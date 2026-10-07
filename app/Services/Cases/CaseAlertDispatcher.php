<?php

namespace App\Services\Cases;

use App\Enums\CaseStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\User;
use App\Notifications\CaseDeadlineAlert;
use App\Services\CaseEventLogger;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

class CaseAlertDispatcher
{
    public function __construct(private Deadlines $deadlines, private CaseEventLogger $events) {}

    public function run(): void
    {
        foreach (Company::query()->where('is_active', true)->cursor() as $company) {
            app(TenantContext::class)->runForCompany($company, function (): void {
                foreach (DisciplinaryCase::query()->whereNotIn('status', [CaseStatus::Draft->value, CaseStatus::ArchivedFinal->value])->cursor() as $case) {
                    foreach ($this->alertsFor($case) as [$type, $stage]) {
                        $this->deliver($case, $type, $stage);
                    }
                }
            });
        }
    }

    public function alertsFor(DisciplinaryCase $case): array
    {
        $now = CarbonImmutable::now('Asia/Baghdad');
        $alerts = [];
        $procedure = $this->deadlines->procedureStatus($case, $now);
        if ($procedure === 'approaching') {
            $alerts[] = ['procedure_approaching', $this->deadlines->procedureDue($case)->toDateString()];
        } elseif ($procedure === 'overdue' && $case->decision_issued_at === null) {
            $alerts[] = ['procedure_overdue', $this->deadlines->procedureDue($case)->toDateString()];
        }
        if ($this->deadlines->responseStatus($case, $now) === 'missed') {
            $alerts[] = ['response_overdue', $this->deadlines->responseDue($case)->toDateString()];
        }
        if ($case->legal_review_requested_at !== null && $case->legal_review_completed_at === null && $case->legal_review_cancelled_at === null) {
            $alerts[] = ['consultation_pending', $now->toDateString()];
        }
        $appealEnd = $this->deadlines->appealEnd($case);
        if ($case->status === CaseStatus::AwaitingAppealWindow && $appealEnd !== null
            && $now->toDateString() <= $appealEnd->toDateString()
            && $now->startOfDay()->gte($appealEnd->subDays((int) config('displanery.deadlines.appeal_ending_days')))) {
            $alerts[] = ['appeal_window_ending', $appealEnd->toDateString()];
        }

        return $alerts;
    }

    public function deliver(DisciplinaryCase $case, string $type, string $stage): void
    {
        $identity = ['case_id' => $case->id, 'alert_type' => $type, 'stage' => $stage];
        $inserted = DB::table('case_alerts')->insertOrIgnore($identity + [
            'company_id' => $case->company_id, 'status' => 'pending', 'attempts' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $claim = DB::table('case_alerts')->where($identity)->where('attempts', '<', 5);
        if ($inserted === 0) {
            $claim->where(fn ($query) => $query->where('status', 'failed')
                ->orWhere(fn ($pending) => $pending->where('status', 'pending')->where('updated_at', '<=', now()->subMinutes(15))));
        } else {
            $claim->where('status', 'pending')->where('attempts', 0);
        }
        if ($claim->update(['status' => 'pending', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]) !== 1) {
            return;
        }

        try {
            $recipients = User::query()->where('company_id', $case->company_id)->where('is_active', true)
                ->where(fn ($query) => $query->where('primary_role', UserRole::CompanyAdmin->value)
                    ->orWhereIn('id', array_filter([$case->assigned_hr_user_id, $case->opened_by_user_id])))->get();
            Notification::send($recipients, new CaseDeadlineAlert($case, $type));
            DB::transaction(function () use ($case, $identity, $type, $stage): void {
                DB::table('case_alerts')->where($identity)->update([
                    'status' => 'sent', 'sent_at' => now(), 'last_error' => null, 'updated_at' => now(),
                ]);
                $this->events->log($case, 'deadline_alert_sent', ['alert_type' => $type, 'stage' => $stage]);
            });
        } catch (Throwable $error) {
            DB::table('case_alerts')->where($identity)->update([
                'status' => 'failed', 'last_error' => mb_substr($error->getMessage(), 0, 2000), 'updated_at' => now(),
            ]);
        }
    }
}

<?php

namespace App\Services\Attendance\FollowUp;

use App\Jobs\Attendance\SendNotificationMessageJob;
use App\Models\Attendance\FollowUpCase;
use App\Models\Attendance\NotificationAttempt;
use App\Models\Attendance\NotificationBatch;
use App\Models\Attendance\NotificationMessage;
use App\Models\Attendance\NotificationMessageCase;
use App\Models\Attendance\NotificationRoute;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\Setting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class NotificationBatches
{
    public function __construct(
        private NotificationSettings $settings,
        private RecipientResolver $recipients,
        private DeliveryEvents $events,
        private AuditLogger $audit,
        private DeliveryFinalizer $finalizer,
    ) {}

    public function build(Company $company, ?User $actor = null, ?string $runKey = null): NotificationBatch
    {
        $this->authorize($company, $actor);
        $runKey ??= 'manual:'.Str::uuid();
        $batch = DB::transaction(function () use ($company, $actor, $runKey): NotificationBatch {
            Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $existing = NotificationBatch::query()->where('run_key', $runKey)->first();
            if ($existing !== null) {
                return $existing;
            }
            $setting = $this->settings->forCompany($company);
            $cutOff = CarbonImmutable::now('Asia/Baghdad');
            $batch = NotificationBatch::create([
                'company_id' => $company->id, 'run_key' => $runKey, 'cut_off' => $cutOff,
                'mode' => $setting->mode, 'status' => 'draft', 'built_by' => $actor?->id,
            ]);
            $restDays = Setting::query()->first()?->leave_calendar_rest_days ?? [5];
            $groups = [];
            FollowUpCase::query()->with('employee')->where('state', '!=', 'closed')
                ->orderBy('id')->chunkById(100, function ($cases) use ($company, $setting, $cutOff, $restDays, &$groups): void {
                    foreach ($cases as $case) {
                        foreach ($case->routes as $route) {
                            if (! ($route['recipient_available'] ?? false)) {
                                continue;
                            }
                            $recipient = $this->recipients->forRoute($case, $route);
                            if ($recipient === null) {
                                continue;
                            }
                            $delivery = NotificationRoute::query()->firstOrCreate([
                                'company_id' => $company->id, 'case_id' => $case->id,
                                'route_key' => $route['key'],
                            ], ['sent_count' => 0]);
                            if (! $this->due($delivery, $cutOff, (int) $setting->reminder_interval_days,
                                (int) $setting->reminder_max, $restDays)) {
                                continue;
                            }
                            $cycle = $delivery->sent_count + 1;
                            if (NotificationMessageCase::query()->where('case_id', $case->id)
                                ->where('route_key', $route['key'])->where('cycle_number', $cycle)->exists()) {
                                continue;
                            }
                            $address = $recipient['address'];
                            $groups[$address][] = [
                                'case' => $case, 'route' => $route, 'recipient' => $recipient,
                                'cycle' => $cycle,
                            ];
                        }
                    }
                });
            foreach ($groups as $address => $entries) {
                $first = $entries[0]['recipient'];
                $message = NotificationMessage::create([
                    'company_id' => $company->id, 'batch_id' => $batch->id,
                    'uuid' => (string) Str::uuid(), 'recipient_kind' => collect($entries)->contains(
                        fn (array $entry): bool => $entry['route']['kind'] === 'manager') ? 'manager' : 'approver',
                    'recipient_employee_id' => $first['recipient_employee_id'],
                    'address' => $address, 'language' => $setting->language,
                    'status' => 'draft',
                ]);
                foreach ($entries as $entry) {
                    $case = $entry['case'];
                    $route = $entry['route'];
                    $snapshot = $this->snapshot($case, $route, $entry['recipient']);
                    NotificationMessageCase::create([
                        'company_id' => $company->id, 'message_id' => $message->id,
                        'case_id' => $case->id, 'route_key' => $route['key'],
                        'cycle_number' => $entry['cycle'],
                        'kind' => $entry['cycle'] === 1 ? 'first' : 'reminder',
                        'snapshot' => $snapshot, 'created_at' => now(),
                    ]);
                    $this->events->record($case, 'reserved', [
                        'route_key' => $route['key'], 'cycle_number' => $entry['cycle'], 'state' => 'due',
                    ], ['route_key' => $route['key'], 'cycle_number' => $entry['cycle'], 'state' => 'reserved'], $actor);
                }
            }
            $this->audit->log('attendance.notification_batch.built', $batch, [
                'message_count' => count($groups), 'run_key' => $runKey, 'mode' => $setting->mode,
            ], $actor);

            return $batch;
        });
        if ($batch->mode === 'auto' && $batch->status === 'draft') {
            $this->queueBatch($batch);
        }

        return $batch->fresh();
    }

    public function send(NotificationBatch $batch, User $actor): void
    {
        $this->authorize(Company::findOrFail($batch->company_id), $actor);
        abort_unless($batch->status === 'draft' && $batch->mode === 'review', 409);
        $this->queueBatch($batch, $actor);
    }

    public function exclude(NotificationMessage $message, User $actor, string $reason): void
    {
        $this->authorize(Company::findOrFail($message->company_id), $actor);
        $this->reason($reason);
        DB::transaction(function () use ($message, $actor, $reason): void {
            $message = NotificationMessage::query()->whereKey($message->id)->lockForUpdate()->firstOrFail();
            abort_unless($message->status === 'draft' && $message->batch->status === 'draft', 409);
            $this->release($message, 'excluded', $actor);
            $message->update(['status' => 'excluded']);
            $this->audit->log('attendance.notification_message.excluded', $message, ['reason' => $reason], $actor);
        });
    }

    public function cancel(NotificationBatch $batch, User $actor, string $reason): void
    {
        $this->authorize(Company::findOrFail($batch->company_id), $actor);
        $this->reason($reason);
        DB::transaction(function () use ($batch, $actor, $reason): void {
            $batch = NotificationBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($batch->status, ['draft', 'sending'], true), 409);
            $messages = $batch->messages()->lockForUpdate()->get();
            abort_if($messages->contains(fn (NotificationMessage $message): bool => in_array($message->status,
                ['sending', 'sent', 'uncertain'], true)), 409);
            foreach ($messages as $message) {
                if (in_array($message->status, ['draft', 'queued', 'failed'], true)) {
                    $this->release($message, 'cancelled', $actor);
                    $message->update(['status' => 'excluded']);
                }
            }
            $batch->update(['status' => 'cancelled']);
            $this->audit->log('attendance.notification_batch.cancelled', $batch, ['reason' => $reason], $actor);
        });
    }

    public function retry(NotificationMessage $message, User $actor): void
    {
        $this->authorize(Company::findOrFail($message->company_id), $actor);
        DB::transaction(function () use ($message, $actor): void {
            $message = NotificationMessage::query()->whereKey($message->id)->lockForUpdate()->firstOrFail();
            abort_unless($message->status === 'failed', 409);
            $message->update(['status' => 'queued']);
            $message->batch()->update(['status' => 'sending']);
            $this->audit->log('attendance.notification_message.retried', $message, [], $actor);
            SendNotificationMessageJob::dispatch($message->id)->afterCommit();
        });
    }

    public function resolveUncertain(NotificationMessage $message, string $action, User $actor): void
    {
        $this->authorize(Company::findOrFail($message->company_id), $actor);
        abort_unless(in_array($action, ['resend', 'mark_sent'], true), 422);
        DB::transaction(function () use ($message, $action, $actor): void {
            $caseIds = NotificationMessageCase::query()->where('message_id', $message->id)->pluck('case_id');
            $employeeIds = FollowUpCase::query()->whereIn('id', $caseIds)->pluck('employee_id')->unique()->sort();
            Employee::query()->whereIn('id', $employeeIds)->orderBy('id')->lockForUpdate()->get();
            $message = NotificationMessage::query()->whereKey($message->id)->lockForUpdate()->firstOrFail();
            abort_unless($message->status === 'uncertain', 409);
            $started = $message->attempts()->where('result', 'started')->latest('id')->firstOrFail();
            NotificationAttempt::create([
                'company_id' => $message->company_id, 'message_id' => $message->id,
                'attempt_number' => $started->attempt_number,
                'result' => $action === 'mark_sent' ? 'sent' : 'failed',
                'address' => $started->address, 'started_at' => $started->started_at,
                'finished_at' => now(), 'error_code' => 'operator_'.$action,
            ]);
            if ($action === 'mark_sent') {
                $this->finalizer->sent($message);
            } else {
                $message->update(['status' => 'queued']);
                SendNotificationMessageJob::dispatch($message->id)->afterCommit();
            }
            $this->audit->log('attendance.notification_message.uncertain_resolved', $message,
                ['action' => $action], $actor);
        });
    }

    public function refreshStatus(NotificationBatch $batch): void
    {
        if ($batch->status === 'cancelled') {
            return;
        }
        $statuses = $batch->messages()->pluck('status');
        if ($statuses->contains(fn (string $status): bool => in_array($status, ['queued', 'sending', 'draft', 'uncertain'], true))) {
            $batch->update(['status' => 'sending']);

            return;
        }
        $batch->update(['status' => $statuses->contains('failed') ? 'partly_failed' : 'sent']);
    }

    private function queueBatch(NotificationBatch $batch, ?User $actor = null): void
    {
        DB::transaction(function () use ($batch, $actor): void {
            $batch = NotificationBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($batch->status !== 'draft') {
                return;
            }
            foreach ($batch->messages()->where('status', 'draft')->get() as $message) {
                $message->update(['status' => 'queued']);
                SendNotificationMessageJob::dispatch($message->id)->afterCommit();
            }
            $batch->update(['status' => $batch->messages()->where('status', 'queued')->exists() ? 'sending' : 'sent']);
            $this->audit->log('attendance.notification_batch.queued', $batch, [], $actor);
        });
    }

    private function release(NotificationMessage $message, string $event, ?User $actor): void
    {
        foreach ($message->cases()->with('followUpCase')->get() as $link) {
            $this->events->record($link->followUpCase, $event, [
                'route_key' => $link->route_key, 'cycle_number' => $link->cycle_number, 'state' => 'reserved',
            ], ['route_key' => $link->route_key, 'cycle_number' => $link->cycle_number, 'state' => 'released'], $actor);
            $link->delete();
        }
    }

    private function snapshot(FollowUpCase $case, array $route, array $recipient): array
    {
        $request = isset($route['request_id']) ? LeaveRequest::query()->find($route['request_id']) : null;

        return [
            'case_id' => $case->id, 'employee_id' => $case->employee_id,
            'employee_name' => $case->employee->name, 'employee_code' => $case->employee->employee_code,
            'work_date' => $case->work_date->toDateString(), 'items' => $route['items'],
            'route' => $route, 'address' => $recipient['address'],
            'recipient_employee_id' => $recipient['recipient_employee_id'],
            'form_number' => $route['external_id'] ?? null,
            'request_from' => $request?->start_date?->toDateString(),
            'request_to' => $request?->end_date?->toDateString(),
        ];
    }

    private function due(NotificationRoute $delivery, CarbonImmutable $cutOff, int $interval, int $maximum, array $restDays): bool
    {
        if ($delivery->sent_count === 0) {
            return true;
        }
        if ($delivery->sent_count > $maximum) {
            return false;
        }
        $date = CarbonImmutable::instance($delivery->last_sent_at)->setTimezone('Asia/Baghdad')->startOfDay();
        $count = 0;
        while ($count < $interval) {
            $date = $date->addDay();
            if (! in_array($date->dayOfWeekIso, $restDays, true)) {
                $count++;
            }
        }

        return $cutOff->toDateString() >= $date->toDateString();
    }

    private function authorize(Company $company, ?User $actor): void
    {
        abort_unless(app(TenantContext::class)->companyId() === $company->id, 403);
        if ($actor === null) {
            abort_unless(app()->runningInConsole() && auth()->guest(), 403);

            return;
        }
        abort_unless(auth()->id() === $actor->id, 403);
        Gate::forUser($actor)->authorize('sendAttendanceNotifications', $company);
    }

    private function reason(string $reason): void
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('messages.follow_up.reason_required')]);
        }
    }
}

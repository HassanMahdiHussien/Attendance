<?php

namespace App\Services\Reports;

use App\Enums\AttachmentKind;
use App\Enums\ReportSource;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Models\DisciplinaryCase;
use App\Models\Employee;
use App\Models\Location;
use App\Models\User;
use App\Models\ViolationReport;
use App\Services\Attachments\AttachmentService;
use App\Services\AuditLogger;
use App\Services\CaseEventLogger;
use App\Services\Cases\CaseDrafts;
use App\Services\Cases\CaseInsights;
use App\Support\Access\ViolationReportVisibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReportService
{
    public function submit(array $data, User $actor): ViolationReport
    {
        abort_unless($actor->is_active && $actor->company?->is_active
            && in_array($actor->primary_role, [UserRole::Reporter, UserRole::DeptManager], true), 403);
        $branchId = (int) $data['branch_id'];
        abort_unless(DB::table('branch_user')->where('company_id', $actor->company_id)->where('branch_id', $branchId)->where('user_id', $actor->id)->exists(), 403);
        if (! empty($data['location_id'])) {
            Location::query()->where('branch_id', $branchId)->findOrFail($data['location_id']);
        }
        foreach (['employee_id', 'alleged_responsible_employee_id'] as $key) {
            if (! empty($data[$key])) {
                Employee::query()->where('company_id', $actor->company_id)->where('branch_id', $branchId)->findOrFail($data[$key]);
            }
        }

        return DB::transaction(function () use ($data, $actor, $branchId): ViolationReport {
            $report = ViolationReport::create(array_intersect_key($data, array_flip([
                'employee_id', 'location_id', 'place_freetext', 'occurred_at', 'violation_type',
                'alleged_responsible_employee_id', 'alleged_responsible_person_freetext', 'description',
            ])) + [
                'company_id' => $actor->company_id, 'branch_id' => $branchId,
                'reporter_user_id' => $actor->id, 'source' => ReportSource::Reporter,
                'status' => ReportStatus::Received,
            ]);
            foreach ($data['witnesses'] ?? [] as $witness) {
                $report->witnesses()->create(['company_id' => $report->company_id, 'name' => $witness['name'], 'employee_id' => $witness['employee_id'] ?? null]);
            }
            foreach ($data['attachments'] ?? [] as $file) {
                app(AttachmentService::class)->store($report, AttachmentKind::ReportEvidence, $file, $actor);
            }
            app(AuditLogger::class)->log('report.submitted', $report, actor: $actor);

            return $report;
        });
    }

    public function dismiss(ViolationReport $report, string $reason, User $actor): void
    {
        $this->authorizeInbox($report, $actor);
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'سبب الاستبعاد مطلوب.']);
        }
        DB::transaction(function () use ($report, $reason, $actor): void {
            $report = ViolationReport::query()->lockForUpdate()->findOrFail($report->id);
            abort_unless(in_array($report->status, [ReportStatus::Received, ReportStatus::UnderReview], true), 409);
            $report->update(['status' => ReportStatus::Dismissed]);
            app(AuditLogger::class)->log('report.dismissed', $report, ['reason' => $reason], $actor);
        });
    }

    public function convert(ViolationReport $report, User $actor, ?DisciplinaryCase $existing = null, array $facts = []): DisciplinaryCase
    {
        $this->authorizeInbox($report, $actor);

        return DB::transaction(function () use ($report, $actor, $existing, $facts): DisciplinaryCase {
            $report = ViolationReport::query()->lockForUpdate()->findOrFail($report->id);
            abort_unless(in_array($report->status, [ReportStatus::Received, ReportStatus::UnderReview], true), 409);
            if ($existing !== null) {
                Gate::forUser($actor)->authorize('update', $existing);
                abort_unless($existing->company_id === $report->company_id, 404);
                abort_unless(app(CaseInsights::class)->duplicateCandidates($report)['cases']->contains('id', $existing->id), 422);
                $case = $existing;
                app(CaseEventLogger::class)->log($case, 'duplicate_report_linked', ['report_id' => $report->id], actor: $actor);
            } else {
                $data = [
                    'branch_id' => $report->branch_id, 'employee_id' => $report->employee_id,
                    'location_id' => $report->location_id, 'violation_place_freetext' => $report->place_freetext,
                    'violation_time' => $report->occurred_at, 'violation_type' => $report->violation_type,
                    'description' => $report->description,
                    'alleged_responsible_employee_id' => $report->alleged_responsible_employee_id,
                    'alleged_responsible_person_freetext' => $report->alleged_responsible_person_freetext,
                    'witnesses' => $report->witnesses->map(fn ($w) => ['name' => $w->name, 'employee_id' => $w->employee_id])->all(),
                ];
                $case = app(CaseDrafts::class)->create(array_replace($data, $facts), $actor, $report);
            }
            $report->update(['status' => ReportStatus::ConvertedToCase, 'case_id' => $case->id]);
            app(AuditLogger::class)->log($existing ? 'report.duplicate_linked' : 'report.converted', $report, ['case_id' => $case->id], $actor);

            return $case;
        });
    }

    private function authorizeInbox(ViolationReport $report, User $actor): void
    {
        abort_unless(ViolationReportVisibility::inbox($actor)->whereKey($report->id)->exists(), 403);
    }
}

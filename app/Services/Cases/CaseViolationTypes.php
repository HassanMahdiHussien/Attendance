<?php

namespace App\Services\Cases;

use App\Enums\UserRole;
use App\Models\DisciplinaryCase;
use App\Models\User;
use App\Models\ViolationReport;
use App\Models\ViolationType;
use App\Services\AuditLogger;
use App\Services\CaseEventLogger;
use App\Support\Access\CaseVisibility;
use App\Support\Access\ViolationReportVisibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CaseViolationTypes
{
    public function selected(?int $id, ?string $text, int $companyId): array
    {
        if ($id !== null) {
            $type = ViolationType::query()->where('company_id', $companyId)->where('is_active', true)->findOrFail($id);

            return ['violation_type_id' => $type->id, 'violation_type' => $type->name('ar')];
        }
        if (blank($text)) {
            throw ValidationException::withMessages(['violationType' => __('Choose a violation type or describe another')]);
        }

        return ['violation_type_id' => null, 'violation_type' => trim($text)];
    }

    public function mapCase(DisciplinaryCase $case, int $typeId, User $actor): void
    {
        abort_unless($this->canMap($actor, $case->company_id)
            && CaseVisibility::for($actor)->whereKey($case->id)->exists(), 403);
        DB::transaction(function () use ($case, $typeId, $actor): void {
            $case = DisciplinaryCase::query()->lockForUpdate()->findOrFail($case->id);
            abort_unless($case->violation_type_id === null, 409);
            $type = ViolationType::query()->where('company_id', $case->company_id)->where('is_active', true)->findOrFail($typeId);
            $old = $case->violation_type;
            $case->update(['violation_type_id' => $type->id]);
            app(CaseEventLogger::class)->log($case, 'violation_type_mapped',
                ['old_text' => $old, 'violation_type_id' => $type->id, 'type_name_ar' => $type->name('ar')], actor: $actor);
        });
    }

    public function mapReport(ViolationReport $report, int $typeId, User $actor): void
    {
        abort_unless($this->canMap($actor, $report->company_id)
            && ViolationReportVisibility::inbox($actor)->whereKey($report->id)->exists(), 403);
        DB::transaction(function () use ($report, $typeId, $actor): void {
            $report = ViolationReport::query()->lockForUpdate()->findOrFail($report->id);
            abort_unless($report->violation_type_id === null, 409);
            $type = ViolationType::query()->where('company_id', $report->company_id)->where('is_active', true)->findOrFail($typeId);
            $old = $report->violation_type;
            $report->update(['violation_type_id' => $type->id]);
            app(AuditLogger::class)->log('report.violation_type_mapped', $report,
                ['old_text' => $old, 'violation_type_id' => $type->id, 'type_name_ar' => $type->name('ar')], $actor);
        });
    }

    private function canMap(User $actor, int $companyId): bool
    {
        return $actor->is_active && $actor->company?->is_active && (int) $actor->company_id === $companyId
            && in_array($actor->primary_role, [UserRole::CompanyAdmin, UserRole::HrStaff], true);
    }
}

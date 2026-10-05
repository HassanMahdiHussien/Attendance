<?php

namespace App\Services\Cases;

use App\Enums\UserRole;
use App\Models\DisciplinaryCase;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CaseEventLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CaseAssignment
{
    public function assign(DisciplinaryCase $case, ?User $assignee, User $actor): DisciplinaryCase
    {
        if ($assignee !== null && ($assignee->company_id !== $case->company_id || ! $assignee->is_active
            || ! in_array($assignee->primary_role, [UserRole::HrStaff, UserRole::CompanyAdmin], true))) {
            throw ValidationException::withMessages(['assigned_hr_user_id' => 'اختر موظف موارد بشرية نشطاً من الشركة.']);
        }

        return DB::transaction(function () use ($case, $assignee, $actor): DisciplinaryCase {
            $case = DisciplinaryCase::query()->lockForUpdate()->findOrFail($case->id);
            Gate::forUser($actor)->authorize('assign', $case);
            abort_unless($actor->primary_role === UserRole::CompanyAdmin || $case->assigned_hr_user_id === $actor->id || $case->opened_by_user_id === $actor->id, 403);
            $previous = $case->assigned_hr_user_id;
            $case->assigned_hr_user_id = $assignee?->id;
            $case->save();
            app(CaseEventLogger::class)->log($case, 'case_assigned', ['previous_user_id' => $previous, 'user_id' => $assignee?->id], actor: $actor);
            app(AuditLogger::class)->log('case.assigned', $case, ['previous_user_id' => $previous, 'user_id' => $assignee?->id], $actor);

            return $case;
        });
    }
}

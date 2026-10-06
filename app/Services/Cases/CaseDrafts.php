<?php

namespace App\Services\Cases;

use App\Enums\CaseStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\DisciplinaryCase;
use App\Models\Employee;
use App\Models\Location;
use App\Models\User;
use App\Models\ViolationReport;
use App\Services\CaseEventLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CaseDrafts
{
    public function create(array $data, User $actor, ?ViolationReport $report = null): DisciplinaryCase
    {
        Gate::forUser($actor)->authorize('create', DisciplinaryCase::class);

        return DB::transaction(function () use ($data, $actor, $report): DisciplinaryCase {
            if ($report !== null && ((int) $report->company_id !== (int) $actor->company_id || $report->case_id !== null)) {
                abort(403);
            }
            $this->checkFacts($data, $actor);
            $branch = Branch::query()->findOrFail($data['branch_id']);
            $case = DisciplinaryCase::create($this->facts($data) + [
                'company_id' => $actor->company_id, 'opened_by_user_id' => $actor->id,
                'assigned_hr_user_id' => $data['assigned_hr_user_id'] ?? $actor->id,
                'jurisdiction_at_opening' => $branch->jurisdiction,
                'violation_report_id' => $report?->id,
            ]);
            foreach ($data['witnesses'] ?? [] as $witness) {
                $case->witnesses()->create(['company_id' => $case->company_id, 'name' => $witness['name'], 'employee_id' => $witness['employee_id'] ?? null]);
            }
            app(CaseEventLogger::class)->log($case, 'case_draft_created', actor: $actor);

            return $case;
        });
    }

    public function update(DisciplinaryCase $case, array $data, User $actor): DisciplinaryCase
    {
        Gate::forUser($actor)->authorize('update', $case);
        if ($case->status !== CaseStatus::Draft) {
            throw ValidationException::withMessages(['case' => 'لا يمكن تعديل الحالة بعد فتحها.']);
        }
        if ((int) $case->branch_id !== (int) $data['branch_id']) {
            throw ValidationException::withMessages(['branch_id' => 'لا يمكن تغيير فرع المسودة. افتح مسودة جديدة.']);
        }

        return DB::transaction(function () use ($case, $data, $actor): DisciplinaryCase {
            $this->checkFacts($data, $actor);
            $case->update($this->facts($data));
            $case->witnesses()->delete();
            foreach ($data['witnesses'] ?? [] as $witness) {
                $case->witnesses()->create(['company_id' => $case->company_id, 'name' => $witness['name'], 'employee_id' => $witness['employee_id'] ?? null]);
            }
            app(CaseEventLogger::class)->log($case, 'case_draft_updated', actor: $actor);

            return $case;
        });
    }

    private function checkFacts(array $data, User $actor): void
    {
        $branch = Branch::query()->where('company_id', $actor->company_id)->findOrFail($data['branch_id']);
        Employee::query()->where('company_id', $actor->company_id)->where('branch_id', $branch->id)->findOrFail($data['employee_id']);
        if (! empty($data['alleged_responsible_employee_id'])) {
            Employee::query()->where('company_id', $actor->company_id)->findOrFail($data['alleged_responsible_employee_id']);
        }
        if (! empty($data['location_id'])) {
            Location::query()->where('company_id', $actor->company_id)->where('branch_id', $branch->id)->findOrFail($data['location_id']);
        }
        if (! empty($data['assigned_hr_user_id'])) {
            $assignee = User::query()->where('company_id', $actor->company_id)->where('is_active', true)->findOrFail($data['assigned_hr_user_id']);
            if (! in_array($assignee->primary_role, [UserRole::HrStaff, UserRole::CompanyAdmin], true)) {
                throw ValidationException::withMessages(['assigned_hr_user_id' => 'يجب اختيار موظف موارد بشرية نشط.']);
            }
        }
    }

    private function facts(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'branch_id', 'employee_id', 'location_id', 'violation_place_freetext', 'violation_time',
            'violation_type', 'description', 'alleged_responsible_employee_id',
            'alleged_responsible_person_freetext', 'include_prior_record', 'is_sensitive',
        ]));
    }
}

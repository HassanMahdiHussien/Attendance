<?php

namespace App\Services\Employees;

use App\Enums\CaseStatus;
use App\Enums\Outcome;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;
use App\Policies\EmployeeProfilePolicy;
use App\Support\Access\CaseVisibility;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class EmployeeProfile
{
    public function __construct(private EmployeeProfilePolicy $policy) {}

    public function search(User $viewer, string $term = ''): Builder
    {
        abort_unless($this->policy->search($viewer), 403);
        $term = trim($term);

        return $this->policy->scope($viewer)->leftJoin('branches as profile_branch', fn ($join) => $join
            ->on('profile_branch.id', '=', 'employees.branch_id')->on('profile_branch.company_id', '=', 'employees.company_id'))
            ->select('employees.*', 'profile_branch.name as branch_name')
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->where('employees.name', 'like', '%'.$term.'%')->orWhere('employees.employee_code', 'like', '%'.$term.'%')
                ->orWhere('employees.department', 'like', '%'.$term.'%')))->orderBy('employees.name');
    }

    public function build(User $viewer, Employee $employee): array
    {
        return app(TenantContext::class)->runForCompany($employee->company_id,
            fn () => $this->buildInCompany($viewer, $employee));
    }

    private function buildInCompany(User $viewer, Employee $employee): array
    {
        abort_unless($this->policy->view($viewer, $employee), 404);
        $cases = CaseVisibility::for($viewer)->where('cases.company_id', $employee->company_id)
            ->where('cases.employee_id', $employee->id)->where('cases.status', '!=', CaseStatus::Draft->value)
            ->with(['violationType', 'location', 'documents' => fn ($query) => $query->where('is_locked', true)->where('pdf_status', 'ready')])
            ->orderByDesc('cases.violation_time')->get();
        $open = $cases->first(fn ($case) => $case->status !== CaseStatus::ArchivedFinal);
        $history = $cases->filter(fn ($case) => $open === null || $case->id !== $open->id)->values();
        foreach ($cases as $case) {
            $case->setRelation('documents', $case->documents->sortByDesc('version')->values());
        }

        return [
            'employee' => $employee->loadMissing(['branch', 'company']),
            'open' => $open,
            'history' => $history,
            'cases' => $cases,
            'counts' => [
                'total' => $cases->count(),
                'warnings' => $cases->where('outcome', Outcome::Warning)->count(),
                'last_year' => $cases->filter(fn ($case) => $case->violation_time &&
                    CarbonImmutable::parse($case->violation_time)->gte(now('Asia/Baghdad')->subYear()))->count(),
            ],
            'partial' => $viewer->primary_role === UserRole::HrStaff && ! $viewer->can('view_all_company_cases'),
            'sections' => ['disciplinary_history'],
        ];
    }
}

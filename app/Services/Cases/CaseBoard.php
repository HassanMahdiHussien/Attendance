<?php

namespace App\Services\Cases;

use App\Enums\CaseStatus;
use App\Models\Employee;
use App\Models\User;
use App\Support\Access\CaseVisibility;
use App\Support\Format\StageIndicator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CaseBoard
{
    public function mine(User $user): Collection
    {
        $cases = CaseVisibility::for($user)->latest()->get();
        $employees = Employee::withoutGlobalScopes()->whereIn('id', $cases->pluck('employee_id'))
            ->get()->keyBy('id');
        foreach ($cases as $case) {
            $case->setRelation('employee', $employees->get($case->employee_id));
        }

        return $cases;
    }

    public function page(User $user, string $search = '', string $branch = '', string $stage = ''): LengthAwarePaginator
    {
        $query = $this->query($user, $search, $branch, $stage)->with(['employee', 'branch']);

        return $query->orderByRaw('CASE WHEN status = ? THEN 2 WHEN defense_letter_issued_at IS NOT NULL AND decision_issued_at IS NULL THEN 0 ELSE 1 END', [CaseStatus::ArchivedFinal->value])
            ->orderBy('defense_letter_issued_at')
            ->orderByDesc('created_at')->paginate(25);
    }

    public function urgentCount(User $user, string $search = '', string $branch = '', string $stage = ''): int
    {
        $today = CarbonImmutable::now('Asia/Baghdad')->startOfDay();

        return $this->query($user, $search, $branch, $stage)
            ->whereNotNull('defense_letter_issued_at')->whereNull('decision_issued_at')
            ->where('status', '!=', CaseStatus::ArchivedFinal->value)
            ->whereDate('defense_letter_issued_at', '<=', $today->subDays((int) config('displanery.deadlines.procedure_days') - 2)->toDateString())->count();
    }

    private function query(User $user, string $search, string $branch, string $stage): Builder
    {
        return CaseVisibility::for($user)
            ->when($branch !== '', fn ($q) => $q->where('branch_id', $branch))
            ->when($stage !== '', fn ($q) => $q->whereIn('status', StageIndicator::statusesFor((int) $stage)))
            ->when($search !== '', fn ($q) => $q->where(fn ($filter) => $filter
                ->where('case_number', 'like', '%'.$search.'%')
                ->orWhereHas('employee', fn ($employee) => $employee->where('name', 'like', '%'.$search.'%'))));
    }
}

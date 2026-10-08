<?php

namespace App\Services\Cases;

use App\Enums\CaseStatus;
use App\Enums\UserRole;
use App\Exceptions\TransitionBlocked;
use App\Models\DisciplinaryCase;
use App\Models\Law;
use App\Models\User;
use App\Services\CaseEventLogger;
use App\Support\Access\CaseVisibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CaseOpening
{
    public function __construct(private CaseWorkflow $workflow, private CaseInsights $insights,
        private ConflictClearance $conflicts, private CaseEventLogger $events) {}

    public function open(DisciplinaryCase $draft, User $actor): DisciplinaryCase
    {
        return DB::transaction(function () use ($draft, $actor): DisciplinaryCase {
            $case = DisciplinaryCase::query()->lockForUpdate()->findOrFail($draft->id);
            Gate::forUser($actor)->authorize('update', $case);
            if ($case->status !== CaseStatus::Draft || ! in_array($actor->primary_role, [UserRole::CompanyAdmin, UserRole::HrStaff], true)
                || ! CaseVisibility::for($actor)->whereKey($case->id)->exists()) {
                throw new TransitionBlocked('يلزم امتلاك صلاحية الموارد البشرية على مسودة الحالة.');
            }
            if ($case->employee_id === null || $case->branch_id === null || blank($case->violation_type)
                || $case->violation_time === null || ($case->location_id === null && blank($case->violation_place_freetext))) {
                throw new TransitionBlocked('يلزم تحديد الموظف والفرع والنوع والوقت والمكان.');
            }
            $case->jurisdiction_at_opening = $case->branch->jurisdiction;
            $law = Law::query()->where('category', 'labor')->where('kind', 'statute')->where('approval_status', 'approved')
                ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', now('Asia/Baghdad')->toDateString()))
                ->where(fn ($query) => $query->whereNull('repealed_at')->orWhereDate('repealed_at', '>', now('Asia/Baghdad')->toDateString()))
                ->whereHas('jurisdictions', fn ($query) => $query->where('jurisdiction', $case->jurisdiction_at_opening->value))
                ->orderByDesc('effective_from')->first();
            if ($law === null) {
                throw new TransitionBlocked('لا يوجد قانون عمل معتمد ونافذ لهذه المنطقة.');
            }
            $case->law_version_id = $law->id;
            $case->save();
            $opened = $this->workflow->transition($case, CaseStatus::Open, $actor, 'HR opened case');
            $this->insights->linkPriors($opened);
            $conflicts = $this->conflicts->conflicts($opened);
            $this->events->log($opened, 'case_opened', [
                'law_version_id' => $law->id, 'prior_count' => $this->insights->priorBanner($opened)['count'],
                'conflict_member_ids' => array_map(fn (array $row) => $row['member']->id, $conflicts),
            ], actor: $actor);

            return $opened;
        });
    }
}

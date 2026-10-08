<?php

namespace App\Services\Cases;

use App\Enums\CaseStatus;
use App\Enums\UserRole;
use App\Exceptions\TransitionBlocked;
use App\Models\AppealTracking;
use App\Models\DisciplinaryCase;
use App\Models\User;
use App\Services\CaseEventLogger;
use App\Support\Access\CaseVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AppealService
{
    public function __construct(private CaseWorkflow $workflow, private CaseEventLogger $events) {}

    public function logFiled(DisciplinaryCase $case, User $actor, string $filedAt): AppealTracking
    {
        return DB::transaction(function () use ($case, $actor, $filedAt): AppealTracking {
            $case = DisciplinaryCase::query()->lockForUpdate()->findOrFail($case->id);
            $this->checkHr($case, $actor);
            if ($case->status !== CaseStatus::AwaitingAppealWindow) {
                throw new TransitionBlocked(__('messages.appeal.not_awaiting'));
            }
            $tracking = AppealTracking::query()->where('case_id', $case->id)->lockForUpdate()->firstOrFail();
            $date = CarbonImmutable::parse($filedAt, 'Asia/Baghdad')->toDateString();
            if ($date < $tracking->window_start || $date > $tracking->window_end) {
                throw new TransitionBlocked(__('messages.appeal.outside_window'));
            }
            $tracking->forceFill(['appeal_filed' => true, 'appeal_filed_at' => $date, 'logged_by' => $actor->id])->save();
            $this->events->log($case, 'appeal_filed', ['filed_at' => $date], actor: $actor);
            $this->workflow->transition($case, CaseStatus::AppealPending, $actor, 'Appeal filed');

            return $tracking;
        });
    }

    public function logOutcome(DisciplinaryCase $case, User $actor, string $outcome): AppealTracking
    {
        return DB::transaction(function () use ($case, $actor, $outcome): AppealTracking {
            $case = DisciplinaryCase::query()->lockForUpdate()->findOrFail($case->id);
            $this->checkHr($case, $actor);
            if ($case->status !== CaseStatus::AppealPending || blank($outcome)) {
                throw new TransitionBlocked(__('messages.appeal.pending_with_outcome_required'));
            }
            $tracking = AppealTracking::query()->where('case_id', $case->id)->lockForUpdate()->firstOrFail();
            $tracking->forceFill(['appeal_outcome' => $outcome, 'logged_by' => $actor->id])->save();
            $this->events->log($case, 'appeal_outcome_recorded', ['outcome' => $outcome], actor: $actor);
            $case = $this->workflow->transition($case, CaseStatus::AppealResolved, $actor, 'Appeal outcome recorded');
            $this->workflow->transition($case, CaseStatus::ArchivedFinal, $actor, 'Appeal resolved');

            return $tracking;
        });
    }

    private function checkHr(DisciplinaryCase $case, User $actor): void
    {
        if (! in_array($actor->primary_role, [UserRole::CompanyAdmin, UserRole::HrStaff], true)
            || ! CaseVisibility::for($actor)->whereKey($case->id)->exists()) {
            throw new TransitionBlocked(__('messages.appeal.hr_permission_required'));
        }
    }
}

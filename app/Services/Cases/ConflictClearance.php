<?php

namespace App\Services\Cases;

use App\Enums\CaseStatus;
use App\Enums\UserRole;
use App\Models\CaseConflictClearance;
use App\Models\CommitteeMandate;
use App\Models\CommitteePoolMember;
use App\Models\DisciplinaryCase;
use App\Models\User;
use App\Services\CaseEventLogger;
use App\Support\Access\CaseVisibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConflictClearance
{
    public function __construct(private CaseEventLogger $events) {}

    public function conflicts(DisciplinaryCase $case): array
    {
        $date = $case->defense_letter_issued_at ?? now();
        $mandate = CommitteeMandate::query()->where('company_id', $case->company_id)
            ->inEffectOn($date)->orderByDesc('effective_from')->first();
        if ($mandate === null) {
            return [];
        }
        $partyIds = array_filter(array_merge(
            [$case->employee_id, $case->alleged_responsible_employee_id],
            $case->witnesses()->pluck('employee_id')->all(),
        ));
        $names = array_filter(array_map($this->normalize(...), array_merge(
            [$case->employee?->name, $case->allegedResponsibleEmployee?->name, $case->alleged_responsible_person_freetext],
            $case->witnesses()->pluck('name')->all(),
        )));
        $results = [];
        foreach ($mandate->poolMembers()->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $case->branch_id))->get() as $member) {
            $hard = $member->employee_id !== null && in_array($member->employee_id, $partyIds);
            $name = $member->employee_id === null && in_array($this->normalize($member->person_name), $names, true);
            if ($hard || $name) {
                $cleared = $name && DB::table('case_conflict_clearances')->where('case_id', $case->id)
                    ->where('committee_pool_member_id', $member->id)->exists();
                $results[] = ['member' => $member, 'type' => $hard ? 'employee_linked' : 'name_only', 'excluded' => $hard || ! $cleared];
            }
        }

        return $results;
    }

    public function clear(DisciplinaryCase $case, CommitteePoolMember $member, string $reason, User $hrUser): void
    {
        if ($case->status === CaseStatus::ArchivedFinal || blank($reason)
            || ! in_array($hrUser->primary_role, [UserRole::CompanyAdmin, UserRole::HrStaff], true)
            || ! CaseVisibility::for($hrUser)->whereKey($case->id)->exists()) {
            throw ValidationException::withMessages(['reason' => __('messages.case.hr_identity_reason_required')]);
        }
        $conflict = collect($this->conflicts($case))->first(fn (array $row) => $row['member']->id === $member->id);
        if ($conflict === null || $conflict['type'] !== 'name_only') {
            throw ValidationException::withMessages(['member' => __('messages.case.only_name_conflict_clearable')]);
        }
        DB::transaction(function () use ($case, $member, $reason, $hrUser): void {
            $clearance = CaseConflictClearance::firstOrCreate([
                'company_id' => $case->company_id, 'case_id' => $case->id,
                'committee_pool_member_id' => $member->id,
            ], ['reason' => $reason,
                'cleared_by' => $hrUser->id, 'created_at' => now(),
            ]);
            if ($clearance->wasRecentlyCreated) {
                $this->events->log($case, 'conflict_cleared', ['pool_member_id' => $member->id, 'reason' => $reason], actor: $hrUser);
            }
        });
    }

    private function normalize(?string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name ?? '')));
    }
}

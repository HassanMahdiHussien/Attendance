<?php

namespace App\Services\Cases;

use App\Enums\CaseStatus;
use App\Enums\DefenseAcceptance;
use App\Enums\Outcome;
use App\Enums\PoolRole;
use App\Enums\UserRole;
use App\Enums\Vote;
use App\Exceptions\TransitionBlocked;
use App\Models\CommitteeMandate;
use App\Models\CommitteeSessionAttendance;
use App\Models\DisciplinaryCase;
use App\Models\User;
use App\Services\CaseEventLogger;
use App\Support\Access\CaseVisibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CommitteeSessionService
{
    public function __construct(private CaseWorkflow $workflow, private ConflictClearance $conflicts, private CaseEventLogger $events) {}

    public function recordSession(DisciplinaryCase $case, array $attendees, ?int $chairId, string $minutes,
        Outcome $outcome, DefenseAcceptance $defenseAcceptance, User $actor, int $minutesTypedByPoolMemberId): DisciplinaryCase
    {
        return DB::transaction(function () use ($case, $attendees, $chairId, $minutes, $outcome, $defenseAcceptance, $actor, $minutesTypedByPoolMemberId): DisciplinaryCase {
            $case = DisciplinaryCase::query()->lockForUpdate()->findOrFail($case->id);
            Gate::forUser($actor)->authorize('update', $case);
            $sources = [CaseStatus::AwaitingEmployeeResponse, CaseStatus::ResponseReceived,
                CaseStatus::ResponseRefusedToSign, CaseStatus::ResponseDeliveryRefused,
                CaseStatus::ResponseScannedUploaded, CaseStatus::AiIngestedResponse, CaseStatus::CommitteeSessionHeld];
            if (! in_array($case->status, $sources, true) || ! in_array($actor->primary_role, [UserRole::CompanyAdmin, UserRole::HrStaff], true)
                || ! CaseVisibility::for($actor)->whereKey($case->id)->exists()) {
                throw new TransitionBlocked(__('messages.session.hr_role_required'));
            }
            if ($case->defense_letter_issued_at === null || blank($minutes)) {
                throw new TransitionBlocked(__('messages.session.defense_date_and_minutes_required'));
            }
            $mandate = CommitteeMandate::query()->where('company_id', $case->company_id)
                ->inEffectOn($case->defense_letter_issued_at)->orderByDesc('effective_from')->first();
            if ($mandate === null) {
                throw new TransitionBlocked(__('messages.session.mandate_not_effective'));
            }
            $pool = $mandate->poolMembers()->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $case->branch_id))
                ->get()->keyBy('id');
            $ids = array_column($attendees, 'pool_member_id');
            if (count($ids) < 3 || count(array_unique($ids)) !== count($ids)) {
                throw new TransitionBlocked(__('messages.session.three_distinct_members_required'), 1001);
            }
            $excluded = collect($this->conflicts->conflicts($case))->filter(fn ($row) => $row['excluded'])
                ->map(fn ($row) => $row['member']->id)->all();
            $union = false;
            $legalId = null;
            $approvals = 0;
            foreach ($attendees as $attendee) {
                $member = $pool->get($attendee['pool_member_id'] ?? null);
                if ($member === null || in_array($member->id, $excluded, true)) {
                    throw new TransitionBlocked(__('messages.session.ineligible_attendee'));
                }
                $vote = $attendee['vote'] instanceof Vote ? $attendee['vote'] : Vote::tryFrom($attendee['vote'] ?? '');
                if ($vote === null) {
                    throw new TransitionBlocked(__('messages.session.vote_required'));
                }
                $union = $union || $member->role_type === PoolRole::LaborUnion;
                if ($legalId === null && $member->role_type === PoolRole::Legal) {
                    $legalId = $member->id;
                }
                $approvals += (int) ($vote === Vote::Approve);
            }
            if (! $union) {
                throw new TransitionBlocked(__('messages.session.union_representative_required'), 1002);
            }
            $chairId ??= $legalId;
            if ($chairId === null || ! in_array($chairId, $ids, true)) {
                throw new TransitionBlocked(__('messages.session.chair_must_attend'));
            }
            if (! in_array($minutesTypedByPoolMemberId, $ids, true)) {
                throw new TransitionBlocked(__('messages.session.minutes_author_must_attend'));
            }
            CommitteeSessionAttendance::query()->where('case_id', $case->id)->delete();
            foreach ($attendees as $attendee) {
                CommitteeSessionAttendance::create([
                    'company_id' => $case->company_id, 'case_id' => $case->id,
                    'committee_pool_member_id' => $attendee['pool_member_id'],
                    'is_chair' => $attendee['pool_member_id'] === $chairId,
                    'vote' => $attendee['vote'] instanceof Vote ? $attendee['vote'] : Vote::from($attendee['vote']),
                    'attended_at' => now(),
                ]);
            }
            $case->forceFill([
                'committee_mandate_id' => $mandate->id, 'committee_session_notes' => $minutes,
                'committee_session_held_at' => now(), 'outcome' => $outcome,
                'defense_acceptance' => $defenseAcceptance,
                'session_majority_reached' => $approvals > count($attendees) / 2,
                'minutes_typed_by_pool_member_id' => $minutesTypedByPoolMemberId,
            ])->save();
            if ($case->ai_request_document_type === 'response_transcription' && $case->ai_request_id !== null) {
                $this->events->log($case, 'ai_request_superseded', ['operation' => 'response_transcription'], actor: $actor);
                $case->forceFill(['ai_request_id' => null, 'ai_request_document_type' => null,
                    'ai_request_from_status' => null, 'ai_request_attachment_id' => null, 'ai_request_started_at' => null])->save();
            }
            if ($case->status !== CaseStatus::CommitteeSessionHeld) {
                $case = $this->workflow->transition($case, CaseStatus::CommitteeSessionHeld, $actor, 'Committee session recorded');
            }
            $this->events->log($case, 'committee_session_recorded', [
                'attendee_ids' => $ids, 'chair_id' => $chairId, 'approve_votes' => $approvals,
                'majority_reached' => $case->session_majority_reached,
                'minutes_typed_by_pool_member_id' => $minutesTypedByPoolMemberId,
            ], actor: $actor);

            return $case;
        });
    }
}

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
use App\Models\Incident;
use App\Models\User;
use App\Services\CaseEventLogger;
use App\Support\Access\CaseVisibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CommitteeSessionService
{
    public function __construct(private CaseWorkflow $workflow, private ConflictClearance $conflicts, private CaseEventLogger $events) {}

    public function recordForIncident(Incident $incident, array $attendeeIds, ?int $chairId, string $sharedMinutes,
        array $perCase, User $actor, int $minutesTypedByPoolMemberId): array
    {
        if (blank($sharedMinutes) || $perCase === []) {
            throw new TransitionBlocked(__('messages.incident.session_details_required'));
        }

        return DB::transaction(function () use ($incident, $attendeeIds, $chairId, $sharedMinutes, $perCase, $actor, $minutesTypedByPoolMemberId): array {
            $incident = Incident::query()->lockForUpdate()->findOrFail($incident->id);
            abort_unless(app(IncidentService::class)->canManageAll($incident, $actor), 403);
            $members = $incident->cases()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $readyIds = $members->filter(fn (DisciplinaryCase $case) => $this->sessionReady($case))->keys()->map(fn ($id) => (int) $id)->sort()->values()->all();
            $selectedIds = array_map('intval', array_keys($perCase));
            sort($selectedIds);
            if ($readyIds !== $selectedIds) {
                throw new TransitionBlocked(__('messages.incident.all_ready_cases_required'));
            }
            $sessionId = DB::table('incident_sessions')->insertGetId([
                'company_id' => $incident->company_id, 'incident_id' => $incident->id,
                'recorded_by_user_id' => $actor->id, 'shared_minutes' => $sharedMinutes, 'created_at' => now(),
            ]);
            $recorded = [];
            foreach ($perCase as $caseId => $decision) {
                $case = $members->get((int) $caseId);
                if ($case === null || ! $this->sessionReady($case)) {
                    throw new TransitionBlocked(__('messages.incident.case_not_ready'));
                }
                if (blank($decision['minutes'] ?? null)) {
                    throw new TransitionBlocked(__('messages.incident.case_minutes_required'));
                }
                if (app(IncidentPrivacy::class)->containsOtherMemberName($case, $decision['minutes'])) {
                    throw new TransitionBlocked(__('messages.incident.other_member_in_text'));
                }
                $attendees = array_map(fn (int $id): array => [
                    'pool_member_id' => $id, 'vote' => $decision['votes'][$id] ?? '',
                ], $attendeeIds);
                $updated = $this->recordSession($case, $attendees, $chairId, $decision['minutes'],
                    Outcome::from($decision['outcome']), DefenseAcceptance::from($decision['defense_acceptance']),
                    $actor, $minutesTypedByPoolMemberId);
                $this->events->log($updated, 'incident_session_recorded', [
                    'incident_id' => $incident->id, 'incident_session_id' => $sessionId,
                ], actor: $actor);
                $recorded[] = $updated->id;
            }

            $skipped = $members->reject(fn (DisciplinaryCase $case) => in_array($case->id, $recorded, true))
                ->map(fn (DisciplinaryCase $case): array => [
                    'case_id' => $case->id,
                    'reason' => $case->status === CaseStatus::CommitteeSessionHeld && $case->session_majority_reached
                        ? 'already_recorded' : 'not_ready',
                ])->values()->all();

            return ['recorded' => $recorded, 'skipped' => $skipped];
        });
    }

    public function sessionReady(DisciplinaryCase $case): bool
    {
        if ($case->status === CaseStatus::CommitteeSessionHeld && $case->session_majority_reached) {
            return false;
        }
        if ($case->defense_letter_issued_at === null || ! in_array($case->status, [
            CaseStatus::AwaitingEmployeeResponse, CaseStatus::ResponseReceived,
            CaseStatus::ResponseRefusedToSign, CaseStatus::ResponseDeliveryRefused,
            CaseStatus::ResponseScannedUploaded, CaseStatus::AiIngestedResponse,
            CaseStatus::CommitteeSessionHeld,
        ], true)) {
            return false;
        }

        return $case->status !== CaseStatus::AwaitingEmployeeResponse
            || (($due = app(Deadlines::class)->responseDue($case)) !== null
                && now('Asia/Baghdad')->startOfDay()->gt($due));
    }

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

<?php

namespace App\Livewire\Cases;

use App\Enums\CaseStatus;
use App\Enums\DefenseAcceptance;
use App\Enums\Outcome;
use App\Exceptions\TransitionBlocked;
use App\Models\CommitteeMandate;
use App\Models\CommitteeSessionAttendance;
use App\Models\Incident;
use App\Services\Cases\CommitteeSessionService;
use App\Services\Cases\ConflictClearance;
use App\Services\Cases\IncidentService;
use App\Support\Access\CaseVisibility;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class RecordSession extends Component
{
    public int $caseId;

    public string $expectedStatus = '';

    public array $selected = [];

    public array $votes = [];

    public string $chairId = '';

    public string $minutesTypedBy = '';

    public string $minutes = '';

    public string $outcome = 'recommendation_only';

    public string $defenseAcceptance = 'rejected';

    public string $notice = '';

    public ?int $incidentId = null;

    public array $includeCases = [];

    public array $caseMinutes = [];

    public array $caseOutcomes = [];

    public array $caseDefenseAcceptance = [];

    public array $caseVotes = [];

    public function mount(string $case): void
    {
        $record = CaseVisibility::for(auth()->user())->findOrFail($case);
        Gate::authorize('update', $record);
        abort_unless(in_array($record->status, [CaseStatus::AwaitingEmployeeResponse, CaseStatus::ResponseReceived,
            CaseStatus::ResponseRefusedToSign, CaseStatus::ResponseDeliveryRefused, CaseStatus::ResponseScannedUploaded,
            CaseStatus::AiIngestedResponse, CaseStatus::CommitteeSessionHeld], true), 404);
        $this->caseId = $record->id;
        $this->incidentId = request()->boolean('single') ? null : $record->incident_id;
        $this->expectedStatus = $record->status->value;
        $this->minutes = $record->committee_session_notes ?? '';
        $this->outcome = $record->outcome?->value ?? 'recommendation_only';
        $this->defenseAcceptance = $record->defense_acceptance?->value ?? 'rejected';
        $this->minutesTypedBy = (string) $record->minutes_typed_by_pool_member_id;
        foreach (CommitteeSessionAttendance::query()->where('case_id', $record->id)->get() as $attendance) {
            $this->selected[$attendance->committee_pool_member_id] = true;
            $this->votes[$attendance->committee_pool_member_id] = $attendance->vote?->value ?? (string) $attendance->vote;
            if ($attendance->is_chair) {
                $this->chairId = (string) $attendance->committee_pool_member_id;
            }
        }
        if ($this->incidentId !== null) {
            abort_unless(app(IncidentService::class)->canManageAll(Incident::query()->findOrFail($this->incidentId), auth()->user()), 403);
            $members = app(IncidentService::class)->visibleMembers(Incident::query()->findOrFail($this->incidentId), auth()->user());
            foreach ($members as $member) {
                $this->includeCases[$member->id] = app(CommitteeSessionService::class)->sessionReady($member);
                $this->caseMinutes[$member->id] = (string) $member->committee_session_notes;
                $this->caseOutcomes[$member->id] = $member->outcome?->value ?? 'recommendation_only';
                $this->caseDefenseAcceptance[$member->id] = $member->defense_acceptance?->value ?? 'rejected';
                foreach (CommitteeSessionAttendance::query()->where('case_id', $member->id)->get() as $attendance) {
                    $this->caseVotes[$member->id][$attendance->committee_pool_member_id] = $attendance->vote?->value ?? '';
                }
            }
        }
    }

    public function save(CommitteeSessionService $sessions): void
    {
        $case = CaseVisibility::for(auth()->user())->findOrFail($this->caseId);
        Gate::authorize('update', $case);
        if ($case->status->value !== $this->expectedStatus) {
            throw ValidationException::withMessages(['notice' => __('messages.case.changed_reload')]);
        }
        $this->validate(['selected' => ['required', 'array', 'min:3'], 'minutes' => ['required', 'string', 'max:50000'],
            'chairId' => ['required', 'integer'], 'minutesTypedBy' => ['required', 'integer'],
            'outcome' => ['required', 'in:recommendation_only,warning,termination'],
            'defenseAcceptance' => ['required', 'in:accepted_fully,accepted_partially,rejected']]);
        $attendees = [];
        foreach ($this->selected as $id => $chosen) {
            if ($chosen) {
                $attendees[] = ['pool_member_id' => (int) $id, 'vote' => $this->votes[$id] ?? ''];
            }
        }
        try {
            if ($this->incidentId !== null) {
                $this->validate(['includeCases' => ['array'], 'caseMinutes' => ['array'],
                    'caseOutcomes' => ['array'], 'caseDefenseAcceptance' => ['array']]);
                $perCase = [];
                foreach ($this->includeCases as $id => $included) {
                    if (! $included) {
                        continue;
                    }
                    $this->validate([
                        "caseMinutes.{$id}" => ['required', 'string', 'max:50000'],
                        "caseOutcomes.{$id}" => ['required', 'in:recommendation_only,warning,termination'],
                        "caseDefenseAcceptance.{$id}" => ['required', 'in:accepted_fully,accepted_partially,rejected'],
                    ]);
                    $perCase[$id] = ['minutes' => $this->caseMinutes[$id], 'outcome' => $this->caseOutcomes[$id],
                        'defense_acceptance' => $this->caseDefenseAcceptance[$id],
                        'votes' => $this->caseVotes[$id] ?? []];
                }
                $result = $sessions->recordForIncident(Incident::query()->findOrFail($this->incidentId),
                    array_column($attendees, 'pool_member_id'), (int) $this->chairId, $this->minutes,
                    $perCase, auth()->user(), (int) $this->minutesTypedBy);
                $this->notice = __('messages.incident.session_recorded', ['count' => count($result['recorded'])]);

                return;
            }
            $updated = $sessions->recordSession($case, $attendees, (int) $this->chairId, $this->minutes,
                Outcome::from($this->outcome), DefenseAcceptance::from($this->defenseAcceptance),
                auth()->user(), (int) $this->minutesTypedBy);
            $this->expectedStatus = $updated->status->value;
            $this->notice = $updated->session_majority_reached ? __('messages.session.recorded') : __('messages.session.recorded_without_majority');
        } catch (TransitionBlocked $error) {
            $this->notice = match ($error->getCode()) {
                1002 => __('messages.session.union_representative_required_short'),
                1001 => __('messages.session.three_distinct_members_required_short'),
                default => $error->getMessage(),
            };
        }
    }

    public function updatedSelected(): void
    {
        if ($this->chairId !== '') {
            return;
        }
        $case = CaseVisibility::for(auth()->user())->findOrFail($this->caseId);
        Gate::authorize('update', $case);
        $mandate = CommitteeMandate::query()->where('company_id', $case->company_id)
            ->inEffectOn($case->defense_letter_issued_at)->orderByDesc('effective_from')->first();
        $legal = $mandate?->poolMembers()->where('role_type', 'legal')->whereIn('id', array_keys(array_filter($this->selected)))->first();
        $this->chairId = (string) ($legal?->id ?? '');
    }

    public function render(): View
    {
        $case = CaseVisibility::for(auth()->user())->findOrFail($this->caseId);
        Gate::authorize('update', $case);
        $mandate = $case->defense_letter_issued_at ? CommitteeMandate::query()->where('company_id', $case->company_id)
            ->inEffectOn($case->defense_letter_issued_at)->orderByDesc('effective_from')->first() : null;
        $excluded = collect(app(ConflictClearance::class)->conflicts($case))->filter(fn ($row) => $row['excluded'])
            ->pluck('member.id')->all();
        $members = $mandate?->poolMembers()->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $case->branch_id))
            ->whereNotIn('id', $excluded)->get() ?? collect();

        $incidentCases = $this->incidentId === null ? collect() : app(IncidentService::class)
            ->visibleMembers(Incident::query()->findOrFail($this->incidentId), auth()->user());

        return view('livewire.cases.record-session', compact('case', 'members', 'incidentCases'))->layout('layouts.workspace');
    }
}

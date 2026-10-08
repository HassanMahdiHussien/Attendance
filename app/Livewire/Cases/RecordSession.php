<?php

namespace App\Livewire\Cases;

use App\Enums\CaseStatus;
use App\Enums\DefenseAcceptance;
use App\Enums\Outcome;
use App\Exceptions\TransitionBlocked;
use App\Models\CommitteeMandate;
use App\Models\CommitteeSessionAttendance;
use App\Services\Cases\CommitteeSessionService;
use App\Services\Cases\ConflictClearance;
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

    public function mount(string $case): void
    {
        $record = CaseVisibility::for(auth()->user())->findOrFail($case);
        Gate::authorize('update', $record);
        abort_unless(in_array($record->status, [CaseStatus::AwaitingEmployeeResponse, CaseStatus::ResponseReceived,
            CaseStatus::ResponseRefusedToSign, CaseStatus::ResponseDeliveryRefused, CaseStatus::ResponseScannedUploaded,
            CaseStatus::AiIngestedResponse, CaseStatus::CommitteeSessionHeld], true), 404);
        $this->caseId = $record->id;
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
    }

    public function save(CommitteeSessionService $sessions): void
    {
        $case = CaseVisibility::for(auth()->user())->findOrFail($this->caseId);
        Gate::authorize('update', $case);
        if ($case->status->value !== $this->expectedStatus) {
            throw ValidationException::withMessages(['notice' => 'تغيرت الحالة، أعد التحميل']);
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
            $updated = $sessions->recordSession($case, $attendees, (int) $this->chairId, $this->minutes,
                Outcome::from($this->outcome), DefenseAcceptance::from($this->defenseAcceptance),
                auth()->user(), (int) $this->minutesTypedBy);
            $this->expectedStatus = $updated->status->value;
            $this->notice = $updated->session_majority_reached ? 'سُجلت الجلسة.' : 'سُجلت الجلسة دون أغلبية. يلزم إعادة التصويت.';
        } catch (TransitionBlocked $error) {
            $this->notice = match ($error->getMessage()) {
                'يلزم حضور ممثل نقابة العمال.' => 'يلزم حضور ممثل نقابة العمال',
                'يلزم حضور ثلاثة أعضاء مختلفين.' => 'يلزم حضور ثلاثة أعضاء مختلفين',
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

        return view('livewire.cases.record-session', compact('case', 'members'))->layout('layouts.workspace');
    }
}

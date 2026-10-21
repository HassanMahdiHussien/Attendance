<?php

namespace App\Livewire\Reports;

use App\Enums\UserRole;
use App\Livewire\Concerns\PicksPeople;
use App\Models\Branch;
use App\Models\Location;
use App\Models\ReportAssistantSession;
use App\Models\ViolationType;
use App\Services\Cases\CaseViolationTypes;
use App\Services\Employees\EmployeePicker;
use App\Services\Reports\ReportAssistant;
use App\Services\Reports\ReportFiles;
use App\Services\Reports\ReportService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

class SubmitReport extends Component
{
    use PicksPeople { updatedBranchId as resetPickedPeopleOnBranch; }
    use WithFileUploads;

    public string $branchId = '';

    public string $locationId = '';

    public string $place = '';

    public string $occurredAt = '';

    public string $occurredAtText = '';

    public bool $witnessesNone = false;

    public bool $witnessesUnknown = false;

    public bool $employeesUnknown = false;

    public string $violationType = '';

    public string $violationTypeId = '';

    public string $description = '';

    public string $howStarted = '';

    public string $injuriesDamage = '';

    public string $assistantSummary = '';

    public array $attachments = [];

    public bool $chatMode = true;

    public string $chatInput = '';

    #[Locked]
    public ?int $assistantSessionId = null;

    #[Locked]
    public bool $reviewing = false;

    public function mount(): void
    {
        abort_unless(in_array(auth()->user()->primary_role, [UserRole::Reporter, UserRole::DeptManager], true), 403);
        if ($this->assistantSessionId === null && blank(config('services.anthropic.key'))) {
            $this->chatMode = false;
        }
    }

    public function updatedBranchId(): void
    {
        $this->resetPickedPeopleOnBranch();
        $this->assistantSessionId = null;
        $this->assistantSummary = '';
    }

    public function usePlainForm(): void
    {
        if ($this->assistantSessionId !== null) {
            $assistant = app(ReportAssistant::class);
            $assistant->useForm($assistant->owned($this->assistantSessionId, auth()->user()), auth()->user());
        }
        $this->chatMode = false;
    }

    public function unknownPlace(): void
    {
        $this->locationId = '';
        $this->place = __('I do not know');
    }

    public function unknownDate(): void
    {
        $this->occurredAt = '';
        $this->occurredAtText = __('I do not know');
    }

    public function startChat(ReportAssistant $assistant): void
    {
        $this->mount();
        $this->validate(['branchId' => ['required', 'integer'], 'chatInput' => ['nullable', 'string', 'max:5000'],
            'attachments' => ['array', 'max:10'], 'attachments.*' => ['file', 'max:51200']]);
        $session = $assistant->start(auth()->user(), (int) $this->branchId, $this->chatInput, true, $this->attachments !== []);
        if ($this->attachments !== []) {
            app(ReportFiles::class)->add($session, $this->attachments, auth()->user());
            $assistant->filesAdded($session->refresh());
        } else {
            $assistant->advance($session);
        }
        $this->assistantSessionId = $session->id;
        $this->attachments = [];
        $this->chatInput = '';
        $this->syncAssistant($session->refresh());
    }

    public function updatedAttachments(): void
    {
        if ($this->assistantSessionId === null || $this->attachments === []) {
            return;
        }
        $assistant = app(ReportAssistant::class);
        $session = $assistant->owned($this->assistantSessionId, auth()->user());
        app(ReportFiles::class)->add($session, $this->attachments, auth()->user());
        $this->attachments = [];
        if (! $this->reviewing) {
            $assistant->filesAdded($session->refresh());
        }
    }

    public function removeUpload(int $index): void
    {
        unset($this->attachments[$index]);
        $this->attachments = array_values($this->attachments);
    }

    public function removeSessionFile(int $id): void
    {
        $session = app(ReportAssistant::class)->owned((int) $this->assistantSessionId, auth()->user());
        app(ReportFiles::class)->remove($session, $id, auth()->user());
    }

    public function answerChat(ReportAssistant $assistant): void
    {
        $this->mount();
        $this->validate(['chatInput' => ['required', 'string', 'max:5000']]);
        $session = $assistant->owned((int) $this->assistantSessionId, auth()->user());
        $assistant->answer($session, auth()->user(), $this->chatInput);
        $this->chatInput = '';
        $this->syncAssistant($session->refresh());
    }

    public function quickReply(string $value): void
    {
        abort_unless(in_array($value, ['today', 'yesterday', 'none', 'unknown'], true), 422);
        $session = app(ReportAssistant::class)->owned((int) $this->assistantSessionId, auth()->user());
        abort_unless(($value === 'unknown' && $session->pending_aspect !== null)
            || (in_array($value, ['today', 'yesterday'], true) && $session->pending_aspect === 'when')
            || ($value === 'none' && $session->pending_aspect === 'witnesses'), 422);
        // The reply is written in the conversation's language, which can differ from the screen language.
        $this->chatInput = trans('report_assistant.chip', [], $session->language)[$value];
        $this->answerChat(app(ReportAssistant::class));
    }

    public function suggestedReply(int $index): void
    {
        $session = app(ReportAssistant::class)->owned((int) $this->assistantSessionId, auth()->user());
        abort_unless($session->status === 'ready' && $session->pending_aspect !== null, 409);
        $reply = $session->facts['quick_replies'][$index] ?? null;
        abort_unless(is_string($reply) && $reply !== '', 422);
        $this->chatInput = $reply;
        $this->answerChat(app(ReportAssistant::class));
    }

    public function confirmPersonMatch(string $choice, ReportAssistant $assistant): void
    {
        $session = $assistant->owned((int) $this->assistantSessionId, auth()->user());
        $assistant->confirmMatch($session, auth()->user(), $choice);
        $this->syncAssistant($session->refresh());
        if ($choice === 'other') {
            $this->usePlainForm();
        }
    }

    public function pollAssistant(ReportAssistant $assistant): void
    {
        if ($this->assistantSessionId !== null) {
            $this->syncAssistant($assistant->poll($this->assistantSessionId, auth()->user()));
        }
    }

    private function syncAssistant(ReportAssistantSession $session): void
    {
        $facts = $session->facts;
        $this->description = (string) ($facts['description'] ?? $this->description);
        $this->place = (string) ($facts['place'] ?? $this->place);
        $this->locationId = (string) ($facts['location_id'] ?? $this->locationId);
        $this->occurredAt = (string) ($facts['occurred_at'] ?? $this->occurredAt);
        $this->occurredAtText = (string) ($facts['occurred_at_text'] ?? $this->occurredAtText);
        $this->howStarted = (string) ($facts['how_started'] ?? $this->howStarted);
        $this->injuriesDamage = (string) ($facts['injuries_damage'] ?? $this->injuriesDamage);
        $this->assistantSummary = (string) ($facts['summary'] ?? $this->assistantSummary);
        $this->witnessesNone = (bool) ($facts['witnesses_none'] ?? false);
        $this->witnessesUnknown = (bool) ($facts['witnesses_unknown'] ?? false);
        $this->employeesUnknown = (bool) ($facts['employees_unknown'] ?? false);
        $this->people['employees'] = $facts['employees'] ?? [];
        $this->people['witnesses'] = $facts['witnesses'] ?? [];
        $this->people['responsible'] = $facts['responsible'] ?? [];
        if ($session->status === 'fallback') {
            $this->chatMode = false;
        }
    }

    public function submit(): void
    {
        $this->mount();
        if ($this->chatMode && $this->assistantSessionId !== null) {
            $session = app(ReportAssistant::class)->owned($this->assistantSessionId, auth()->user());
            abort_unless($session->status === 'ready' && ($session->facts['pending_match'] ?? null) === null
                && ($session->question_count >= 6 || app(ReportAssistant::class)->missing($session->facts) === []), 409);
        }
        $this->validatedReport();
        $this->reviewing = true;
    }

    /** A finished chat goes straight to the summary review; the full form opens only if something still needs fixing. */
    public function continueToReview(): void
    {
        // The chat does not ask the reporter to classify; the short "what" stands as the free-text type for HR to map.
        if ($this->violationTypeId === '' && $this->violationType === '') {
            $this->violationType = mb_substr($this->description, 0, 255);
        }
        try {
            $this->submit();
        } catch (ValidationException $exception) {
            $this->chatMode = false;

            throw $exception;
        }
    }

    public function editReview(): void
    {
        $this->reviewing = false;
        $this->chatMode = false;
        $this->assistantSummary = '';
    }

    public function confirmSend(ReportService $reports): void
    {
        $this->mount();
        abort_unless($this->reviewing, 409);
        $data = $this->validatedReport();
        $employees = $this->pickedPeople('employees');
        $responsible = $this->pickedPeople('responsible');
        $ids = array_values(array_map(fn ($item) => $item['employee_id'], array_filter($employees, fn ($item) => $item['employee_id'] !== null)));
        $reports->submit([
            'branch_id' => $data['branchId'], 'employee_id' => $ids[0] ?? null,
            'employee_ids' => $ids,
            'free_employee_names' => array_values(array_map(fn ($item) => $item['name'], array_filter($employees, fn ($item) => $item['employee_id'] === null))),
            'employees_unknown' => $this->employeesUnknown && $employees === [],
            'location_id' => $data['locationId'] ?: null, 'place_freetext' => $data['place'] ?: null,
            'occurred_at' => $data['occurredAt'] ?: null, 'occurred_at_text' => $data['occurredAtText'] ?: null,
            'witnesses_none' => $this->witnessesNone && $this->pickedPeople('witnesses') === [],
            'witnesses_unknown' => $this->witnessesUnknown && $this->pickedPeople('witnesses') === [],
            'violation_type' => $data['violationType'],
            'violation_type_id' => $data['violationTypeId'] ?: null,
            'alleged_responsible_employee_id' => $responsible[0]['employee_id'] ?? null,
            'alleged_responsible_person_freetext' => ($responsible[0]['employee_id'] ?? null) === null ? ($responsible[0]['name'] ?? null) : null,
            'description' => $data['description'], 'witnesses' => $this->pickedPeople('witnesses'),
            'how_started' => $data['howStarted'] ?: null, 'injuries_damage' => $data['injuriesDamage'] ?: null,
            'attachments' => $this->attachments,
            'assistant_summary' => $this->assistantSummary,
            'assistant_session_id' => $this->assistantSessionId,
        ], auth()->user());
        $this->assistantSessionId = null;
        session()->flash('status', __('Report sent. HR will review it.'));
        $this->redirect('/reports', navigate: true);
    }

    private function validatedReport(): array
    {
        $data = $this->validate([
            'branchId' => ['required', 'integer'],
            'locationId' => ['nullable', 'integer'], 'place' => ['nullable', 'string', 'max:255'],
            'occurredAt' => ['nullable', 'date', 'before_or_equal:now'],
            'occurredAtText' => ['nullable', 'string', 'max:255'], 'violationType' => ['nullable', 'string', 'max:255'],
            'violationTypeId' => ['nullable', 'integer'],
            'description' => ['required', 'string'],
            'howStarted' => ['nullable', 'string', 'max:5000'],
            'injuriesDamage' => ['nullable', 'string', 'max:5000'],
            'attachments' => ['array', 'max:10'], 'attachments.*' => ['file', 'max:51200'],
        ]);
        if (blank($this->locationId) && blank($this->place)) {
            throw ValidationException::withMessages(['place' => __('messages.report.location_or_place_required')]);
        }
        if ($this->pickedPeople('employees') === [] && ! $this->employeesUnknown) {
            throw ValidationException::withMessages(['people.employees' => __('Name at least one person.')]);
        }
        if (blank($this->occurredAt) && blank($this->occurredAtText)) {
            throw ValidationException::withMessages(['occurredAtText' => __('Enter when it happened or choose I do not know.')]);
        }
        if ($this->pickedPeople('witnesses') === [] && ! $this->witnessesNone && ! $this->witnessesUnknown) {
            throw ValidationException::withMessages(['witnessesNone' => __('Name a witness or choose none.')]);
        }
        app(EmployeePicker::class)->authorizeBranch(auth()->user(), (int) $this->branchId);
        if ($this->locationId !== '') {
            Location::query()->where('company_id', auth()->user()->company_id)
                ->where('branch_id', $this->branchId)->where('is_active', true)->findOrFail($this->locationId);
        }
        app(CaseViolationTypes::class)->selected($this->violationTypeId !== '' ? (int) $this->violationTypeId : null,
            $this->violationType, (int) auth()->user()->company_id);
        $this->pickedPeople('employees');
        $this->pickedPeople('witnesses');

        return $data;
    }

    public function render(EmployeePicker $picker): View
    {
        $this->mount();
        $branchIds = DB::table('branch_user')->where('company_id', auth()->user()->company_id)->where('user_id', auth()->id())->pluck('branch_id');
        $branches = Branch::query()->whereIn('id', $branchIds)->get();
        // A reporter with one branch has nothing to choose.
        if ($this->branchId === '' && $branches->count() === 1) {
            $this->branchId = (string) $branches->first()->id;
        }

        return view('livewire.reports.submit-report', [
            'branches' => $branches,
            'locations' => $this->branchId ? Location::query()->where('branch_id', $this->branchId)->where('is_active', true)->get() : collect(),
            'violationTypes' => ViolationType::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'peopleResults' => $this->peopleResults($picker),
            'assistantSession' => $this->assistantSessionId !== null ? app(ReportAssistant::class)->owned($this->assistantSessionId, auth()->user()) : null,
            'sessionFiles' => $this->assistantSessionId !== null
                ? app(ReportFiles::class)->active(app(ReportAssistant::class)->owned($this->assistantSessionId, auth()->user())) : collect(),
        ])->layout('layouts.workspace');
    }

    protected function allowsFreeEmployees(): bool
    {
        return true;
    }
}

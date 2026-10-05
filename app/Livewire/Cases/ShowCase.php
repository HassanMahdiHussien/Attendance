<?php

namespace App\Livewire\Cases;

use App\Enums\CaseStatus;
use App\Enums\SignaturePurpose;
use App\Enums\SignatureStatus;
use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\CommitteePoolMember;
use App\Models\DisciplinaryCase;
use App\Models\Employee;
use App\Models\Signature;
use App\Models\User;
use App\Services\Cases\CaseAssignment;
use App\Services\Cases\CaseInsights;
use App\Services\Cases\CaseOpening;
use App\Services\Cases\ConflictClearance;
use App\Services\Cases\Deadlines;
use App\Services\Cases\HandOffService;
use App\Services\Cases\ResponseService;
use App\Services\Documents\DocumentCorrector;
use App\Support\Access\CaseVisibility;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;

class ShowCase extends Component
{
    use WithFileUploads;

    public int $caseId;

    public string $expectedStatus = '';

    public string $notice = '';

    public string $assigneeId = '';

    public string $clearanceReason = '';

    public string $handoffPurpose = 'defense_letter_receipt';

    public string $handoffStatus = 'signed';

    public string $signedAt = '';

    public string $attemptedAt = '';

    public string $attemptLocation = '';

    public string $attemptedBy = '';

    public string $witnessOne = '';

    public string $witnessTwo = '';

    public $responseScan;

    public string $correctionReason = '';

    public function mount(string $case): void
    {
        $record = CaseVisibility::for(auth()->user())->findOrFail($case);
        Gate::authorize('view', $record);
        $this->caseId = $record->id;
        $this->expectedStatus = $record->status->value;
        $this->assigneeId = (string) $record->assigned_hr_user_id;
    }

    public function assign(CaseAssignment $assignments): void
    {
        $case = $this->editable('assign');
        if ($case->status !== CaseStatus::Open) {
            throw ValidationException::withMessages(['notice' => 'التكليف متاح عند فتح الحالة.']);
        }
        $this->validate(['assigneeId' => ['nullable', 'integer']]);
        $user = $this->assigneeId === '' ? null : User::query()->where('company_id', $case->company_id)->findOrFail($this->assigneeId);
        $assignments->assign($case, $user, auth()->user());
        $this->notice = 'حُدث التكليف.';
    }

    public function clearConflict(int $memberId, ConflictClearance $clearances): void
    {
        $case = $this->editable('update');
        $this->validate(['clearanceReason' => ['required', 'string', 'max:1000']]);
        $member = CommitteePoolMember::query()->where('company_id', $case->company_id)->findOrFail($memberId);
        $clearances->clear($case, $member, $this->clearanceReason, auth()->user());
        $this->clearanceReason = '';
        $this->notice = 'سُجل تحقق الهوية.';
    }

    public function open(CaseOpening $opening): void
    {
        $case = $this->editable('update');
        if ($case->status !== CaseStatus::Draft) {
            $this->stale();

            return;
        }
        if (app(CaseInsights::class)->duplicateCandidates($case)['cases']->isNotEmpty()) {
            throw ValidationException::withMessages(['notice' => 'قد توجد حالة مكررة؛ راجع فحص التكرار قبل الفتح.']);
        }
        $opening->open($case, auth()->user());
        $this->expectedStatus = CaseStatus::Open->value;
        $this->notice = 'فُتحت الحالة.';
    }

    public function recordHandOff(HandOffService $handOffs, ResponseService $responses): void
    {
        $case = $this->editable('recordHandOff');
        $this->validate([
            'handoffPurpose' => ['required', 'in:defense_letter_receipt,response_submission'],
            'handoffStatus' => ['required', 'in:signed,refused_to_sign,delivery_refused'],
            'signedAt' => ['required_if:handoffStatus,signed', 'nullable', 'date'], 'attemptedAt' => ['required_if:handoffStatus,delivery_refused', 'nullable', 'date'],
            'attemptLocation' => ['required_if:handoffStatus,delivery_refused', 'nullable', 'string', 'max:255'], 'attemptedBy' => ['required_if:handoffStatus,delivery_refused', 'nullable', 'string', 'max:255'],
            'witnessOne' => ['required_unless:handoffStatus,signed', 'nullable', 'string', 'max:255'], 'witnessTwo' => ['required_unless:handoffStatus,signed', 'different:witnessOne', 'nullable', 'string', 'max:255'],
        ]);
        $purpose = SignaturePurpose::from($this->handoffPurpose);
        $status = SignatureStatus::from($this->handoffStatus);
        if ($case->status !== CaseStatus::AwaitingEmployeeResponse) {
            $this->stale();

            return;
        }
        $data = ['signed_at' => $this->signedAt ?: null, 'attempted_at' => $this->attemptedAt ?: null,
            'attempt_location' => $this->attemptLocation ?: null, 'attempted_by_name' => $this->attemptedBy ?: null,
            'witness_1_name' => $this->witnessOne ?: null, 'witness_2_name' => $this->witnessTwo ?: null];
        if ($purpose === SignaturePurpose::ResponseSubmission) {
            $responses->recordHandOff($case, $status, $data, auth()->user());
            $this->expectedStatus = $case->fresh()->status->value;
        } else {
            $handOffs->record($case, $purpose, $status, $data, auth()->user());
        }
        $this->notice = 'سُجل التسليم.';
    }

    public function uploadResponse(ResponseService $responses): void
    {
        $case = $this->editable('uploadAttachment');
        $this->validate(['responseScan' => ['required', 'file', 'max:10240']]);
        $responses->attachScan($case, $this->responseScan, auth()->user());
        $this->responseScan = null;
        $this->expectedStatus = CaseStatus::ResponseScannedUploaded->value;
        $this->notice = 'رُفع مسح الرد.';
    }

    public function markNoResponse(ResponseService $responses): void
    {
        $case = $this->editable('recordHandOff');
        $responses->markNoResponse($case, auth()->user());
        $this->notice = 'سُجل عدم ورود رد.';
    }

    public function startCorrection(int $documentId, DocumentCorrector $corrector): void
    {
        $case = $this->editable('correct');
        $this->validate(['correctionReason' => ['required', 'string', 'max:1000']]);
        $document = $case->documents()->findOrFail($documentId);
        $draft = $corrector->startCorrection($document, $this->correctionReason, auth()->user());
        $this->redirect('/cases/'.$case->id.'/documents/'.$draft->id.'/edit', navigate: true);
    }

    public function render(): View
    {
        $record = CaseVisibility::for(auth()->user())->findOrFail($this->caseId);
        Gate::authorize('view', $record);
        $record->setRelation('employee', Employee::withoutGlobalScopes()->find($record->employee_id));

        $record->load(['documents.contents', 'events.actorUser', 'assignedHrUser']);
        $insights = app(CaseInsights::class);
        $deadlines = app(Deadlines::class);
        $assigneeIds = $record->events->where('event_type', 'case_assigned')
            ->pluck('payload')->map(fn (array $payload) => $payload['user_id'] ?? null)->filter()->unique();
        $signatureIds = $record->events->whereIn('event_type', ['hand_off_recorded', 'response_handoff_recorded'])
            ->pluck('payload')->map(fn (array $payload) => $payload['signature_id'] ?? null)->filter()->unique();

        return view('livewire.cases.show-case', [
            'record' => $record,
            'success' => session()->pull('case_success_'.$record->id),
            'assigneeNames' => User::query()->where('company_id', $record->company_id)->whereIn('id', $assigneeIds)->pluck('name', 'id')->all(),
            'signatures' => Signature::query()->where('case_id', $record->id)->whereIn('id', $signatureIds)->get()->keyBy('id'),
            'priors' => $insights->priorBanner($record),
            'pattern' => $insights->responsiblePersonPattern($record),
            'conflicts' => app(ConflictClearance::class)->conflicts($record),
            'deadlines' => $deadlines,
            'attachments' => Attachment::query()->where('attachable_type', $record->getMorphClass())->where('attachable_id', $record->id)->orderByDesc('created_at')->get(),
            'hrUsers' => User::query()->where('company_id', $record->company_id)->where('is_active', true)
                ->whereIn('primary_role', [UserRole::HrStaff->value, UserRole::CompanyAdmin->value])->get(),
        ])->layout('layouts.workspace');
    }

    private function editable(string $ability): DisciplinaryCase
    {
        $case = CaseVisibility::for(auth()->user())->findOrFail($this->caseId);
        Gate::authorize($ability, $case);
        if ($case->status->value !== $this->expectedStatus) {
            $this->stale();
            throw ValidationException::withMessages(['notice' => 'تغيرت الحالة، أعد التحميل']);
        }

        return $case;
    }

    private function stale(): void
    {
        $this->notice = 'تغيرت الحالة، أعد التحميل';
    }
}

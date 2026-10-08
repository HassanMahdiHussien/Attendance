<?php

namespace App\Livewire\Cases;

use App\Enums\CaseStatus;
use App\Enums\DocumentType;
use App\Enums\SignaturePurpose;
use App\Enums\SignatureStatus;
use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\CommitteePoolMember;
use App\Models\DisciplinaryCase;
use App\Models\Employee;
use App\Models\ResponseTranscription;
use App\Models\Signature;
use App\Models\User;
use App\Services\Cases\CaseAssignment;
use App\Services\Cases\CaseClosure;
use App\Services\Cases\CaseInsights;
use App\Services\Cases\CaseOpening;
use App\Services\Cases\ConflictClearance;
use App\Services\Cases\ConsultationService;
use App\Services\Cases\Deadlines;
use App\Services\Cases\HandOffService;
use App\Services\Cases\ResponseReading;
use App\Services\Cases\ResponseService;
use App\Services\Cases\SignaturePresence;
use App\Services\Documents\DocumentCorrector;
use App\Support\Access\CaseVisibility;
use App\Support\Tenancy\TenantContext;
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

    public $witnessScan;

    public $responseScan;

    public bool $responseScanSafe = false;

    public string $responseText = '';

    public string $responseOrigin = 'human';

    public string $responseCorrectionReason = '';

    public ?int $shownTranscriptionId = null;

    public string $lastAiPrefill = '';

    public string $correctionReason = '';

    public string $consultationCancelReason = '';

    public array $decisionSignaturePresence = ['general_manager' => 'filled', 'union' => 'filled'];

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
            throw ValidationException::withMessages(['notice' => __('messages.case.assignment_only_when_open')]);
        }
        $this->validate(['assigneeId' => ['nullable', 'integer']]);
        $user = $this->assigneeId === '' ? null : User::query()->where('company_id', $case->company_id)->findOrFail($this->assigneeId);
        $assignments->assign($case, $user, auth()->user());
        $this->notice = __('messages.case.assignment_updated');
    }

    public function clearConflict(int $memberId, ConflictClearance $clearances): void
    {
        $case = $this->editable('update');
        $this->validate(['clearanceReason' => ['required', 'string', 'max:1000']]);
        $member = CommitteePoolMember::query()->where('company_id', $case->company_id)->findOrFail($memberId);
        $clearances->clear($case, $member, $this->clearanceReason, auth()->user());
        $this->clearanceReason = '';
        $this->notice = __('messages.case.identity_check_recorded');
    }

    public function open(CaseOpening $opening): void
    {
        $case = $this->editable('update');
        if ($case->status !== CaseStatus::Draft) {
            $this->stale();

            return;
        }
        if (app(CaseInsights::class)->duplicateCandidates($case)['cases']->isNotEmpty()) {
            throw ValidationException::withMessages(['notice' => __('messages.case.possible_duplicate')]);
        }
        $opening->open($case, auth()->user());
        $this->expectedStatus = CaseStatus::Open->value;
        $this->notice = __('messages.case.opened');
    }

    public function recordHandOff(HandOffService $handOffs, ResponseService $responses): void
    {
        $case = $this->editable('recordHandOff');
        $this->validate([
            'handoffPurpose' => ['required', 'in:defense_letter_receipt,response_submission,decision_letter_receipt'],
            'handoffStatus' => ['required', 'in:signed,refused_to_sign,delivery_refused'],
            'signedAt' => ['required_if:handoffStatus,signed', 'nullable', 'date'], 'attemptedAt' => ['required_if:handoffStatus,delivery_refused', 'nullable', 'date'],
            'attemptLocation' => ['required_if:handoffStatus,delivery_refused', 'nullable', 'string', 'max:255'], 'attemptedBy' => ['required_if:handoffStatus,delivery_refused', 'nullable', 'string', 'max:255'],
            'witnessOne' => ['required_unless:handoffStatus,signed', 'nullable', 'string', 'max:255'], 'witnessTwo' => ['required_unless:handoffStatus,signed', 'different:witnessOne', 'nullable', 'string', 'max:255'],
            'witnessScan' => ['exclude_if:handoffStatus,signed', 'required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ], [
            'signedAt.required_if' => __('messages.handoff.signed_date_required'),
            'attemptedAt.required_if' => __('messages.handoff.attempt_date_required'),
            'attemptLocation.required_if' => __('messages.handoff.attempt_location_required'),
            'attemptedBy.required_if' => __('messages.handoff.attempt_actor_required'),
            'witnessOne.required_unless' => __('messages.handoff.first_witness_required'),
            'witnessTwo.required_unless' => __('messages.handoff.second_witness_required'),
            'witnessTwo.different' => __('messages.handoff.distinct_witnesses_required'),
            'witnessScan.required' => __('messages.handoff.witness_scan_required'),
            'witnessScan.file' => __('messages.handoff.witness_scan_file_required'),
            'witnessScan.mimes' => __('messages.attachment.pdf_jpg_png_only'),
            'witnessScan.max' => __('messages.handoff.witness_scan_too_large'),
        ]);
        $purpose = in_array($case->status, [CaseStatus::AwaitingAppealWindow, CaseStatus::AppealPending], true)
            ? SignaturePurpose::DecisionLetterReceipt : SignaturePurpose::from($this->handoffPurpose);
        $status = SignatureStatus::from($this->handoffStatus);
        if (($purpose === SignaturePurpose::DecisionLetterReceipt && ! in_array($case->status, [CaseStatus::AwaitingAppealWindow, CaseStatus::AppealPending], true))
            || ($purpose !== SignaturePurpose::DecisionLetterReceipt && $case->status !== CaseStatus::AwaitingEmployeeResponse)) {
            $this->stale();

            return;
        }
        $data = ['signed_at' => $this->signedAt ?: null, 'attempted_at' => $this->attemptedAt ?: null,
            'attempt_location' => $this->attemptLocation ?: null, 'attempted_by_name' => $this->attemptedBy ?: null,
            'witness_1_name' => $this->witnessOne ?: null, 'witness_2_name' => $this->witnessTwo ?: null,
            'witness_scan' => $status === SignatureStatus::Signed ? null : $this->witnessScan];
        if ($purpose === SignaturePurpose::ResponseSubmission) {
            $responses->recordHandOff($case, $status, $data, auth()->user());
            $this->expectedStatus = $case->fresh()->status->value;
        } else {
            $handOffs->record($case, $purpose, $status, $data, auth()->user());
        }
        $this->notice = __('messages.handoff.recorded');
        $this->witnessScan = null;
    }

    public function requestConsultation(ConsultationService $consultations): void
    {
        $case = CaseVisibility::for(auth()->user())->findOrFail($this->caseId);
        if ($case->status->value !== $this->expectedStatus) {
            throw ValidationException::withMessages(['notice' => __('messages.case.changed_reload')]);
        }
        $consultations->request($case, auth()->user());
        $this->notice = __('messages.consultation.requested_deadline_continues');
    }

    public function cancelConsultation(ConsultationService $consultations): void
    {
        $case = $this->editable('update');
        $this->validate(['consultationCancelReason' => ['required', 'string', 'max:2000']]);
        $consultations->cancel($case, $this->consultationCancelReason, auth()->user());
        $this->consultationCancelReason = '';
        $this->notice = __('messages.consultation.cancelled_with_reason');
    }

    public function closeWithoutPenalty(CaseClosure $closure): void
    {
        $case = $this->editable('update');
        $closure->closeWithoutPenalty($case, auth()->user());
        $this->expectedStatus = CaseStatus::ArchivedFinal->value;
        $this->notice = __('messages.case.archived_without_penalty');
    }

    public function recordDecisionSignatures(SignaturePresence $presence): void
    {
        $case = $this->editable('update');
        $this->validate(['decisionSignaturePresence.general_manager' => ['required', 'in:filled,empty'],
            'decisionSignaturePresence.union' => ['required', 'in:filled,empty']]);
        $document = $case->documents()->where('document_type', DocumentType::DecisionLetter->value)
            ->where('is_locked', true)->orderByDesc('version')->firstOrFail();
        $presence->recordDecisionSignatures($document, $this->decisionSignaturePresence, auth()->user());
        $this->notice = __('messages.signature.decision_boxes_recorded');
    }

    public function uploadResponse(ResponseService $responses): void
    {
        $case = $this->editable('uploadAttachment');
        $this->validate(['responseScan' => ['required', 'file', 'max:10240']]);
        $responses->attachScan($case, $this->responseScan, auth()->user());
        $this->responseScan = null;
        $this->expectedStatus = CaseStatus::ResponseScannedUploaded->value;
        $this->notice = __('messages.response.scan_uploaded');
    }

    public function markNoResponse(ResponseService $responses): void
    {
        $case = $this->editable('recordHandOff');
        $responses->markNoResponse($case, auth()->user());
        $this->notice = __('messages.response.no_response_recorded');
    }

    public function requestResponseReading(ResponseReading $reading): void
    {
        $case = $this->editable('uploadAttachment');
        $this->validate(['responseScanSafe' => ['accepted']]);
        $reading->request($case, auth()->user(), $this->responseScanSafe);
        $this->notice = __('messages.response.scan_reading_started');
    }

    public function confirmResponse(ResponseReading $reading): void
    {
        $case = $this->editable('uploadAttachment');
        $this->validate(['responseText' => ['required', 'string'], 'responseOrigin' => ['required', 'in:human,ai_checked']]);
        $reading->confirm($case, $this->responseText, $this->responseOrigin, auth()->user());
        $this->expectedStatus = CaseStatus::AiIngestedResponse->value;
        $this->notice = __('messages.response.text_confirmed_against_scan');
    }

    public function correctResponse(ResponseReading $reading): void
    {
        $case = $this->editable('uploadAttachment');
        $this->validate(['responseCorrectionReason' => ['required', 'string', 'max:2000']]);
        $reading->correct($case, $this->responseCorrectionReason, auth()->user());
        $this->expectedStatus = CaseStatus::ResponseScannedUploaded->value;
        $this->responseText = '';
        $this->lastAiPrefill = '';
        $this->responseOrigin = 'human';
        $this->notice = __('messages.response.correction_started');
    }

    public function startCorrection(int $documentId, DocumentCorrector $corrector): void
    {
        $case = $this->editable('correct');
        $this->validate(['correctionReason' => ['required', 'string', 'max:1000']]);
        $document = $case->documents()->findOrFail($documentId);
        $draft = $corrector->startCorrection($document, $this->correctionReason, auth()->user());
        $url = $draft->document_type === DocumentType::Recommendations
            ? '/cases/'.$case->id.'/recommendations/'.$draft->id
            : '/cases/'.$case->id.'/documents/'.$draft->id.'/edit';
        $this->redirect($url, navigate: true);
    }

    public function render(): View
    {
        $record = CaseVisibility::for(auth()->user())->findOrFail($this->caseId);
        Gate::authorize('view', $record);
        if (auth()->user()->primary_role === UserRole::ExternalLegalReviewer) {
            app(TenantContext::class)->setCompany($record->company);
        }
        $record->setRelation('employee', Employee::withoutGlobalScopes()->find($record->employee_id));

        $record->load(['documents.contents', 'events.actorUser', 'assignedHrUser', 'responseChecker']);
        $insights = app(CaseInsights::class);
        $deadlines = app(Deadlines::class);
        $assigneeIds = $record->events->where('event_type', 'case_assigned')
            ->pluck('payload')->map(fn (array $payload) => $payload['user_id'] ?? null)->filter()->unique();
        $signatureIds = $record->events->whereIn('event_type', ['hand_off_recorded', 'response_handoff_recorded'])
            ->pluck('payload')->map(fn (array $payload) => $payload['signature_id'] ?? null)->filter()->unique();
        $recommendationsDocument = $record->documents->where('document_type', DocumentType::Recommendations)
            ->where('is_locked', true)->last();
        $decisionDocument = $record->documents->where('document_type', DocumentType::DecisionLetter)
            ->where('is_locked', true)->last();
        $responseScan = app(ResponseReading::class)->latestScan($record);
        $aiAttempt = $responseScan && $record->status === CaseStatus::ResponseScannedUploaded
            && in_array(auth()->user()->primary_role, [UserRole::CompanyAdmin, UserRole::HrStaff], true)
            ? ResponseTranscription::query()->where('case_id', $record->id)->where('attachment_id', $responseScan->id)
                ->where('origin', 'ai')->orderByDesc('id')->first() : null;
        if ($aiAttempt && $this->shownTranscriptionId !== $aiAttempt->id) {
            $this->shownTranscriptionId = $aiAttempt->id;
            if ($this->responseText === '' || $this->responseText === $this->lastAiPrefill) {
                $this->responseText = $aiAttempt->text;
                $this->responseOrigin = 'ai_checked';
            }
            $this->lastAiPrefill = $aiAttempt->text;
        }

        return view('livewire.cases.show-case', [
            'record' => $record,
            'responseScanAttachment' => $responseScan,
            'aiAttempt' => $aiAttempt,
            'success' => session()->pull('case_success_'.$record->id),
            'assigneeNames' => User::query()->where('company_id', $record->company_id)->whereIn('id', $assigneeIds)->pluck('name', 'id')->all(),
            'signatures' => Signature::query()->where('case_id', $record->id)->whereIn('id', $signatureIds)
                ->with('witnessScanAttachment')->get()->keyBy('id'),
            'signatureChecks' => $recommendationsDocument
                ? app(SignaturePresence::class)->latestChecks($recommendationsDocument) : collect(),
            'decisionSignatureChecks' => $decisionDocument ? Signature::query()->where('document_id', $decisionDocument->id)
                ->whereIn('purpose', [SignaturePurpose::GeneralManagerSignature->value, SignaturePurpose::UnionSignature->value])
                ->orderByDesc('id')->get()->unique(fn (Signature $check) => $check->purpose->value)->values() : collect(),
            'priors' => auth()->user()->primary_role === UserRole::ExternalLegalReviewer ? ['count' => 0, 'cases' => []] : $insights->priorBanner($record),
            'pattern' => auth()->user()->primary_role === UserRole::ExternalLegalReviewer ? ['flagged' => false, 'count' => 0] : $insights->responsiblePersonPattern($record),
            'conflicts' => auth()->user()->primary_role === UserRole::ExternalLegalReviewer ? [] : app(ConflictClearance::class)->conflicts($record),
            'deadlines' => $deadlines,
            'attachments' => Attachment::query()->where(function ($query) use ($record): void {
                $query->where(fn ($caseAttachments) => $caseAttachments->where('attachable_type', $record->getMorphClass())->where('attachable_id', $record->id))
                    ->orWhere(fn ($documentAttachments) => $documentAttachments->where('attachable_type', 'document')
                        ->whereIn('attachable_id', $record->documents->pluck('id')));
            })->orderByDesc('created_at')->get(),
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
            throw ValidationException::withMessages(['notice' => __('messages.case.changed_reload')]);
        }

        return $case;
    }

    private function stale(): void
    {
        $this->notice = __('messages.case.changed_reload');
    }
}

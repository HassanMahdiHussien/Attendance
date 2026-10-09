<?php

namespace App\Livewire\Cases;

use App\Enums\CaseStatus;
use App\Enums\DocumentType;
use App\Exceptions\TransitionBlocked;
use App\Models\CommitteeSessionAttendance;
use App\Models\DisciplinaryCase;
use App\Models\SignaturePresenceSuggestion;
use App\Services\Cases\CaseClosure;
use App\Services\Cases\ConsultationService;
use App\Services\Cases\SignaturePresence;
use App\Support\Access\CaseVisibility;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;

class SignedScan extends Component
{
    use WithFileUploads;

    public int $caseId;

    public string $expectedStatus = '';

    public $scan;

    public array $presence = [];

    public string $reason = '';

    public string $notice = '';

    public ?int $suggestionId = null;

    public ?int $shownScanId = null;

    public function mount(string $case): void
    {
        $record = CaseVisibility::for(auth()->user())->findOrFail($case);
        Gate::authorize('uploadAttachment', $record);
        abort_unless(in_array($record->status, [CaseStatus::AwaitingCommitteeSignatures, CaseStatus::SignedRecommendationsUploaded], true), 404);
        $this->caseId = $record->id;
        $this->expectedStatus = $record->status->value;
    }

    public function upload(SignaturePresence $signatures): void
    {
        $case = $this->currentCase();
        $this->validate(['scan' => ['required', 'file', 'max:10240'], 'presence' => ['required', 'array']]);
        $document = $case->documents()->where('document_type', DocumentType::Recommendations->value)->where('is_locked', true)->latest('id')->firstOrFail();
        try {
            $signatures->recordSignedScan($document, $this->scan, $this->presence, auth()->user());
            $this->scan = null;
            $this->expectedStatus = $case->fresh()->status->value;
            if ($this->expectedStatus === CaseStatus::AiVerifyAndClose->value) {
                $this->redirect('/cases/'.$this->caseId, navigate: true);

                return;
            }
            $this->notice = $this->expectedStatus === CaseStatus::SignedRecommendationsUploaded->value
                ? __('messages.signature.scan_uploaded_present') : __('messages.signature.scan_uploaded_empty_box');
        } catch (TransitionBlocked $error) {
            $this->notice = $error->getMessage();
        }
    }

    public function override(SignaturePresence $signatures): void
    {
        $case = $this->currentCase();
        $this->validate(['reason' => ['required', 'string', 'max:2000']]);
        $document = $case->documents()->where('document_type', DocumentType::Recommendations->value)->where('is_locked', true)->latest('id')->firstOrFail();
        try {
            $signatures->overrideEmpty($document, $this->reason, auth()->user());
        } catch (TransitionBlocked $error) {
            $this->notice = $error->getMessage();

            return;
        }
        $this->expectedStatus = $case->fresh()->status->value;
        if ($this->expectedStatus === CaseStatus::AiVerifyAndClose->value) {
            $this->redirect('/cases/'.$this->caseId, navigate: true);

            return;
        }
        $this->notice = __('messages.signature.empty_box_override_recorded');
    }

    public function uploadForReading(SignaturePresence $signatures): void
    {
        $case = $this->currentCase();
        $this->validate(['scan' => ['required', 'file', 'max:10240']]);
        $document = $case->documents()->where('document_type', DocumentType::Recommendations->value)->where('is_locked', true)->latest('id')->firstOrFail();
        try {
            $signatures->uploadSignedScan($document, $this->scan, auth()->user());
        } catch (TransitionBlocked $error) {
            $this->notice = $error->getMessage();

            return;
        }
        $this->scan = null;
        $this->suggestionId = null;
        $this->notice = __('messages.signature.scan_reading_started');
    }

    public function recordReadPresence(SignaturePresence $signatures): void
    {
        // Errors raised by hand persist between Livewire requests until reset; a corrected submission must not
        // keep showing the previous refusal.
        $this->resetErrorBag();
        $case = $this->currentCase();
        $document = $case->documents()->where('document_type', DocumentType::Recommendations->value)->where('is_locked', true)->latest('id')->firstOrFail();
        $scan = $signatures->latestScan($document);
        if (! $scan) {
            throw ValidationException::withMessages(['scan' => __('messages.signature.scan_required')]);
        }
        // Unclear suggestions leave a row unselected: HR must decide every box before recording.
        if (collect($this->presence)->contains(fn ($value) => ! in_array($value, ['filled', 'empty'], true))
            || count(array_filter($this->presence)) < CommitteeSessionAttendance::query()->where('case_id', $case->id)->count()) {
            throw ValidationException::withMessages(['notice' => __('messages.signature.every_box_required')]);
        }
        try {
            $signatures->recordPresence($document, $scan, $this->presence, auth()->user(), $this->suggestionId);
        } catch (TransitionBlocked $error) {
            $this->notice = $error->getMessage();

            return;
        }
        $this->expectedStatus = $case->fresh()->status->value;
        if ($this->expectedStatus === CaseStatus::AiVerifyAndClose->value) {
            $this->redirect('/cases/'.$this->caseId, navigate: true);

            return;
        }
        $this->notice = $this->expectedStatus === CaseStatus::SignedRecommendationsUploaded->value
            ? __('messages.signature.boxes_recorded') : __('messages.signature.boxes_recorded_empty');
    }

    public function close(CaseClosure $closure): void
    {
        $case = $this->currentCase();
        $closure->closeWithoutPenalty($case, auth()->user());
        $this->redirect('/cases/'.$this->caseId, navigate: true);
    }

    public function cancelConsultation(ConsultationService $consultations): void
    {
        $case = $this->currentCase();
        $this->validate(['reason' => ['required', 'string', 'max:2000']]);
        $consultations->cancel($case, $this->reason, auth()->user());
        $this->notice = __('messages.consultation.cancelled_with_reason');
    }

    public function render(): View
    {
        $case = CaseVisibility::for(auth()->user())->findOrFail($this->caseId);
        Gate::authorize('uploadAttachment', $case);
        $document = $case->documents()->where('document_type', DocumentType::Recommendations->value)->where('is_locked', true)->latest('id')->firstOrFail();
        $attendees = CommitteeSessionAttendance::query()->where('case_id', $case->id)->with('committeePoolMember')->get();
        $checks = app(SignaturePresence::class)->latestChecks($document)->keyBy('committee_pool_member_id');

        $scan = app(SignaturePresence::class)->latestScan($document);
        if ($scan && $this->shownScanId !== $scan->id) {
            $this->shownScanId = $scan->id;
            $this->suggestionId = null;
            $this->presence = [];
        }
        $suggestion = $scan ? SignaturePresenceSuggestion::query()->where('document_id', $document->id)
            ->where('attachment_id', $scan->id)->orderByDesc('id')->first() : null;
        if ($suggestion && $this->suggestionId !== $suggestion->id) {
            $this->suggestionId = $suggestion->id;
            foreach ($suggestion->results as $attendanceId => $result) {
                if (! isset($this->presence[$attendanceId]) || $this->presence[$attendanceId] === '') {
                    $this->presence[$attendanceId] = match ($result['result']) {
                        'appears_filled' => 'filled', 'appears_empty' => 'empty', default => '',
                    };
                }
            }
        }

        return view('livewire.cases.signed-scan', [...compact('case', 'document', 'attendees', 'checks', 'suggestion'),
            // Named apart from the public $scan upload property, which Livewire would otherwise expose in its place.
            'latestScan' => $scan])->layout('layouts.workspace');
    }

    private function currentCase(): DisciplinaryCase
    {
        $case = CaseVisibility::for(auth()->user())->findOrFail($this->caseId);
        Gate::authorize('uploadAttachment', $case);
        if ($case->status->value !== $this->expectedStatus) {
            throw ValidationException::withMessages(['notice' => __('messages.case.changed_reload')]);
        }

        return $case;
    }
}

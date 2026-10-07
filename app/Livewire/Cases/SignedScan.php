<?php

namespace App\Livewire\Cases;

use App\Enums\CaseStatus;
use App\Enums\DocumentType;
use App\Exceptions\TransitionBlocked;
use App\Models\CommitteeSessionAttendance;
use App\Models\DisciplinaryCase;
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
            $this->notice = $this->expectedStatus === CaseStatus::SignedRecommendationsUploaded->value ? 'رُفع المسح وسُجل ظهور التواقيع.' : 'رُفع المسح. توجد خانة توقيع تبدو فارغة.';
        } catch (TransitionBlocked $error) {
            $this->notice = $error->getMessage();
        }
    }

    public function override(SignaturePresence $signatures): void
    {
        $case = $this->currentCase();
        $this->validate(['reason' => ['required', 'string', 'max:2000']]);
        $document = $case->documents()->where('document_type', DocumentType::Recommendations->value)->where('is_locked', true)->latest('id')->firstOrFail();
        $signatures->overrideEmpty($document, $this->reason, auth()->user());
        $this->expectedStatus = CaseStatus::SignedRecommendationsUploaded->value;
        $this->notice = 'سُجل سبب تجاوز الخانة الفارغة.';
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
        $this->notice = 'أُلغيت الاستشارة مع تسجيل السبب.';
    }

    public function render(): View
    {
        $case = CaseVisibility::for(auth()->user())->findOrFail($this->caseId);
        Gate::authorize('uploadAttachment', $case);
        $document = $case->documents()->where('document_type', DocumentType::Recommendations->value)->where('is_locked', true)->latest('id')->firstOrFail();
        $attendees = CommitteeSessionAttendance::query()->where('case_id', $case->id)->with('committeePoolMember')->get();
        $checks = app(SignaturePresence::class)->latestChecks($document)->keyBy('committee_pool_member_id');

        return view('livewire.cases.signed-scan', compact('case', 'document', 'attendees', 'checks'))->layout('layouts.workspace');
    }

    private function currentCase(): DisciplinaryCase
    {
        $case = CaseVisibility::for(auth()->user())->findOrFail($this->caseId);
        Gate::authorize('uploadAttachment', $case);
        if ($case->status->value !== $this->expectedStatus) {
            throw ValidationException::withMessages(['notice' => 'تغيرت الحالة، أعد التحميل']);
        }

        return $case;
    }
}

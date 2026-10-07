<?php

namespace App\Livewire\Cases;

use App\Enums\CaseStatus;
use App\Enums\DocumentType;
use App\Exceptions\IssuanceBlocked;
use App\Exceptions\TransitionBlocked;
use App\Livewire\Cases\Concerns\DraftsWithAi;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Services\Cases\RecommendationsEditor;
use App\Support\Access\CaseVisibility;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class EditRecommendations extends Component
{
    use DraftsWithAi;

    public int $caseId;

    public ?int $documentId = null;

    public string $expectedStatus = '';

    public array $findings = [];

    public array $recommendations = [];

    public string $notice = '';

    public function mount(string $case, ?string $document = null): void
    {
        $record = CaseVisibility::for(auth()->user())->findOrFail($case);
        Gate::authorize('manageDocuments', $record);
        abort_unless($document !== null || in_array($record->status, [CaseStatus::CommitteeSessionHeld, CaseStatus::AiDraftingRecommendations, CaseStatus::RecommendationsCitationCheck,
            CaseStatus::RecommendationsAwaitingApprovals, CaseStatus::RecommendationsConfirmed], true), 404);
        $this->caseId = $record->id;
        $this->expectedStatus = $record->status->value;
        $draft = $document !== null ? $record->documents()->findOrFail($document)
            : $record->documents()->where('document_type', DocumentType::Recommendations->value)->where('is_locked', false)->first();
        if ($draft) {
            abort_unless($draft->document_type === DocumentType::Recommendations && ! $draft->is_locked, 404);
            $this->documentId = $draft->id;
            $this->findings = $draft->contents->pluck('body', 'language_code')->all();
            $this->recommendations = $draft->contents->pluck('secondary_body', 'language_code')->all();
        }
    }

    public function save(RecommendationsEditor $editor): void
    {
        $case = $this->editable();
        $languages = array_unique(array_merge(['ar', 'en'], $case->company->enabled_languages));
        $contents = [];
        foreach ($languages as $language) {
            if (blank($this->findings[$language] ?? null) || blank($this->recommendations[$language] ?? null)) {
                throw ValidationException::withMessages(['notice' => 'النتائج والتوصيات مطلوبة بكل اللغات المفعلة.']);
            }
            $contents[$language] = ['body' => $this->findings[$language], 'secondary_body' => $this->recommendations[$language]];
        }
        $document = $this->documentId ? $editor->update($case->documents()->findOrFail($this->documentId), $contents, auth()->user())
            : $editor->create($case, $contents, auth()->user());
        $this->documentId = $document->id;
        $this->expectedStatus = $case->fresh()->status->value;
        $this->notice = 'حُفظت المسودة.';
    }

    public function submit(RecommendationsEditor $editor): void
    {
        $this->save($editor);
        try {
            $editor->submitForApproval(Document::query()->findOrFail($this->documentId), auth()->user());
            $this->expectedStatus = CaseStatus::RecommendationsAwaitingApprovals->value;
            $this->notice = 'أُرسلت التوصيات إلى أعضاء اللجنة للموافقة.';
        } catch (TransitionBlocked|IssuanceBlocked $error) {
            $this->expectedStatus = CaseVisibility::for(auth()->user())->findOrFail($this->caseId)->status->value;
            $this->notice = $error->getMessage();
        }
    }

    public function render(): View
    {
        $case = CaseVisibility::for(auth()->user())->findOrFail($this->caseId);
        Gate::authorize('manageDocuments', $case);

        return view('livewire.cases.edit-recommendations', ['case' => $case, 'units' => $this->citableUnits($case),
            'aiAvailable' => $this->canRequestAi($case), 'aiBusy' => $case->status === $this->aiBusyStage() && $case->ai_request_id !== null,
            'aiFailed' => $this->aiFailed($case),
            'languages' => array_unique(array_merge(['ar', 'en'], $case->company->enabled_languages)),
            'document' => $this->documentId ? $case->documents()->findOrFail($this->documentId) : null])->layout('layouts.workspace');
    }

    protected function aiDocumentType(): DocumentType
    {
        return DocumentType::Recommendations;
    }

    protected function loadAiBodies(Document $document): void
    {
        $this->findings = $document->contents->pluck('body', 'language_code')->all();
        $this->recommendations = $document->contents->pluck('secondary_body', 'language_code')->all();
    }

    private function editable(): DisciplinaryCase
    {
        $case = CaseVisibility::for(auth()->user())->findOrFail($this->caseId);
        Gate::authorize('manageDocuments', $case);
        if ($case->status->value !== $this->expectedStatus) {
            throw ValidationException::withMessages(['notice' => 'تغيرت الحالة، أعد التحميل']);
        }

        return $case;
    }
}

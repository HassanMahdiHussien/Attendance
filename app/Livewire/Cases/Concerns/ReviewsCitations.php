<?php

namespace App\Livewire\Cases\Concerns;

use App\Livewire\Cases\ConfirmDocument;
use App\Models\Document;
use App\Services\Documents\CitationOverride;
use App\Services\Documents\CitationRefresh;
use App\Services\Documents\RelevanceCheck;
use App\Support\Access\CaseVisibility;
use Illuminate\Support\Facades\Gate;

trait ReviewsCitations
{
    public array $overrideReasons = [];

    protected function citationDocument(bool $writable = true): Document
    {
        $case = CaseVisibility::for(auth()->user())->findOrFail($this->caseId);
        Gate::authorize($this instanceof ConfirmDocument ? 'confirm' : 'manageDocuments', $case);
        $document = $case->documents()->findOrFail($this->documentId);
        if (method_exists($this, 'aiDocumentType')) {
            abort_unless($document->document_type === $this->aiDocumentType(), 404);
        }
        abort_if($writable && $document->is_locked, 403);

        return $document;
    }

    public function recheckCitations(RelevanceCheck $checks): void
    {
        $checks->clear($this->citationDocument());
        $this->notice = __('messages.citation.recheck_requested');
    }

    public function overrideCitation(int $citationId, CitationOverride $overrides): void
    {
        $document = $this->citationDocument();
        $citation = $document->citations()->findOrFail($citationId);
        $this->validate(['overrideReasons.'.$citationId => ['required', 'string', 'max:1000']]);
        $overrides->override($citation, auth()->user(), $this->overrideReasons[$citationId]);
        unset($this->overrideReasons[$citationId]);
        $this->notice = __('messages.citation.override_reason_recorded');
    }

    public function replaceCitation(int $unitId, CitationRefresh $refresh): void
    {
        $document = $this->citationDocument();
        $citation = $document->citations()->where('legal_unit_id', $unitId)->firstOrFail();
        $refresh->replace($document, $citation->legalUnit, auth()->user());
        $this->expectedStatus = $document->disciplinaryCase->fresh()->status->value;
        if (method_exists($this, 'loadAiBodies')) {
            $this->loadAiBodies($document->fresh());
        }
        $this->notice = __('messages.citation.corrected_text_replaced');
    }
}

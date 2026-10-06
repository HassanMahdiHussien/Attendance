<?php

namespace App\Livewire\Cases;

use App\Enums\CaseStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Services\Cases\PoolEligibility;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class PendingApprovals extends Component
{
    public function render(): View
    {
        $eligibility = app(PoolEligibility::class);
        $documents = Document::query()->where('document_type', DocumentType::Recommendations->value)
            ->where('is_locked', false)->where(fn ($query) => $query
            ->whereHas('disciplinaryCase', fn ($case) => $case->where('status', CaseStatus::RecommendationsAwaitingApprovals->value))
            ->orWhere(fn ($correction) => $correction->where('version', '>', 1)->where('status', 'ready_for_review')))->get()
            ->filter(fn (Document $document) => $eligibility->forDocument($document)
                ->contains(fn (array $row) => $row['user']->id === auth()->id()));

        return view('livewire.cases.pending-approvals', compact('documents'))->layout('layouts.workspace');
    }
}

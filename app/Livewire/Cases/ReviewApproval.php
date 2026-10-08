<?php

namespace App\Livewire\Cases;

use App\Enums\PoolRole;
use App\Exceptions\TransitionBlocked;
use App\Models\CommitteeMandate;
use App\Models\Document;
use App\Services\Cases\DocumentApprovalService;
use App\Services\Cases\PoolEligibility;
use App\Support\Access\CaseVisibility;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class ReviewApproval extends Component
{
    public int $documentId;

    public string $expectedStatus = '';

    public string $category = '';

    public string $comment = '';

    public string $notice = '';

    public function mount(string $document): void
    {
        $draft = Document::query()->findOrFail($document);
        $eligible = app(PoolEligibility::class)->forDocument($draft)->filter(fn (array $row) => $row['user']->id === auth()->id());
        abort_unless($this->canView($draft), 404);
        $this->documentId = $draft->id;
        $this->expectedStatus = $draft->disciplinaryCase->status->value;
        if ($eligible->pluck('category')->unique()->count() === 1) {
            $this->category = $eligible->first()['category']->value;
        }
    }

    public function approve(DocumentApprovalService $approvals): void
    {
        $draft = $this->currentDocument();
        $this->validate(['category' => ['required', 'in:legal,hr,labor_union']]);
        try {
            $approvals->approve($draft, auth()->user(), PoolRole::from($this->category));
            session()->flash('committee_notice', __('messages.approval.recorded'));
            $this->redirect('/committee', navigate: true);
        } catch (TransitionBlocked $error) {
            $this->notice = $error->getMessage();
        }
    }

    public function reject(DocumentApprovalService $approvals): void
    {
        $draft = $this->currentDocument();
        $this->validate(['category' => ['required', 'in:legal,hr,labor_union'],
            'comment' => ['required', 'string', 'max:2000']]);
        try {
            $approvals->reject($draft, auth()->user(), $this->comment, PoolRole::from($this->category));
            session()->flash('committee_notice', __('messages.approval.rejected'));
            $this->redirect('/committee', navigate: true);
        } catch (TransitionBlocked $error) {
            $this->notice = $error->getMessage();
        }
    }

    public function render(): View
    {
        $draft = $this->currentDocument(false);
        $eligible = app(PoolEligibility::class)->forDocument($draft)
            ->filter(fn (array $row) => $row['user']->id === auth()->id());

        return view('livewire.cases.review-approval', ['document' => $draft->load('contents'),
            'categories' => $eligible->pluck('category')->unique()])->layout('layouts.workspace');
    }

    private function currentDocument(bool $action = true): Document
    {
        $draft = Document::query()->findOrFail($this->documentId);
        if (! $this->canView($draft) || ($action && ! app(PoolEligibility::class)->forDocument($draft)
            ->contains(fn (array $row) => $row['user']->id === auth()->id()))) {
            abort(404);
        }
        if ($draft->disciplinaryCase->status->value !== $this->expectedStatus) {
            throw ValidationException::withMessages(['notice' => __('messages.case.changed_reload')]);
        }

        return $draft;
    }

    private function canView(Document $draft): bool
    {
        $case = $draft->disciplinaryCase;
        $decided = $draft->approvals()->where('approver_user_id', auth()->id())->whereNull('invalidated_at')->exists();
        if ((! CaseVisibility::for(auth()->user())->whereKey($case->id)->exists() && ! $decided)
            || auth()->user()->company_id !== $case->company_id || ! auth()->user()->is_active
            || ! $case->defense_letter_issued_at) {
            return false;
        }
        $mandate = CommitteeMandate::query()->where('company_id', $case->company_id)
            ->inEffectOn($case->defense_letter_issued_at)->orderByDesc('effective_from')->first();

        return $mandate?->poolMembers()->where('user_id', auth()->id())
            ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $case->branch_id))->exists() ?? false;
    }
}

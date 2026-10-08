<?php

namespace App\Livewire\Cases;

use App\Enums\CaseStatus;
use App\Enums\UserRole;
use App\Exceptions\TransitionBlocked;
use App\Models\Attachment;
use App\Services\Cases\ConsultationService;
use App\Support\Access\CaseVisibility;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class ReviewConsultation extends Component
{
    public int $caseId;

    public string $expectedStatus = '';

    public string $note = '';

    public string $notice = '';

    public function mount(string $case): void
    {
        abort_unless(auth()->user()->primary_role === UserRole::ExternalLegalReviewer, 404);
        $record = CaseVisibility::for(auth()->user())->findOrFail($case);
        abort_unless($record->legal_review_requested_at && ! $record->legal_review_cancelled_at, 404);
        $this->caseId = $record->id;
        $this->expectedStatus = $record->status->value;
    }

    public function complete(ConsultationService $consultations): void
    {
        $case = CaseVisibility::for(auth()->user())->findOrFail($this->caseId);
        if ($case->status->value !== $this->expectedStatus || $case->status === CaseStatus::ArchivedFinal) {
            throw ValidationException::withMessages(['notice' => __('messages.case.changed_reload')]);
        }
        $this->validate(['note' => ['required', 'string', 'max:50000']]);
        try {
            $consultations->complete($case, auth()->user(), $this->note);
            $this->expectedStatus = CaseVisibility::for(auth()->user())->findOrFail($this->caseId)->status->value;
            $this->notice = __('messages.consultation.recorded');
        } catch (TransitionBlocked $error) {
            $this->notice = $error->getMessage();
        }
    }

    public function render(): View
    {
        $case = CaseVisibility::for(auth()->user())->findOrFail($this->caseId);
        app(TenantContext::class)->setCompany($case->company);
        $case->load(['documents.contents', 'events']);
        // The case was authorised through CaseVisibility; with the context on its company the normal
        // tenant scope returns exactly that company's records, so no scope bypass is needed here.
        $case->load('employee');

        return view('livewire.cases.review-consultation', ['case' => $case,
            'attachments' => Attachment::query()->where(function ($query) use ($case): void {
                $query->where(fn ($q) => $q->where('attachable_type', $case->getMorphClass())->where('attachable_id', $case->id))
                    ->orWhere(fn ($q) => $q->where('attachable_type', 'document')->whereIn('attachable_id', $case->documents->pluck('id')));
            })->get()])->layout('layouts.workspace');
    }
}

<?php

namespace App\Services\Cases;

use App\Enums\CaseStatus;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Exceptions\TransitionBlocked;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DefenseLetterFlow
{
    public function __construct(private CaseWorkflow $workflow, private CaseDocumentIssuance $issuance) {}

    public function confirmAndIssue(Document $draft, User $actor, ?string $manualRef = null): Document
    {
        return DB::transaction(function () use ($draft, $actor, $manualRef): Document {
            $case = DisciplinaryCase::query()->lockForUpdate()->findOrFail($draft->case_id);
            Gate::forUser($actor)->authorize('confirm', $case);
            $document = Document::query()->where('case_id', $case->id)->findOrFail($draft->id);
            if ($document->document_type !== DocumentType::DefenseLetter || $document->is_locked
                || $document->status !== DocumentStatus::ReadyForReview) {
                throw new TransitionBlocked('كتاب الدفاع غير متاح.');
            }
            if ($document->version === 1) {
                if ($case->status !== CaseStatus::DefenseLetterReadyForReview) {
                    throw new TransitionBlocked('تغيرت الحالة، أعد التحميل.');
                }
                $case = $this->workflow->transition($case, CaseStatus::DefenseLetterConfirmed, $actor, 'HR confirmed defense letter');
            }

            return $this->issuance->issue($document, $actor, $manualRef);
        });
    }
}

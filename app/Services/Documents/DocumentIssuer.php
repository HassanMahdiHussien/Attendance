<?php

namespace App\Services\Documents;

use App\Enums\ApprovalDecision;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\PenaltyType;
use App\Enums\PoolRole;
use App\Enums\SequenceKey;
use App\Exceptions\IssuanceBlocked;
use App\Models\Document;
use App\Models\User;
use App\Services\CaseEventLogger;
use App\Services\SequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DocumentIssuer
{
    public function __construct(private SequenceService $sequences, private CaseEventLogger $events) {}

    public function issue(Document $draft, User $actor, ?string $manualReference = null): Document
    {
        return DB::transaction(function () use ($draft, $actor, $manualReference): Document {
            $document = Document::query()->lockForUpdate()->findOrFail($draft->id);
            if ($document->is_locked || ! in_array($document->status, [DocumentStatus::Draft, DocumentStatus::ReadyForReview], true)) {
                throw new IssuanceBlocked('Only a draft or ready document can be issued.');
            }
            if ($actor->company_id !== $document->company_id) {
                throw new IssuanceBlocked('The issuer must belong to the document company.');
            }
            if ($document->document_type === DocumentType::DecisionLetter) {
                $case = $document->disciplinaryCase;
                if ($case->legal_review_requested_at !== null && $case->legal_review_completed_at === null) {
                    throw new IssuanceBlocked('The requested consultation must be completed.');
                }
                if ($document->penalty_type === PenaltyType::Termination
                    && ($document->leave_confirmed_by === null || $document->leave_confirmed_at === null)) {
                    throw new IssuanceBlocked('HR leave confirmation is required for termination.');
                }
            }

            $company = $document->company;
            foreach ($company->enabled_languages as $language) {
                if (blank($document->contents()->where('language_code', $language)->value('body'))) {
                    throw new IssuanceBlocked("Missing content for language {$language}.");
                }
            }
            if ($company->requires_second_review && ($document->second_reviewed_by === null
                || $document->second_reviewed_at === null || (int) $document->second_reviewed_by === (int) $actor->id)) {
                throw new IssuanceBlocked('A completed review by another user is required.');
            }
            if ($document->document_type === DocumentType::Recommendations) {
                foreach ([PoolRole::Legal, PoolRole::Hr, PoolRole::LaborUnion] as $role) {
                    if (! $document->approvals()->where('role_category', $role->value)
                        ->where('decision', ApprovalDecision::Approved->value)
                        ->whereNull('invalidated_at')->exists()) {
                        throw new IssuanceBlocked("A valid {$role->value} approval is required.");
                    }
                }
            }

            $year = (int) now()->year;
            $manual = $manualReference !== null;
            if ($manual) {
                $identity = ['company_id' => $document->company_id,
                    'sequence_key' => SequenceKey::DocumentReference->value, 'year' => $year];
                DB::table('company_sequences')->insertOrIgnore($identity + [
                    'next_value' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('company_sequences')->where($identity)->lockForUpdate()->first();
                $reference = trim($manualReference);
                if ($reference === '') {
                    throw ValidationException::withMessages(['reference_number' => 'Reference number is required.']);
                }
                if ($this->referenceTaken($document, $year, $reference)) {
                    throw ValidationException::withMessages(['reference_number' => 'Reference number is already used this year.']);
                }
            } else {
                do {
                    $reference = (string) $this->sequences->next((int) $document->company_id, SequenceKey::DocumentReference, $year);
                } while ($this->referenceTaken($document, $year, $reference));
            }

            $document->forceFill([
                'reference_number' => $reference,
                'reference_year' => $year,
                'reference_is_manual' => $manual,
                'issued_at' => now(),
                'issued_by' => $actor->id,
                'status' => DocumentStatus::Issued,
                'is_locked' => true,
            ])->save();

            if ($document->previous_version_id !== null) {
                $previous = Document::query()->lockForUpdate()->findOrFail($document->previous_version_id);
                if ($previous->status !== DocumentStatus::Issued || $previous->case_id !== $document->case_id
                    || $previous->document_type !== $document->document_type) {
                    throw new IssuanceBlocked('The previous version is not the current document for this case and type.');
                }
                $previous->forceFill([
                    'status' => DocumentStatus::Superseded,
                    'superseded_by_id' => $document->id,
                ])->save();
            }

            $this->events->log($document->disciplinaryCase, 'document_issued', [
                'document_type' => $document->document_type->value,
                'version' => $document->version,
                'reference_number' => $reference,
            ], actor: $actor);

            return $document;
        });
    }

    public function previewReference(Document $draft): string
    {
        $year = (int) now()->year;
        $next = (int) (DB::table('company_sequences')->where([
            'company_id' => $draft->company_id,
            'sequence_key' => SequenceKey::DocumentReference->value,
            'year' => $year,
        ])->value('next_value') ?? 1);

        while ($this->referenceTaken($draft, $year, (string) $next)) {
            $next++;
        }

        return (string) $next;
    }

    private function referenceTaken(Document $document, int $year, string $reference): bool
    {
        return DB::table('documents')->where('company_id', $document->company_id)
            ->where('reference_year', $year)->where('reference_number', $reference)->exists();
    }
}

<?php

namespace App\Services\Documents;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\DraftedBy;
use App\Exceptions\IssuanceBlocked;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\User;
use App\Services\CaseEventLogger;
use App\Services\Cases\RecommendationsEditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DocumentCorrector
{
    public function __construct(private CaseEventLogger $events) {}

    public function startCorrection(Document $issued, string $reason, User $actor): Document
    {
        return DB::transaction(function () use ($issued, $reason, $actor): Document {
            $case = DisciplinaryCase::query()->lockForUpdate()->findOrFail($issued->case_id);
            Gate::forUser($actor)->authorize('correct', $case);
            $source = Document::query()->lockForUpdate()->findOrFail($issued->id);
            if ($source->status !== DocumentStatus::Issued || ! $source->is_locked) {
                throw new IssuanceBlocked('Only a currently issued document can be corrected.');
            }
            if ($actor->company_id !== $source->company_id) {
                throw new IssuanceBlocked('The correcting user must belong to the document company.');
            }
            if (blank($reason)) {
                throw ValidationException::withMessages(['correction_reason' => 'A correction reason is required.']);
            }
            if (Document::query()->where('case_id', $case->id)->where('document_type', $source->document_type->value)
                ->whereIn('status', [DocumentStatus::Draft->value, DocumentStatus::ReadyForReview->value])->exists()) {
                throw new IssuanceBlocked('An open draft already exists for this case and document type.');
            }

            $correction = Document::create([
                'company_id' => $source->company_id,
                'case_id' => $source->case_id,
                'document_type' => $source->document_type,
                'penalty_type' => $source->penalty_type,
                'drafted_by' => DraftedBy::Human,
                'status' => DocumentStatus::Draft,
                'version' => $source->version + 1,
                'previous_version_id' => $source->id,
                'correction_reason' => $reason,
                'created_by' => $actor->id,
                'last_edited_by' => $actor->id,
            ]);
            if ($source->document_type === DocumentType::Recommendations) {
                app(RecommendationsEditor::class)->update($correction, $source->contents->mapWithKeys(
                    fn ($content) => [$content->language_code => ['body' => $content->body,
                        'secondary_body' => $content->secondary_body]])->all(), $actor);
            } else {
                foreach ($source->contents as $content) {
                    $correction->contents()->create([
                        'company_id' => $source->company_id,
                        'language_code' => $content->language_code,
                        'body' => $content->body,
                        'secondary_body' => $content->secondary_body,
                        'is_authoritative' => $content->is_authoritative,
                    ]);
                }
            }
            $this->events->log($case, 'document_correction_started', [
                'from_version' => $source->version,
                'reason' => $reason,
            ], actor: $actor);

            return $correction;
        });
    }
}

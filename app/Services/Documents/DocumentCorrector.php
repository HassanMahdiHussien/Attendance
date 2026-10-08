<?php

namespace App\Services\Documents;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\DraftedBy;
use App\Exceptions\IssuanceBlocked;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\LegalUnit;
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
                throw new IssuanceBlocked('لا يمكن تصحيح إلا المستند الصادر الحالي.');
            }
            if ($actor->company_id !== $source->company_id) {
                throw new IssuanceBlocked('يجب أن ينتمي المستخدم المصحح إلى شركة المستند.');
            }
            if (blank($reason)) {
                throw ValidationException::withMessages(['correction_reason' => 'يلزم بيان سبب التصحيح.']);
            }
            if (Document::query()->where('case_id', $case->id)->where('document_type', $source->document_type->value)
                ->whereIn('status', [DocumentStatus::Draft->value, DocumentStatus::ReadyForReview->value])->exists()) {
                throw new IssuanceBlocked('توجد مسودة مفتوحة لهذا النوع من المستندات في الحالة.');
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
            $markerIds = $source->contents->flatMap(fn ($content) => collect(CitationSync::claims(
                $content->body."\n".($content->secondary_body ?? '')))->pluck('legal_unit_id'))->unique()->all();
            $canSync = ! $markerIds || LegalUnit::query()->citableFor($case)->whereKey($markerIds)->count() === count($markerIds);
            if ($source->document_type === DocumentType::Recommendations) {
                app(RecommendationsEditor::class)->update($correction, $source->contents->mapWithKeys(
                    fn ($content) => [$content->language_code => ['body' => $content->body,
                        'secondary_body' => $content->secondary_body]])->all(), $actor, DraftedBy::Human, $canSync);
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
                if ($canSync) {
                    app(CitationSync::class)->sync($correction);
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

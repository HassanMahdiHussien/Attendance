<?php

namespace App\Services\Documents;

use App\Enums\DocumentStatus;
use App\Exceptions\IssuanceBlocked;
use App\Models\Document;
use App\Models\User;
use App\Services\CaseEventLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SecondReview
{
    public function __construct(private CaseEventLogger $events) {}

    public function mark(Document $draft, User $reviewer): Document
    {
        return DB::transaction(function () use ($draft, $reviewer): Document {
            $document = Document::query()->lockForUpdate()->findOrFail($draft->id);
            Gate::forUser($reviewer)->authorize('secondReview', $document->disciplinaryCase);
            if ($document->is_locked || $document->status !== DocumentStatus::ReadyForReview
                || (int) $document->last_edited_by === (int) $reviewer->id) {
                throw new IssuanceBlocked(__('messages.document.second_reviewer_must_differ'));
            }
            $document->forceFill(['second_reviewed_by' => $reviewer->id, 'second_reviewed_at' => now()])->save();
            $this->events->log($document->disciplinaryCase, 'document_second_reviewed', ['document_id' => $document->id], actor: $reviewer);

            return $document;
        });
    }
}

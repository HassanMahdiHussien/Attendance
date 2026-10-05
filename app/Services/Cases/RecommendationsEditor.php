<?php

namespace App\Services\Cases;

use App\Enums\CaseStatus;
use App\Enums\DocumentType;
use App\Enums\UserRole;
use App\Exceptions\TransitionBlocked;
use App\Models\Document;
use App\Models\DocumentContent;
use App\Models\User;
use App\Services\CaseEventLogger;
use App\Support\Access\CaseVisibility;
use Illuminate\Support\Facades\DB;

class RecommendationsEditor
{
    private static bool $writing = false;

    public function __construct(private CaseWorkflow $workflow, private CaseEventLogger $events) {}

    public static function isWriting(): bool
    {
        return self::$writing;
    }

    public function update(Document $draft, array $contents, User $hrUser): Document
    {
        return DB::transaction(function () use ($draft, $contents, $hrUser): Document {
            $document = Document::query()->lockForUpdate()->findOrFail($draft->id);
            $case = $document->disciplinaryCase;
            if ($document->document_type !== DocumentType::Recommendations || $document->is_locked
                || ! in_array($hrUser->primary_role, [UserRole::CompanyAdmin, UserRole::HrStaff], true)
                || ! CaseVisibility::for($hrUser)->whereKey($case->id)->exists()) {
                throw new TransitionBlocked('HR access to a recommendations draft is required.');
            }
            if (array_is_list($contents)) {
                $contents = collect($contents)->pluck('body', 'language_code')->all();
            }
            if (count($contents) !== count(array_unique(array_keys($contents))) || in_array('', $contents, true)) {
                throw new TransitionBlocked('Content must have unique languages and nonempty text.');
            }
            self::$writing = true;
            try {
                foreach ($contents as $language => $body) {
                    DocumentContent::updateOrCreate(
                        ['document_id' => $document->id, 'language_code' => $language],
                        ['company_id' => $document->company_id, 'body' => $body, 'is_authoritative' => $language === 'ar'],
                    );
                }
                $document->contents()->whereNotIn('language_code', array_keys($contents))->get()->each->delete();
            } finally {
                self::$writing = false;
            }
            $document->approvals()->whereNull('invalidated_at')->get()->each(function ($approval): void {
                $approval->invalidated_at = now();
                $approval->save();
            });
            $this->events->log($case, 'approvals_invalidated', ['document_id' => $document->id], actor: $hrUser);
            if (in_array($case->status, [CaseStatus::RecommendationsAwaitingApprovals, CaseStatus::RecommendationsConfirmed], true)) {
                $this->workflow->transition($case, CaseStatus::RecommendationsCitationCheck, $hrUser, 'Recommendations content edited');
            }

            return $document->refresh();
        });
    }
}

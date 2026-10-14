<?php

namespace App\LegalLibrary;

use App\Models\LegalUnit;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UnitCorrection
{
    public function __construct(private AuditLogger $audit) {}

    public function correct(LegalUnit $unit, User $actor, string $text, string $reason): LegalUnit
    {
        abort_unless($actor->can('review', $unit->law), 403);
        if (! in_array($unit->status->value, ['verified_auto', 'verified_human', 'verified_text', 'verified_single'], true)
            || $unit->superseded_by_id !== null || blank($text) || blank($reason)) {
            throw ValidationException::withMessages(['unit' => __('messages.legal.correction_required')]);
        }

        return DB::transaction(function () use ($unit, $actor, $text, $reason): LegalUnit {
            $replacement = LegalUnit::create(['law_id' => $unit->law_id, 'company_id' => $unit->company_id,
                'import_run_id' => $unit->import_run_id, 'version' => $unit->version + 1,
                'previous_version_id' => $unit->id, 'article_number' => $unit->article_number,
                'clause_path' => $unit->clause_path, 'official_text' => $text, 'source_page' => $unit->source_page,
                'extraction_a' => $unit->extraction_a, 'extraction_b' => $unit->extraction_b,
                'status' => 'verified_human', 'verified_by' => $actor->id, 'verified_at' => now(),
                'tags' => [...($unit->tags ?? []), 'correction_reason' => $reason]]);
            $unit->update(['superseded_by_id' => $replacement->id]);
            foreach ($unit->topics as $topic) {
                $replacement->topics()->create(['topic' => $topic->topic,
                    'created_by' => $actor->id, 'created_at' => now()]);
            }
            $this->audit->log('legal_unit.corrected', $replacement, ['previous_id' => $unit->id,
                'old_text' => $unit->official_text, 'new_text' => $text, 'reason' => $reason], $actor);

            return $replacement;
        });
    }
}

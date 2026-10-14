<?php

namespace App\LegalLibrary;

use App\Models\LegalUnit;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MismatchResolver
{
    public function __construct(private AuditLogger $audit) {}

    public function resolve(LegalUnit $unit, User $actor, string $choice, ?string $text = null): LegalUnit
    {
        abort_unless($actor->can('review', $unit->law), 403);
        if ($unit->status->value !== 'mismatch' || ! in_array($choice, ['a', 'b', 'edit'], true)) {
            throw ValidationException::withMessages(['unit' => __('messages.legal.mismatch_choice_required')]);
        }
        if ($unit->law->kind->value === 'statute' && $unit->source_page === null) {
            throw ValidationException::withMessages(['unit' => __('messages.legal.official_page_required')]);
        }
        $chosen = match ($choice) {
            'a' => $unit->extraction_a,
            'b' => $unit->extraction_b,
            'edit' => $text,
        };
        if (blank($chosen)) {
            throw ValidationException::withMessages(['text' => __('messages.legal.verified_text_required')]);
        }

        return DB::transaction(function () use ($unit, $actor, $choice, $chosen): LegalUnit {
            $unit->update(['official_text' => $chosen, 'status' => 'verified_human',
                'verified_by' => $actor->id, 'verified_at' => now()]);
            if ($unit->previous_version_id !== null) {
                $old = LegalUnit::findOrFail($unit->previous_version_id);
                $old->update(['superseded_by_id' => $unit->id]);
            }
            $this->audit->log('legal_unit.verified', $unit, ['choice' => $choice,
                'extraction_a' => $unit->extraction_a, 'extraction_b' => $unit->extraction_b,
                'official_text' => $chosen], $actor);

            return $unit;
        });
    }

    public function exclude(LegalUnit $unit, User $actor, string $reason): void
    {
        abort_unless($actor->can('review', $unit->law), 403);
        if (! in_array($unit->status->value, ['mismatch', 'unverified'], true) || blank($reason)) {
            throw ValidationException::withMessages(['reason' => __('messages.legal.exclusion_reason_required')]);
        }
        DB::transaction(function () use ($unit, $actor, $reason): void {
            $unit->update(['status' => 'excluded', 'tags' => [...($unit->tags ?? []), 'excluded_reason' => $reason]]);
            $this->audit->log('legal_unit.excluded', $unit, ['reason' => $reason,
                'extraction_a' => $unit->extraction_a, 'extraction_b' => $unit->extraction_b], $actor);
        });
    }
}

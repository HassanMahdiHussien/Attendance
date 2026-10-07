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
            throw ValidationException::withMessages(['unit' => 'Choose a mismatch reading or edit it.']);
        }
        $chosen = match ($choice) {
            'a' => $unit->extraction_a,
            'b' => $unit->extraction_b,
            'edit' => $text,
        };
        if (blank($chosen)) {
            throw ValidationException::withMessages(['text' => 'Verified text is required.']);
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
        if ($unit->status->value !== 'mismatch' || blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A mismatch and reason are required.']);
        }
        DB::transaction(function () use ($unit, $actor, $reason): void {
            $unit->update(['status' => 'excluded', 'tags' => [...($unit->tags ?? []), 'excluded_reason' => $reason]]);
            $this->audit->log('legal_unit.excluded', $unit, ['reason' => $reason,
                'extraction_a' => $unit->extraction_a, 'extraction_b' => $unit->extraction_b], $actor);
        });
    }
}

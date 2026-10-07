<?php

namespace App\LegalLibrary;

use App\Models\Law;
use App\Models\LegalUnit;
use App\Models\LegalUnitApproval;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegulationApprovals
{
    public function __construct(private AuditLogger $audit) {}

    public function approve(LegalUnit $unit, User $actor): LegalUnitApproval
    {
        abort_unless($actor->can('review', $unit->law), 403);
        if ($unit->law->kind->value !== 'internal_regulation'
            || ! in_array($unit->status->value, ['verified_auto', 'verified_human', 'verified_text'], true)
            || $unit->superseded_by_id !== null) {
            throw ValidationException::withMessages(['unit' => 'This regulation unit cannot be approved.']);
        }

        return DB::transaction(function () use ($unit, $actor): LegalUnitApproval {
            $approval = LegalUnitApproval::firstOrCreate(['legal_unit_id' => $unit->id],
                ['company_id' => $unit->company_id, 'approved_by' => $actor->id, 'created_at' => now()]);
            if ($approval->wasRecentlyCreated) {
                $this->audit->log('legal_unit.approved', $unit, ['approval_id' => $approval->id], $actor);
            }

            return $approval;
        });
    }

    public function approveAllVerified(Law $law, User $actor): int
    {
        abort_unless($actor->can('review', $law), 403);
        $count = 0;
        foreach ($law->units()->whereIn('status', ['verified_auto', 'verified_human', 'verified_text'])
            ->whereNull('superseded_by_id')->cursor() as $unit) {
            $count += (int) $this->approve($unit, $actor)->wasRecentlyCreated;
        }

        return $count;
    }
}

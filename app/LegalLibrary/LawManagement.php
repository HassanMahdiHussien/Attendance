<?php

namespace App\LegalLibrary;

use App\Enums\Jurisdiction;
use App\Enums\LawKind;
use App\Enums\UserRole;
use App\Models\Law;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class LawManagement
{
    public function __construct(private AuditLogger $audit) {}

    public function create(User $actor, array $attributes): Law
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $attributes): Law {
            $law = Law::create([...$attributes, 'company_id' => null, 'kind' => LawKind::Statute]);
            $this->audit->log('law.created', $law, $attributes, $actor);

            return $law;
        });
    }

    public function update(Law $law, User $actor, array $attributes): Law
    {
        $this->authorize($actor);
        abort_unless($law->kind === LawKind::Statute, 403);

        return DB::transaction(function () use ($law, $actor, $attributes): Law {
            $law->update(collect($attributes)->except(['company_id', 'kind', 'id'])->all());
            $this->audit->log('law.updated', $law, $attributes, $actor);

            return $law;
        });
    }

    public function setJurisdictions(Law $law, User $actor, array $jurisdictions): void
    {
        $this->authorize($actor);
        abort_unless($law->kind === LawKind::Statute, 403);
        DB::transaction(function () use ($law, $actor, $jurisdictions): void {
            $law->jurisdictions()->delete();
            foreach (array_unique($jurisdictions) as $jurisdiction) {
                $law->jurisdictions()->create(['jurisdiction' => $jurisdiction instanceof Jurisdiction
                    ? $jurisdiction : Jurisdiction::from($jurisdiction)]);
            }
            $this->audit->log('law.jurisdictions_changed', $law, ['jurisdictions' => $jurisdictions], $actor);
        });
    }

    public function repeal(Law $law, User $actor, string $date): void
    {
        $this->authorize($actor);
        abort_unless($law->kind === LawKind::Statute, 403);
        DB::transaction(function () use ($law, $actor, $date): void {
            $law->update(['repealed_at' => $date]);
            $this->audit->log('law.repealed', $law, ['repealed_at' => $date], $actor);
        });
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->is_active && $actor->primary_role === UserRole::PlatformAdmin, 403);
    }
}

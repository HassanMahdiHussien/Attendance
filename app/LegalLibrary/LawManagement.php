<?php

namespace App\LegalLibrary;

use App\Enums\Jurisdiction;
use App\Enums\LawKind;
use App\Enums\UserRole;
use App\Models\Law;
use App\Models\LegalUnit;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

    public function setUnitTopics(LegalUnit $unit, User $actor, array $topics): void
    {
        $this->authorize($actor);
        abort_unless($unit->law->kind === LawKind::Statute && $unit->company_id === null && $unit->superseded_by_id === null, 403);
        $topics = array_values(array_unique(array_map('trim', $topics)));
        if (collect($topics)->contains(fn ($topic) => $topic === '' || mb_strlen($topic) > 80)) {
            throw ValidationException::withMessages(['topics' => __('messages.legal.topics_invalid')]);
        }
        DB::transaction(function () use ($unit, $actor, $topics): void {
            $before = $unit->topics()->pluck('topic')->all();
            $unit->topics()->whereNotIn('topic', $topics)->delete();
            foreach ($topics as $topic) {
                $unit->topics()->firstOrCreate(['topic' => $topic], ['created_by' => $actor->id, 'created_at' => now()]);
            }
            $this->audit->log('legal_unit.topics_changed', $unit, ['before' => $before, 'after' => $topics], $actor);
        });
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->is_active && $actor->primary_role === UserRole::PlatformAdmin, 403);
    }
}

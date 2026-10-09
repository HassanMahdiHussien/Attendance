<?php

namespace App\Models;

use App\Exceptions\ImmutableRecordException;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RegulationComparison extends Model
{
    use BelongsToCompany, HasFactory;

    protected $guarded = [];

    protected array $companyParents = ['disciplinaryCase'];

    protected array $companyUserKeys = ['confirmed_by'];

    protected static function booted(): void
    {
        static::updating(function (self $comparison): void {
            if (in_array($comparison->getRawOriginal('status'), ['confirmed', 'skipped'], true)
                && (array_keys($comparison->getDirty()) !== ['consumed_at'] || $comparison->getRawOriginal('consumed_at') !== null)) {
                throw new ImmutableRecordException('Confirmed comparisons are immutable.');
            }
        });
        static::deleting(fn (self $comparison) => in_array($comparison->status, ['confirmed', 'skipped'], true)
            ? throw new ImmutableRecordException('Confirmed comparisons cannot be deleted.') : null);
    }

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime', 'consumed_at' => 'datetime'];
    }

    public function disciplinaryCase(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }

    public function pairs(): HasMany
    {
        return $this->hasMany(RegulationComparisonPair::class, 'comparison_id');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}

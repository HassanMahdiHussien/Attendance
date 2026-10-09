<?php

namespace App\Models;

use App\Ai\RegulationComparisons;
use App\Exceptions\ImmutableRecordException;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegulationComparisonPair extends Model
{
    use BelongsToCompany, HasFactory;

    protected $guarded = [];

    protected array $companyParents = ['comparison'];

    protected static function booted(): void
    {
        static::saving(function (self $pair): void {
            if ($pair->exists && $pair->getRawOriginal('comparison_id') !== $pair->comparison_id) {
                throw new ImmutableRecordException('Comparison pairs cannot move.');
            }
            if (in_array($pair->comparison?->status, ['confirmed', 'skipped'], true)) {
                throw new ImmutableRecordException('Confirmed comparison pairs are immutable.');
            }
            app(RegulationComparisons::class)->validatePair($pair->comparison->disciplinaryCase,
                (int) $pair->law_unit_id, (int) $pair->regulation_unit_id);
        });
        static::deleting(fn (self $pair) => in_array($pair->comparison?->status, ['confirmed', 'skipped'], true)
            ? throw new ImmutableRecordException('Confirmed comparison pairs are immutable.') : null);
    }

    protected function casts(): array
    {
        return ['changed_by_hr' => 'boolean'];
    }

    public function comparison(): BelongsTo
    {
        return $this->belongsTo(RegulationComparison::class, 'comparison_id');
    }

    public function lawUnit(): BelongsTo
    {
        return $this->belongsTo(LegalUnit::class, 'law_unit_id');
    }

    public function regulationUnit(): BelongsTo
    {
        return $this->belongsTo(LegalUnit::class, 'regulation_unit_id');
    }
}

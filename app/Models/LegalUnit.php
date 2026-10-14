<?php

namespace App\Models;

use App\Enums\LegalUnitStatus;
use App\Exceptions\ImmutableRecordException;
use App\Exceptions\TenantMismatch;
use App\Support\Tenancy\LegalLibraryScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LegalUnit extends Model
{
    use HasFactory;

    protected $table = 'legal_units';

    protected $guarded = [];

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::addGlobalScope(new LegalLibraryScope);
        static::saving(function (self $unit): void {
            if ($unit->exists && in_array($unit->getRawOriginal('status'), ['verified_auto', 'verified_human', 'verified_text', 'verified_single'], true)) {
                $dirty = array_keys($unit->getDirty());
                if ($dirty !== ['superseded_by_id'] || $unit->getRawOriginal('superseded_by_id') !== null || $unit->superseded_by_id === null) {
                    throw new ImmutableRecordException('Verified legal units are immutable.');
                }
            }

            $law = Law::withoutGlobalScopes()->find($unit->law_id);
            if ($law !== null) {
                if ($unit->status === LegalUnitStatus::VerifiedSingle
                    && ($law->kind->value !== 'internal_regulation' || $unit->source_page === null
                        || $unit->import_run_id === null || $unit->extraction_b !== null
                        || ! LegalImportPage::withoutGlobalScopes()->where('run_id', $unit->import_run_id)
                            ->where('page_number', $unit->source_page)->where('text_status', 'done')
                            ->where('ai_status', 'skipped')->exists())) {
                    throw new ImmutableRecordException('Single-reading verification requires a regulation source page.');
                }
                $context = app(TenantContext::class);
                if ($law->company_id !== null && (! $context->isSet()
                    || (! $context->isAllCompanies() && (int) $law->company_id !== $context->companyId()))) {
                    throw new TenantMismatch('Legal unit belongs to another company.');
                }
                if ($unit->company_id !== null && (int) $unit->company_id !== (int) $law->company_id) {
                    throw new TenantMismatch('Legal unit belongs to another company.');
                }
                $unit->company_id = $law->company_id;
                if ($unit->import_run_id !== null) {
                    $run = LegalImportRun::withoutGlobalScopes()->findOrFail($unit->import_run_id);
                    if ((int) $run->law_id !== (int) $law->id) {
                        throw new TenantMismatch('Import run belongs to another law.');
                    }
                }
            }
            if ($unit->company_id !== null && $unit->verified_by !== null) {
                $verifier = User::find($unit->verified_by);
                if ($verifier?->company_id !== null && (int) $verifier->company_id !== (int) $unit->company_id) {
                    throw new TenantMismatch('Verifier belongs to another company.');
                }
            }
        });
        static::deleting(function (self $unit): void {
            if (in_array($unit->status, [LegalUnitStatus::VerifiedAuto, LegalUnitStatus::VerifiedHuman, LegalUnitStatus::VerifiedText, LegalUnitStatus::VerifiedSingle], true)) {
                throw new ImmutableRecordException('Verified legal units cannot be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => LegalUnitStatus::class,
            'tags' => 'array',
        ];
    }

    public function law(): BelongsTo
    {
        return $this->belongsTo(Law::class, 'law_id');
    }

    public function topics(): HasMany
    {
        return $this->hasMany(LegalUnitTopic::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function previousVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_version_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function importRun(): BelongsTo
    {
        return $this->belongsTo(LegalImportRun::class, 'import_run_id');
    }

    public function scopeCitableFor(Builder $query, DisciplinaryCase $case): Builder
    {
        $on = $case->created_at?->copy()->timezone('Asia/Baghdad')->toDateString() ?? now('Asia/Baghdad')->toDateString();
        $jurisdiction = $case->jurisdiction_at_opening->value;

        return $query->whereIn('legal_units.status', ['verified_auto', 'verified_human', 'verified_text', 'verified_single'])
            ->whereNull('legal_units.superseded_by_id')
            ->whereHas('law', function (Builder $law) use ($case, $on, $jurisdiction): void {
                $law->where(function (Builder $choice) use ($case, $on, $jurisdiction): void {
                    $choice->where(function (Builder $regulation) use ($case): void {
                        $regulation->where('kind', 'internal_regulation')->where('company_id', $case->company_id)
                            ->whereExists(function ($approval): void {
                                $approval->selectRaw('1')->from('legal_unit_approvals')
                                    ->whereColumn('legal_unit_approvals.legal_unit_id', 'legal_units.id');
                            });
                    })->orWhere(function (Builder $statute) use ($case, $on, $jurisdiction): void {
                        $statute->where('kind', 'statute')->where('legal_units.status', '!=', 'verified_single')
                            ->where('approval_status', 'approved')
                            ->whereExists(function ($jurisdictions) use ($jurisdiction): void {
                                $jurisdictions->selectRaw('1')->from('law_jurisdictions')
                                    ->whereColumn('law_jurisdictions.law_id', 'laws.id')
                                    ->where('jurisdiction', $jurisdiction);
                            })->where(function (Builder $category) use ($case, $on): void {
                                $category->where(function (Builder $labor) use ($case): void {
                                    $labor->where('category', 'labor')->whereKey($case->law_version_id);
                                })->orWhere(function (Builder $other) use ($on): void {
                                    $other->where('category', '!=', 'labor')
                                        ->where(function (Builder $dated) use ($on): void {
                                            $dated->whereNull('effective_from')->orWhereDate('effective_from', '<=', $on);
                                        })->where(function (Builder $dated) use ($on): void {
                                            $dated->whereNull('repealed_at')->orWhereDate('repealed_at', '>', $on);
                                        });
                                });
                            });
                    });
                });
            });
    }
}

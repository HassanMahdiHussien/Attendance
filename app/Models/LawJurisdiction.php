<?php

namespace App\Models;

use App\Enums\Jurisdiction;
use App\Exceptions\MissingTenantContext;
use App\Exceptions\TenantMismatch;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LawJurisdiction extends Model
{
    use HasFactory;

    protected $table = 'law_jurisdictions';

    protected $guarded = [];

    public $timestamps = false;

    public $incrementing = false;

    protected static function booted(): void
    {
        static::addGlobalScope('visible_law', function (Builder $query): void {
            if (! app(TenantContext::class)->isSet()) {
                throw new MissingTenantContext('A company context is required for law jurisdictions.');
            }
            $query->whereHas('law');
        });
        static::saving(function (self $jurisdiction): void {
            $law = Law::withoutGlobalScopes()->find($jurisdiction->law_id);
            $context = app(TenantContext::class);
            if ($law?->company_id !== null && (! $context->isSet()
                || (! $context->isAllCompanies() && (int) $law->company_id !== $context->companyId()))) {
                throw new TenantMismatch('Law jurisdiction belongs to another company.');
            }
        });
    }

    protected function casts(): array
    {
        return ['jurisdiction' => Jurisdiction::class];
    }

    public function law(): BelongsTo
    {
        return $this->belongsTo(Law::class, 'law_id');
    }
}

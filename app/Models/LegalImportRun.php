<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Exceptions\TenantMismatch;
use App\Support\Tenancy\LegalLibraryScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LegalImportRun extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::addGlobalScope(new LegalLibraryScope);
        static::saving(function (self $run): void {
            $law = Law::withoutGlobalScopes()->findOrFail($run->law_id);
            $context = app(TenantContext::class);
            if (! $context->isSet() || ($law->company_id !== null && ! $context->isAllCompanies() && (int) $law->company_id !== $context->companyId())) {
                throw new TenantMismatch('Import run belongs to another company.');
            }
            if ($run->company_id !== null && (int) $run->company_id !== (int) $law->company_id) {
                throw new TenantMismatch('Import run belongs to another company.');
            }
            $starter = User::find($run->started_by);
            if ($starter !== null && $law->company_id !== null
                && (int) $starter->company_id !== (int) $law->company_id
                && $starter->primary_role !== UserRole::PlatformAdmin) {
                throw new TenantMismatch('Import starter belongs to another company.');
            }
            $run->company_id = $law->company_id;
        });
    }

    protected function casts(): array
    {
        return ['counts' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function law(): BelongsTo
    {
        return $this->belongsTo(Law::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(LegalImportPage::class, 'run_id');
    }
}

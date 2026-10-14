<?php

namespace App\Models;

use App\Exceptions\TenantMismatch;
use App\Support\Tenancy\LegalLibraryScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegalImportPage extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::addGlobalScope(new LegalLibraryScope);
        static::saving(function (self $page): void {
            $run = LegalImportRun::withoutGlobalScopes()->findOrFail($page->run_id);
            $context = app(TenantContext::class);
            if (! $context->isSet() || ($run->company_id !== null && ! $context->isAllCompanies() && (int) $run->company_id !== $context->companyId())) {
                throw new TenantMismatch('Import page belongs to another company.');
            }
            if ($page->company_id !== null && (int) $page->company_id !== (int) $run->company_id) {
                throw new TenantMismatch('Import page belongs to another company.');
            }
            $page->company_id = $run->company_id;
        });
    }

    protected function casts(): array
    {
        return ['ai_illegible' => 'boolean', 'text_repairs' => 'array'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(LegalImportRun::class, 'run_id');
    }
}

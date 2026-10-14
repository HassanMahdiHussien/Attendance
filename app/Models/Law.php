<?php

namespace App\Models;

use App\Enums\LawApprovalStatus;
use App\Enums\LawKind;
use App\Exceptions\MissingTenantContext;
use App\Exceptions\TenantMismatch;
use App\Support\Tenancy\LegalLibraryScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Law extends Model
{
    use HasFactory;

    protected $table = 'laws';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::addGlobalScope(new LegalLibraryScope);
        static::saving(function (self $law): void {
            $context = app(TenantContext::class);
            if ($law->kind === LawKind::Statute) {
                if ($law->company_id !== null) {
                    throw new TenantMismatch('Statutes are platform owned.');
                }

                return;
            }
            if (! $context->isSet()) {
                throw new MissingTenantContext('Regulation needs a company context.');
            }
            if ($law->company_id === null) {
                $law->company_id = $context->companyId();
            }
            if ($law->company_id === null || (! $context->isAllCompanies() && (int) $law->company_id !== $context->companyId())) {
                throw new TenantMismatch('Regulation belongs to another company.');
            }
            if ($law->approved_by !== null) {
                $approver = User::find($law->approved_by);
                if ($approver?->company_id !== null && (int) $approver->company_id !== (int) $law->company_id) {
                    throw new TenantMismatch('Approver belongs to another company.');
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'kind' => LawKind::class,
            'approval_status' => LawApprovalStatus::class,
            'requires_page_range' => 'boolean',
            'text_layer_fixes' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function units(): HasMany
    {
        return $this->hasMany(LegalUnit::class);
    }

    public function importRuns(): HasMany
    {
        return $this->hasMany(LegalImportRun::class);
    }

    public function jurisdictions(): HasMany
    {
        return $this->hasMany(LawJurisdiction::class);
    }
}

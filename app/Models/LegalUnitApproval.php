<?php

namespace App\Models;

use App\Enums\LawKind;
use App\Exceptions\ImmutableRecordException;
use App\Exceptions\TenantMismatch;
use App\Models\Concerns\AppendOnly;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegalUnitApproval extends Model
{
    use AppendOnly;

    protected $guarded = [];

    public $timestamps = false;

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::creating(function (self $approval): void {
            $unit = LegalUnit::withoutGlobalScopes()->findOrFail($approval->legal_unit_id);
            $law = Law::withoutGlobalScopes()->findOrFail($unit->law_id);
            $user = User::findOrFail($approval->approved_by);
            $context = app(TenantContext::class);
            if ($law->kind !== LawKind::InternalRegulation || $unit->company_id === null
                || (int) $approval->company_id !== (int) $unit->company_id
                || (int) $user->company_id !== (int) $unit->company_id
                || ! $user->is_active
                || (! $context->isAllCompanies() && $context->companyId() !== (int) $unit->company_id)
                || ! $user->can('review_internal_regulation')) {
                throw new TenantMismatch('Only the company regulation reviewer may approve this unit.');
            }
            $approval->created_at ??= now();
        });
        static::updating(fn () => throw new ImmutableRecordException('Legal unit approval is append-only.'));
        static::deleting(fn () => throw new ImmutableRecordException('Legal unit approval is append-only.'));
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(LegalUnit::class, 'legal_unit_id');
    }
}

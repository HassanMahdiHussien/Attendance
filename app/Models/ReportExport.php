<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Exceptions\TenantMismatch;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportExport extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $query): void {
            $context = app(TenantContext::class);
            if (! $context->isAllCompanies()) {
                $query->where('report_exports.company_id', $context->companyId() ?? -1);
            }
        });
        static::saving(function (self $export): void {
            $context = app(TenantContext::class);
            $owner = User::findOrFail($export->requested_by);
            if (! in_array($export->status, ['queued', 'ready', 'failed', 'expired', 'revoked'], true) ||
                ! in_array($export->format, ['pdf', 'xlsx'], true) ||
                ! in_array($export->report_type, ['periodic', 'employee', 'compliance', 'hotspots'], true)) {
                throw new TenantMismatch('Invalid report export state.');
            }
            if ($export->exists && $export->isDirty(['company_id', 'requested_by', 'report_type', 'parameters', 'format'])) {
                throw new TenantMismatch('Report export identity cannot change.');
            }
            if (! $export->exists && $export->company_id === null && ! $context->isAllCompanies()) {
                $export->company_id = $context->companyId();
            }
            if (! $context->isSet() || ($export->company_id === null && ! $context->isAllCompanies()) ||
                (! $export->exists && $export->company_id === null && $owner->primary_role !== UserRole::PlatformAdmin) ||
                (! $export->exists && $owner->company_id !== null && (int) $owner->company_id !== (int) $export->company_id) ||
                ($export->company_id !== null && ! $context->isAllCompanies() &&
                    (int) $export->company_id !== $context->companyId())) {
                throw new TenantMismatch('Invalid report export company.');
            }
            if ($export->company_id === null && ! in_array($export->report_type, ['periodic', 'hotspots'], true)) {
                throw new TenantMismatch('All-company exports are aggregate reports only.');
            }
        });
    }

    protected function casts(): array
    {
        return ['parameters' => 'array', 'ready_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}

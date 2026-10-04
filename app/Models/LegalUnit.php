<?php

namespace App\Models;

use App\Enums\LegalUnitStatus;
use App\Exceptions\ImmutableRecordException;
use App\Exceptions\TenantMismatch;
use App\Support\Tenancy\LegalLibraryScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
            if ($unit->exists && in_array($unit->getRawOriginal('status'), ['verified_auto', 'verified_human'], true)) {
                $dirty = array_keys($unit->getDirty());
                if ($dirty !== ['superseded_by_id'] || $unit->getRawOriginal('superseded_by_id') !== null || $unit->superseded_by_id === null) {
                    throw new ImmutableRecordException('Verified legal units are immutable.');
                }
            }

            $law = Law::withoutGlobalScopes()->find($unit->law_id);
            if ($law !== null) {
                $context = app(TenantContext::class);
                if ($law->company_id !== null && (! $context->isSet()
                    || (! $context->isAllCompanies() && (int) $law->company_id !== $context->companyId()))) {
                    throw new TenantMismatch('Legal unit belongs to another company.');
                }
                if ($unit->company_id !== null && (int) $unit->company_id !== (int) $law->company_id) {
                    throw new TenantMismatch('Legal unit belongs to another company.');
                }
                $unit->company_id = $law->company_id;
            }
            if ($unit->company_id !== null && $unit->verified_by !== null) {
                $verifier = User::find($unit->verified_by);
                if ($verifier?->company_id !== null && (int) $verifier->company_id !== (int) $unit->company_id) {
                    throw new TenantMismatch('Verifier belongs to another company.');
                }
            }
        });
        static::deleting(function (self $unit): void {
            if (in_array($unit->status, [LegalUnitStatus::VerifiedAuto, LegalUnitStatus::VerifiedHuman], true)) {
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
}

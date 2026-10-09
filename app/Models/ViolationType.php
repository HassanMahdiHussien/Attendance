<?php

namespace App\Models;

use App\Exceptions\ImmutableRecordException;
use App\Exceptions\TenantMismatch;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Tenancy\TenantContext;
use App\Support\Text\ArabicText;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class ViolationType extends Model
{
    use BelongsToCompany, Concerns\Audited, HasFactory;

    protected $guarded = [];

    protected $attributes = ['is_active' => true, 'sort_order' => 0];

    protected static function booted(): void
    {
        static::saving(function (self $type): void {
            $names = $type->names ?? [];
            if (! is_array($names) || blank($names['ar'] ?? null) || blank($names['en'] ?? null)) {
                throw ValidationException::withMessages(['names' => __('Arabic and English names are required')]);
            }
            foreach ($names as $name) {
                if (! is_string($name) || mb_strlen(trim($name)) > 255) {
                    throw ValidationException::withMessages(['names' => __('Names must be 255 characters or fewer')]);
                }
            }
            $type->names = array_map(fn (string $name): string => ArabicText::westernDigits(trim($name)), $names);
        });
        static::deleting(function (self $type): void {
            $context = app(TenantContext::class);
            if (! $context->isSet() || (! $context->isAllCompanies() && $context->companyId() !== (int) $type->company_id)) {
                throw new TenantMismatch('Violation type belongs to another company.');
            }
            if (DisciplinaryCase::withoutGlobalScopes()->where('company_id', $type->company_id)->where('violation_type_id', $type->id)->exists()
                || ViolationReport::withoutGlobalScopes()->where('company_id', $type->company_id)->where('violation_type_id', $type->id)->exists()) {
                throw new ImmutableRecordException('Used violation types cannot be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return ['names' => 'array', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function name(?string $language = null): string
    {
        $names = $this->names ?? [];
        $selected = $names[$language ?? app()->getLocale()] ?? null;

        return (string) (filled($selected) ? $selected : ($names['ar'] ?? ''));
    }
}

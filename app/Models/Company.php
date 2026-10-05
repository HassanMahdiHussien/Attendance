<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Company extends Model
{
    use Concerns\Audited, HasFactory;

    protected $table = 'companies';

    protected $guarded = [];

    protected $attributes = ['enabled_languages' => '["ar","en"]', 'is_active' => true];

    protected static function booted(): void
    {
        static::saving(function (self $company): void {
            $languages = $company->enabled_languages;
            if (! is_array($languages) || ! in_array('ar', $languages, true) || ! in_array('en', $languages, true)) {
                throw ValidationException::withMessages(['enabled_languages' => 'Arabic and English must be enabled.']);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'enabled_languages' => 'array',
            'has_internal_regulation' => 'boolean',
            'requires_second_review' => 'boolean',
            'is_active' => 'boolean',
            'subscription_status' => SubscriptionStatus::class,
        ];
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function cases(): HasMany
    {
        return $this->hasMany(DisciplinaryCase::class);
    }

    public function laws(): HasMany
    {
        return $this->hasMany(Law::class);
    }
}

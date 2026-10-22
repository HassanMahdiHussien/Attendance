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
                throw ValidationException::withMessages(['enabled_languages' => __('messages.company.arabic_english_required')]);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'enabled_languages' => 'array',
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
}

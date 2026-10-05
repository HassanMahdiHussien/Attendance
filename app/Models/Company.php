<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class Company extends Model
{
    use Concerns\Audited, HasFactory;

    protected $table = 'companies';

    protected $guarded = [];

    protected $attributes = ['enabled_languages' => '["ar","en"]', 'is_active' => true];

    protected static function booted(): void
    {
        static::created(function (self $company): void {
            $path = null;
            $sha = null;
            if ($company->letterhead_logo_path && Storage::disk('local')->exists($company->letterhead_logo_path)) {
                $bytes = Storage::disk('local')->get($company->letterhead_logo_path);
                $extension = strtolower(pathinfo($company->letterhead_logo_path, PATHINFO_EXTENSION));
                if (in_array($extension, ['png', 'jpg', 'jpeg'], true)) {
                    $sha = hash('sha256', $bytes);
                    $path = "letterheads/{$company->id}/{$sha}.{$extension}";
                    if (! Storage::disk('local')->put($path, $bytes)) {
                        throw new \RuntimeException('Cannot store company letterhead logo.');
                    }
                }
            }
            foreach (['ar', 'en'] as $language) {
                DB::table('company_letterheads')->insert([
                    'company_id' => $company->id, 'language_code' => $language, 'version' => 1,
                    'name' => $company->name, 'address_lines' => $company->letterhead_address,
                    'phone' => $company->letterhead_phone, 'email' => $company->letterhead_email,
                    'footer_text' => 'CONFIDENTIAL', 'logo_path' => $path, 'logo_sha256' => $sha,
                    'needs_review' => $language === 'en', 'created_at' => now(),
                ]);
            }
        });
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

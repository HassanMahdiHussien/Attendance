<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Exceptions\TenantMismatch;
use Database\Factories\UserFactory;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Spatie\Permission\Traits\HasRoles;

#[Hidden(['password', 'remember_token'])]
#[Fillable(['name', 'email', 'password'])]
class User extends Authenticatable implements FilamentUser, HasLocalePreference, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use Concerns\Audited, HasFactory, HasRoles, Notifiable;

    protected string $guard_name = 'web';

    public function preferredLocale(): string
    {
        return in_array($this->locale, ['en', 'ar'], true) ? $this->locale : 'en';
    }

    protected $attributes = ['is_active' => true, 'locale' => 'en'];

    protected static function booted(): void
    {
        static::saving(function (self $user): void {
            if ($user->primary_role === null) {
                throw new TenantMismatch('A primary role is required.');
            }
            $companyRole = in_array($user->primary_role, [
                UserRole::CompanyAdmin, UserRole::HrStaff, UserRole::DeptManager,
                UserRole::Reporter, UserRole::CommitteeMember,
            ], true);

            if ($companyRole !== ($user->company_id !== null)) {
                throw new TenantMismatch('User role and company do not match.');
            }

            if ($user->primary_role === UserRole::ExternalLegalReviewer && $user->law_firm_partner_id === null) {
                throw new TenantMismatch('A law firm is required.');
            }
            if ($user->primary_role === UserRole::HrPartnerStaff && $user->hr_partner_id === null) {
                throw new TenantMismatch('An HR partner is required.');
            }

            if ($user->employee_id !== null) {
                $employee = Employee::withoutGlobalScopes()->find($user->employee_id);
                if ($employee !== null && (int) $employee->company_id !== (int) $user->company_id) {
                    throw new TenantMismatch('Employee belongs to another company.');
                }
            }
        });
    }

    public function scopeInCompany(Builder $query, Company|int $company): Builder
    {
        return $query->where('company_id', $company instanceof Company ? $company->id : $company);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active || ($this->company_id !== null && ! $this->company?->is_active)) {
            return false;
        }

        return match ($panel->getId()) {
            'platform' => $this->primary_role === UserRole::PlatformAdmin,
            'admin' => in_array($this->primary_role, [UserRole::PlatformAdmin, UserRole::CompanyAdmin, UserRole::HrStaff], true),
            default => false,
        };
    }

    public function getTenants(Panel $panel): array|Collection
    {
        if (! $this->canAccessPanel($panel)) {
            return [];
        }

        return $this->primary_role === UserRole::PlatformAdmin
            ? Company::query()->get()
            : Company::query()->whereKey($this->company_id)->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Company && $this->is_active
            && $this->canAccessPanel(Filament::getCurrentOrDefaultPanel())
            && ($this->primary_role === UserRole::PlatformAdmin || ($tenant->is_active && (int) $this->company_id === (int) $tenant->getKey()));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function lawFirmPartner(): BelongsTo
    {
        return $this->belongsTo(LawFirmPartner::class);
    }

    public function hrPartner(): BelongsTo
    {
        return $this->belongsTo(HrPartner::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'primary_role' => UserRole::class,
            'is_active' => 'boolean',
        ];
    }
}

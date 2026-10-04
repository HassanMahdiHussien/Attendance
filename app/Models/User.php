<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Exceptions\TenantMismatch;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Hidden(['password', 'remember_token'])]
#[Fillable(['name', 'email', 'password'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::saving(function (self $user): void {
            if ($user->primary_role === null) {
                throw new TenantMismatch('A primary role is required.');
            }
            $companyRole = in_array($user->primary_role, [
                UserRole::CompanyAdmin, UserRole::HrStaff, UserRole::DeptManager,
                UserRole::Reporter, UserRole::CommitteeMember, UserRole::CompanyLegalReviewer,
            ], true);

            if ($companyRole !== ($user->company_id !== null)) {
                throw new TenantMismatch('User role and company do not match.');
            }

            if ($user->employee_id !== null) {
                $employee = Employee::withoutGlobalScopes()->find($user->employee_id);
                if ($employee !== null && (int) $employee->company_id !== (int) $user->company_id) {
                    throw new TenantMismatch('Employee belongs to another company.');
                }
            }
        });
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

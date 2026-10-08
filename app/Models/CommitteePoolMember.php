<?php

namespace App\Models;

use App\Enums\PoolRole;
use App\Enums\UserRole;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class CommitteePoolMember extends Model
{
    use BelongsToCompany, Concerns\Audited, HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $member): void {
            if ($member->user_id !== null) {
                $user = User::find($member->user_id);
                // HR and Legal alternates are often the company's own HR staff or admin; they keep one
                // account. Reporters and department managers can never sit on the committee.
                $poolRoles = [UserRole::CommitteeMember, UserRole::HrStaff, UserRole::CompanyAdmin];
                if (! $user || ! in_array($user->primary_role, $poolRoles, true) || (int) $user->company_id !== (int) $member->company_id) {
                    throw ValidationException::withMessages(['user_id' => __('messages.committee.account_role_required')]);
                }
            }
        });
    }

    protected $table = 'committee_pool_members';

    protected $guarded = [];

    protected array $companyUserKeys = ['user_id'];

    protected array $companyParents = ['mandate'];

    protected function casts(): array
    {
        return [
            'role_type' => PoolRole::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function mandate(): BelongsTo
    {
        return $this->belongsTo(CommitteeMandate::class, 'mandate_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

<?php

namespace App\Models;

use App\Enums\PoolRole;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommitteePoolMember extends Model
{
    use BelongsToCompany, HasFactory;

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

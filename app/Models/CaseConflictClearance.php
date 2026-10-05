<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseConflictClearance extends Model
{
    use AppendOnly, BelongsToCompany, HasFactory;

    protected $table = 'case_conflict_clearances';

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyUserKeys = ['cleared_by'];

    protected array $companyParents = ['disciplinaryCase', 'poolMember'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function disciplinaryCase(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }

    public function poolMember(): BelongsTo
    {
        return $this->belongsTo(CommitteePoolMember::class, 'committee_pool_member_id');
    }
}

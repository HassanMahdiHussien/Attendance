<?php

namespace App\Models;

use App\Enums\Vote;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommitteeSessionAttendance extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'committee_session_attendances';

    protected $guarded = [];

    protected array $companyParents = ['disciplinaryCase'];

    protected function casts(): array
    {
        return [
            'is_chair' => 'boolean',
            'vote' => Vote::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function disciplinaryCase(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }

    public function committeePoolMember(): BelongsTo
    {
        return $this->belongsTo(CommitteePoolMember::class, 'committee_pool_member_id');
    }
}

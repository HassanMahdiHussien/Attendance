<?php

namespace App\Models\Attendance;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HolidayVersion extends Model
{
    use AppendOnly, BelongsToCompany, HasFactory;

    protected $table = 'attendance_holiday_versions';

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyParents = ['holiday', 'source'];

    protected array $companyUserKeys = ['decided_by'];

    protected function casts(): array
    {
        return [
            'ranges' => 'array', 'legal_entities' => 'array', 'branch_ids' => 'array',
            'work_patterns' => 'array', 'created_at' => 'datetime',
        ];
    }

    public function holiday(): BelongsTo
    {
        return $this->belongsTo(Holiday::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(HolidaySource::class, 'source_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}

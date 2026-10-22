<?php

namespace App\Models\Attendance;

use App\Models\Branch;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Day extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'attendance_days';

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyParents = ['employee', 'branch', 'run', 'supersedingRun', 'policy'];

    protected function casts(): array
    {
        return [
            'work_date' => 'date', 'first_in' => 'datetime', 'last_out' => 'datetime',
            'required_leave_at' => 'datetime', 'intervals' => 'array', 'exits' => 'array',
            'inputs' => 'array', 'changes' => 'array', 'reviewed' => 'boolean', 'created_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ProcessingRun::class, 'run_id');
    }

    public function supersedingRun(): BelongsTo
    {
        return $this->belongsTo(ProcessingRun::class, 'superseded_by_run_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class, 'policy_id');
    }
}

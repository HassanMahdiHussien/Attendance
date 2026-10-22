<?php

namespace App\Models\Leave;

use App\Models\Attendance\Day;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Allocation extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'leave_allocations';

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyParents = ['employee', 'sourceRequestDay', 'sourceCredit', 'targetDay', 'run', 'supersedingRun'];

    protected function casts(): array
    {
        return ['explanation' => 'array', 'created_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function sourceRequestDay(): BelongsTo
    {
        return $this->belongsTo(RequestDay::class, 'source_request_day_id');
    }

    public function sourceCredit(): BelongsTo
    {
        return $this->belongsTo(Credit::class, 'source_credit_id');
    }

    public function targetDay(): BelongsTo
    {
        return $this->belongsTo(Day::class, 'target_day_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ProcessingRun::class, 'run_id');
    }

    public function supersedingRun(): BelongsTo
    {
        return $this->belongsTo(ProcessingRun::class, 'superseded_by_run_id');
    }
}

<?php

namespace App\Models\Leave;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestDay extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'leave_request_days';

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyParents = ['request', 'employee', 'run', 'supersedingRun'];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'created_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'request_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
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

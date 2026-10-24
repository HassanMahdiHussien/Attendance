<?php

namespace App\Models\Leave;

use App\Models\Attendance\RestDayEntitlement;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntitlementConsumption extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'leave_entitlement_consumptions';

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyParents = ['employee', 'request', 'requestDay', 'entitlement', 'run', 'supersedingRun'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:1', 'explanation' => 'array', 'created_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'request_id');
    }

    public function requestDay(): BelongsTo
    {
        return $this->belongsTo(RequestDay::class, 'request_day_id');
    }

    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(RestDayEntitlement::class, 'entitlement_id');
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

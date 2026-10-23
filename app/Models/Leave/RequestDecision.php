<?php

namespace App\Models\Leave;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestDecision extends Model
{
    use AppendOnly, BelongsToCompany, HasFactory;

    protected $table = 'leave_request_decisions';

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyParents = ['request', 'employee'];

    protected array $companyUserKeys = ['created_by'];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'values' => 'array', 'created_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'request_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}

<?php

namespace App\Models\Leave;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'leave_requests';

    protected $guarded = [];

    protected array $companyParents = ['employee', 'leaveType', 'latestBatch', 'currentVersion'];

    protected function casts(): array
    {
        return ['rejected_on' => 'date', 'start_date' => 'date', 'end_date' => 'date', 'created_on' => 'datetime', 'latest_snapshot_at' => 'datetime', 'flags' => 'array'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function latestBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'latest_batch_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(RequestVersion::class, 'current_version_id');
    }
}

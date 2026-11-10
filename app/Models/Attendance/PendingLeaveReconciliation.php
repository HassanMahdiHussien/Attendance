<?php

namespace App\Models\Attendance;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingLeaveReconciliation extends Model
{
    use BelongsToCompany;

    protected $table = 'attendance_pending_leave_reconciliations';

    protected $guarded = [];

    protected array $companyParents = ['employee'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}

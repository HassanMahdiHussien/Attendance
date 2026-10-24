<?php

namespace App\Models\Attendance;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestDayEntitlement extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'attendance_rest_day_entitlements';

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyParents = ['employee', 'sourceDay', 'run', 'supersedingRun'];

    protected function casts(): array
    {
        return [
            'work_date' => 'date', 'quantity' => 'decimal:1', 'holiday' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function sourceDay(): BelongsTo
    {
        return $this->belongsTo(Day::class, 'source_day_id');
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

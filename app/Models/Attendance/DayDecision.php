<?php

namespace App\Models\Attendance;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DayDecision extends Model
{
    use AppendOnly, BelongsToCompany, HasFactory;

    protected $table = 'attendance_day_decisions';

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyParents = ['employee', 'manualPunch'];

    protected array $companyUserKeys = ['created_by'];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function manualPunch(): BelongsTo
    {
        return $this->belongsTo(ManualPunch::class);
    }
}

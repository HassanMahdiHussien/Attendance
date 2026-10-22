<?php

namespace App\Models\Attendance;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Punch extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'attendance_punches';

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyParents = ['transaction', 'run', 'employee', 'supersedingRun'];

    protected function casts(): array
    {
        return ['punched_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ProcessingRun::class, 'run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function supersedingRun(): BelongsTo
    {
        return $this->belongsTo(ProcessingRun::class, 'superseded_by_run_id');
    }
}

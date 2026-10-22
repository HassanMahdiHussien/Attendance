<?php

namespace App\Models\Attendance;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Meal extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'attendance_meals';

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyParents = ['firstTransaction', 'run', 'employee', 'supersedingRun'];

    protected function casts(): array
    {
        return ['meal_date' => 'date', 'created_at' => 'datetime'];
    }

    public function firstTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'first_transaction_id');
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

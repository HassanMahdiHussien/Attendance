<?php

namespace App\Models\Leave;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Credit extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'leave_credits';

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyParents = ['employee', 'sourceDay', 'run', 'supersedingRun'];

    protected function casts(): array
    {
        return ['expires_on' => 'date', 'created_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function sourceDay(): BelongsTo
    {
        return $this->belongsTo(RequestDay::class, 'source_day_id');
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

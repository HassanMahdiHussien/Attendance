<?php

namespace App\Models\Attendance;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonthSummary extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'attendance_month_summaries';

    protected $guarded = [];

    protected array $companyParents = ['employee'];

    protected function casts(): array
    {
        return ['month' => 'date', 'attendance' => 'array', 'overtime' => 'array',
            'version' => 'integer', 'is_dirty' => 'boolean', 'built_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}

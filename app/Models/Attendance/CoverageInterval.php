<?php

namespace App\Models\Attendance;

use App\Models\Branch;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoverageInterval extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'attendance_coverage_intervals';

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyParents = ['batch', 'branch'];

    protected function casts(): array
    {
        return ['coverage_from' => 'datetime', 'coverage_to' => 'datetime', 'is_confirmed' => 'boolean', 'created_at' => 'datetime'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'batch_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}

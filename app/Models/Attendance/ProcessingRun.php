<?php

namespace App\Models\Attendance;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessingRun extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'attendance_processing_runs';

    protected $guarded = [];

    protected array $companyParents = ['batch', 'device'];

    protected function casts(): array
    {
        return ['person_codes' => 'array', 'counts' => 'array', 'scope_from' => 'datetime', 'scope_to' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'batch_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}

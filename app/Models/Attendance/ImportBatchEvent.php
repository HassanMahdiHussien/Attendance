<?php

namespace App\Models\Attendance;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportBatchEvent extends Model
{
    use AppendOnly, BelongsToCompany, HasFactory;

    protected $table = 'attendance_import_batch_events';

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyParents = ['batch'];

    protected function casts(): array
    {
        return ['counts' => 'array', 'created_at' => 'datetime'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'batch_id');
    }
}

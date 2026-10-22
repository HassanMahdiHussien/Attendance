<?php

namespace App\Models\Leave;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRow extends Model
{
    use AppendOnly, BelongsToCompany, HasFactory;

    protected $table = 'leave_import_rows';

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyParents = ['batch', 'request'];

    protected function casts(): array
    {
        return ['raw_values' => 'array', 'errors' => 'array', 'created_at' => 'datetime'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'batch_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'request_id');
    }
}

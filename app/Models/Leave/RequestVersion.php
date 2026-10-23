<?php

namespace App\Models\Leave;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestVersion extends Model
{
    use AppendOnly, BelongsToCompany, HasFactory;

    protected $table = 'leave_request_versions';

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyParents = ['request', 'batch'];

    protected function casts(): array
    {
        return ['snapshot_at' => 'datetime', 'values' => 'array', 'raw_values' => 'array', 'seen_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'request_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'batch_id');
    }
}

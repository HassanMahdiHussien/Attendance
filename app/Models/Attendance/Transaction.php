<?php

namespace App\Models\Attendance;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use AppendOnly, BelongsToCompany, HasFactory;

    protected $table = 'attendance_transactions';

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyParents = ['firstBatch', 'device'];

    protected function casts(): array
    {
        return ['punched_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function firstBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'first_batch_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}

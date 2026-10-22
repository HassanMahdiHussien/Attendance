<?php

namespace App\Models\Attendance;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionSource extends Model
{
    use AppendOnly, BelongsToCompany, HasFactory;

    protected $table = 'attendance_transaction_sources';

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyParents = ['transaction', 'batch'];

    protected function casts(): array
    {
        return ['duplicate_in_file' => 'boolean', 'errors' => 'array', 'created_at' => 'datetime'];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'batch_id');
    }
}

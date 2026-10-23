<?php

namespace App\Models\Leave;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImportBatch extends Model
{
    use AppendOnly, BelongsToCompany, HasFactory;

    protected $table = 'leave_import_batches';

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyUserKeys = ['uploaded_by'];

    protected function casts(): array
    {
        return ['snapshot_at' => 'datetime', 'period_from' => 'date', 'period_to' => 'date', 'mapping' => 'array', 'summary' => 'array', 'created_at' => 'datetime'];
    }
}

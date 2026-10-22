<?php

namespace App\Models\Attendance;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImportBatch extends Model
{
    use AppendOnly, BelongsToCompany, HasFactory;

    protected $table = 'attendance_import_batches';

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyUserKeys = ['uploaded_by'];

    protected function casts(): array
    {
        return ['export_at' => 'datetime', 'coverage_from' => 'datetime', 'coverage_to' => 'datetime', 'coverage_branch_ids' => 'array', 'mapping' => 'array', 'summary' => 'array', 'created_at' => 'datetime'];
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeImportRun extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected array $companyParents = ['batch'];

    protected array $companyUserKeys = ['uploaded_by'];

    protected function casts(): array
    {
        return ['effective_date' => 'date', 'mapping' => 'array', 'result_counts' => 'array'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(EmployeeImportBatch::class, 'employee_import_batch_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}

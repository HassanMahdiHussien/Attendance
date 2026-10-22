<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeVersion extends Model
{
    use AppendOnly, BelongsToCompany, HasFactory;

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyParents = ['employee', 'batch'];

    protected array $companyUserKeys = ['changed_by'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'changes' => 'array', 'previous' => 'array', 'created_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(EmployeeImportBatch::class, 'employee_import_batch_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}

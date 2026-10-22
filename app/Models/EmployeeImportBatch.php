<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeImportBatch extends Model
{
    use AppendOnly, BelongsToCompany, HasFactory;

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyUserKeys = ['uploaded_by'];

    protected function casts(): array
    {
        return ['effective_date' => 'date', 'mapping' => 'array', 'counts' => 'array', 'summary' => 'array', 'created_at' => 'datetime'];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}

<?php

namespace App\Models\Attendance;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataIssue extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'data_issues';

    protected $guarded = [];

    protected array $companyParents = ['batch'];

    protected array $companyUserKeys = ['resolved_by'];

    protected function casts(): array
    {
        return ['date_from' => 'date', 'date_to' => 'date', 'resolved_at' => 'datetime', 'details' => 'array'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'batch_id');
    }
}

<?php

namespace App\Models\Leave;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProcessingRun extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'leave_processing_runs';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['scope' => 'array', 'counts' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}

<?php

namespace App\Models\Leave;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DescriptionReading extends Model
{
    use BelongsToCompany;

    protected $table = 'leave_description_readings';

    protected $guarded = [];

    protected array $companyParents = ['request'];

    protected array $companyUserKeys = ['decided_by'];

    protected function casts(): array
    {
        return ['worked_dates' => 'array', 'settled_from' => 'date', 'settled_to' => 'date', 'decided_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'request_id');
    }
}

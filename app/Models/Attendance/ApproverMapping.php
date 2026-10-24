<?php

namespace App\Models\Attendance;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApproverMapping extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'attendance_approver_mappings';

    protected $guarded = [];

    protected array $companyParents = ['employee'];

    protected array $companyUserKeys = ['confirmed_by'];

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}

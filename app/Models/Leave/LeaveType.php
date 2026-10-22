<?php

namespace App\Models\Leave;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'leave_types';

    protected $guarded = [];

    protected array $companyUserKeys = ['mapped_by'];

    protected function casts(): array
    {
        return ['covers' => 'boolean', 'is_mapped' => 'boolean'];
    }
}

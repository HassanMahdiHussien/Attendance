<?php

namespace App\Models\Attendance;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Policy extends Model
{
    use AppendOnly, BelongsToCompany, HasFactory;

    protected $table = 'attendance_policies';

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyUserKeys = ['created_by'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'office_rest_days' => 'array',
            'exits_count_toward_balance' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}

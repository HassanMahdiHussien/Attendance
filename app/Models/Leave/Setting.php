<?php

namespace App\Models\Leave;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'leave_settings';

    protected $attributes = ['leave_calendar_rest_days' => '[5]'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['leave_calendar_rest_days' => 'array'];
    }

    protected array $companyUserKeys = ['updated_by'];
}

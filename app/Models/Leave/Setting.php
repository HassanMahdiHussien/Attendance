<?php

namespace App\Models\Leave;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'leave_settings';

    protected $guarded = [];

    protected array $companyUserKeys = ['updated_by'];
}

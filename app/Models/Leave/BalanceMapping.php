<?php

namespace App\Models\Leave;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BalanceMapping extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'leave_balance_mappings';

    protected $guarded = [];

    protected array $companyUserKeys = ['updated_by'];

    protected function casts(): array
    {
        return ['headers' => 'array', 'mapping' => 'array'];
    }
}

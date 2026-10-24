<?php

namespace App\Models\Leave;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BalanceBatch extends Model
{
    use AppendOnly, BelongsToCompany, HasFactory;

    protected $table = 'leave_balance_batches';

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyUserKeys = ['uploaded_by'];

    protected function casts(): array
    {
        return ['mapping' => 'array', 'balance_date' => 'date', 'created_at' => 'datetime'];
    }
}

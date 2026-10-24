<?php

namespace App\Models\Leave;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BalanceRow extends Model
{
    use AppendOnly, BelongsToCompany, HasFactory;

    protected $table = 'leave_balance_rows';

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyParents = ['batch', 'employee'];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(BalanceBatch::class, 'batch_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}

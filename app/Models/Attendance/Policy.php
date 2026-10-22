<?php

namespace App\Models\Attendance;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

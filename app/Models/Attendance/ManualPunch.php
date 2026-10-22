<?php

namespace App\Models\Attendance;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualPunch extends Model
{
    use AppendOnly, BelongsToCompany, HasFactory;

    protected $table = 'attendance_manual_punches';

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyParents = ['employee', 'reversedPunch'];

    protected array $companyUserKeys = ['created_by'];

    protected function casts(): array
    {
        return ['punched_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reversedPunch(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

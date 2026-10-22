<?php

namespace App\Models\Attendance;

use App\Models\Branch;
use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceSetting extends Model
{
    use AppendOnly, BelongsToCompany, HasFactory;

    protected $table = 'attendance_device_settings';

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyParents = ['device', 'branch'];

    protected array $companyUserKeys = ['approved_by'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'created_at' => 'datetime'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}

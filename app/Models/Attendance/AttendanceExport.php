<?php

namespace App\Models\Attendance;

use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceExport extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'attendance_exports';

    protected $guarded = [];

    protected array $companyUserKeys = ['requested_by'];

    protected function casts(): array
    {
        return ['filters' => 'array'];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AttendanceExportEvent::class, 'export_id');
    }
}

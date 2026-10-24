<?php

namespace App\Models\Attendance;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationSetting extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'attendance_notification_settings';

    protected $guarded = [];

    protected array $companyUserKeys = ['updated_by'];

    protected function casts(): array
    {
        return ['reminder_interval_days' => 'integer', 'reminder_max' => 'integer'];
    }
}

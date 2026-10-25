<?php

namespace App\Models\Attendance;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationMessage extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'attendance_notification_messages';

    protected $guarded = [];

    protected array $companyParents = ['batch', 'recipientEmployee'];

    protected function casts(): array
    {
        return ['attempts' => 'integer'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(NotificationBatch::class, 'batch_id');
    }

    public function recipientEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'recipient_employee_id');
    }

    public function cases(): HasMany
    {
        return $this->hasMany(NotificationMessageCase::class, 'message_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(NotificationAttempt::class, 'message_id');
    }

    public function attemptRows(): HasMany
    {
        // The attempts column is the delivery counter, so the UI needs a distinct relation name.
        return $this->hasMany(NotificationAttempt::class, 'message_id');
    }
}

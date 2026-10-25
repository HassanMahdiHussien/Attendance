<?php

namespace App\Models\Attendance;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationMessageCase extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'attendance_notification_message_cases';

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyParents = ['message', 'followUpCase'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'created_at' => 'datetime', 'cycle_number' => 'integer'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(NotificationMessage::class, 'message_id');
    }

    public function followUpCase(): BelongsTo
    {
        return $this->belongsTo(FollowUpCase::class, 'case_id');
    }
}

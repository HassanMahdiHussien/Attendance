<?php

namespace App\Models\Attendance;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationRoute extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'attendance_notification_routes';

    protected $guarded = [];

    protected array $companyParents = ['followUpCase'];

    protected function casts(): array
    {
        return ['last_sent_at' => 'datetime', 'sent_count' => 'integer'];
    }

    public function followUpCase(): BelongsTo
    {
        return $this->belongsTo(FollowUpCase::class, 'case_id');
    }
}

<?php

namespace App\Models\Attendance;

use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationBatch extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'attendance_notification_batches';

    protected $guarded = [];

    protected array $companyUserKeys = ['built_by'];

    protected function casts(): array
    {
        return ['cut_off' => 'datetime'];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(NotificationMessage::class, 'batch_id');
    }

    public function builder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'built_by')->withoutGlobalScopes();
    }
}

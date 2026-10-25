<?php

namespace App\Models\Attendance;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FollowUpCase extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'attendance_cases';

    protected $guarded = [];

    protected array $companyParents = ['employee', 'day'];

    protected array $companyUserKeys = ['manual_closed_by'];

    protected function casts(): array
    {
        return [
            'work_date' => 'date', 'items' => 'array', 'routes' => 'array', 'request_ids' => 'array',
            'closure_source' => 'array', 'closed_at' => 'datetime',
            'manual_closed_items' => 'array', 'manual_closed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(Day::class, 'day_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CaseEvent::class, 'case_id');
    }

    public function deliveryRoutes(): HasMany
    {
        return $this->hasMany(NotificationRoute::class, 'case_id');
    }
}

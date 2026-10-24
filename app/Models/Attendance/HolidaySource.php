<?php

namespace App\Models\Attendance;

use App\Models\AiGenerationLog;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HolidaySource extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'attendance_holiday_sources';

    protected $guarded = [];

    protected array $companyParents = ['aiLog'];

    protected array $companyUserKeys = ['uploaded_by'];

    protected function casts(): array
    {
        return ['proposal' => 'array', 'parts' => 'array'];
    }

    public function aiLog(): BelongsTo
    {
        return $this->belongsTo(AiGenerationLog::class, 'ai_log_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}

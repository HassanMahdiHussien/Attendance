<?php

namespace App\Models\Attendance;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Holiday extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'attendance_holidays';

    protected $guarded = [];

    protected array $companyParents = ['currentVersion'];

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(HolidayVersion::class, 'current_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(HolidayVersion::class);
    }
}

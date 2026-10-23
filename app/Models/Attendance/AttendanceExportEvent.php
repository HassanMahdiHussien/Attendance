<?php

namespace App\Models\Attendance;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceExportEvent extends Model
{
    use AppendOnly, BelongsToCompany, HasFactory;

    protected $table = 'attendance_export_events';

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyParents = ['export'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function export(): BelongsTo
    {
        return $this->belongsTo(AttendanceExport::class, 'export_id');
    }
}

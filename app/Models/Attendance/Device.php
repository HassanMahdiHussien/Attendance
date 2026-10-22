<?php

namespace App\Models\Attendance;

use App\Models\Branch;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Device extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'attendance_devices';

    protected $guarded = [];

    protected array $companyParents = ['suggestedBranch'];

    protected function casts(): array
    {
        return ['suggested_branch_id' => 'integer'];
    }

    public function suggestedBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'suggested_branch_id');
    }
}

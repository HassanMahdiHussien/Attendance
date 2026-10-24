<?php

namespace App\Models\Attendance;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseEvent extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'attendance_case_events';

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyParents = ['followUpCase'];

    protected array $companyUserKeys = ['actor_id'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];
    }

    public function followUpCase(): BelongsTo
    {
        return $this->belongsTo(FollowUpCase::class, 'case_id');
    }
}

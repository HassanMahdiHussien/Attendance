<?php

namespace App\Models\Attendance;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Person extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'attendance_people';

    protected $guarded = [];

    protected array $companyParents = ['employee'];

    protected function casts(): array
    {
        return ['classified_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}

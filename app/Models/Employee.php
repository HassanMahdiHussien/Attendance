<?php

namespace App\Models;

use App\Enums\EmployeeGender;
use App\Enums\SequenceKey;
use App\Models\Concerns\BelongsToCompany;
use App\Services\SequenceService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class Employee extends Model
{
    use BelongsToCompany, Concerns\Audited, HasFactory;

    protected $attributes = ['is_active' => true, 'is_general_manager' => false, 'gender' => 'unspecified'];

    protected $table = 'employees';

    protected $guarded = [];

    protected array $companyParents = ['branch'];

    protected static function booted(): void
    {
        static::saving(function (self $employee): void {
            if (! EmployeeGender::tryFrom((string) ($employee->getAttributes()['gender'] ?? 'unspecified'))) {
                throw ValidationException::withMessages(['gender' => __('messages.employee.gender_invalid')]);
            }
            if ($employee->is_general_manager && $employee->is_active && self::query()
                ->where('company_id', $employee->company_id)
                ->where('is_general_manager', true)->where('is_active', true)
                ->when($employee->exists, fn ($query) => $query->whereKeyNot($employee->getKey()))
                ->exists()) {
                throw ValidationException::withMessages(['is_general_manager' => __('messages.employee.single_active_general_manager')]);
            }
        });
        static::creating(function (self $employee): void {
            if (blank($employee->employee_code)) {
                $number = app(SequenceService::class)->next((int) $employee->company_id, SequenceKey::EmployeeCode);
                $employee->employee_code = 'EMP-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
            'gender' => EmployeeGender::class,
            'is_general_manager' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function cases(): HasMany
    {
        return $this->hasMany(DisciplinaryCase::class);
    }
}

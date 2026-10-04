<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'employees';

    protected $guarded = [];

    protected array $companyParents = ['branch'];

    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
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

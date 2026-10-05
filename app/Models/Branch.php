<?php

namespace App\Models;

use App\Enums\Jurisdiction;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use BelongsToCompany, Concerns\Audited, HasFactory;

    protected $table = 'branches';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['jurisdiction' => Jurisdiction::class, 'is_active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function violationReports(): HasMany
    {
        return $this->hasMany(ViolationReport::class);
    }

    public function cases(): HasMany
    {
        return $this->hasMany(DisciplinaryCase::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Incident extends Model
{
    use BelongsToCompany, HasFactory;

    protected $guarded = [];

    protected array $companyParents = ['branch'];

    protected array $companyUserKeys = ['created_by_user_id'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function cases(): HasMany
    {
        return $this->hasMany(DisciplinaryCase::class);
    }
}

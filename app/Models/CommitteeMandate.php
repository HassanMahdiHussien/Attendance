<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommitteeMandate extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'committee_mandates';

    protected $guarded = [];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function poolMembers(): HasMany
    {
        return $this->hasMany(CommitteePoolMember::class, 'mandate_id');
    }

    public function scopeInEffectOn(Builder $query, DateTimeInterface|string $date): Builder
    {
        return $query->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $builder) => $builder->whereNull('superseded_at')->orWhereDate('superseded_at', '>', $date));
    }
}

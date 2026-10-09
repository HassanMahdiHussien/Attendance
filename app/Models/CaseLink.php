<?php

namespace App\Models;

use App\Enums\CaseLinkType;
use App\Exceptions\ImmutableRecordException;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CaseLink extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'case_links';

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyParents = ['disciplinaryCase'];

    protected array $companyUserKeys = ['created_by_user_id'];

    protected static function booted(): void
    {
        static::updating(fn (self $link) => $link->getRawOriginal('link_type') === CaseLinkType::Related->value
            ? throw new ImmutableRecordException('Related case links cannot be changed.') : null);
        static::deleting(fn (self $link) => $link->link_type === CaseLinkType::Related
            ? throw new ImmutableRecordException('Related case links cannot be deleted.') : null);
    }

    protected function casts(): array
    {
        return [
            'link_type' => CaseLinkType::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function disciplinaryCase(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }

    public function linkedCase(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'linked_case_id');
    }

    public function annotations(): HasMany
    {
        return $this->hasMany(CaseLinkAnnotation::class);
    }
}

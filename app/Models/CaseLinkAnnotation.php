<?php

namespace App\Models;

use App\Exceptions\ImmutableRecordException;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseLinkAnnotation extends Model
{
    use BelongsToCompany, HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    protected array $companyParents = ['caseLink'];

    protected array $companyUserKeys = ['created_by_user_id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new ImmutableRecordException('Case link annotations cannot be changed.'));
        static::deleting(fn () => throw new ImmutableRecordException('Case link annotations cannot be deleted.'));
    }

    public function caseLink(): BelongsTo
    {
        return $this->belongsTo(CaseLink::class);
    }
}

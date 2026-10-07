<?php

namespace App\Models;

use App\Enums\LawKind;
use App\Exceptions\TenantMismatch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegalUnitTopic extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected static function booted(): void
    {
        static::saving(function (self $topic): void {
            $unit = LegalUnit::withoutGlobalScopes()->with('law')->findOrFail($topic->legal_unit_id);
            if ($unit->company_id !== null || $unit->law->kind !== LawKind::Statute) {
                throw new TenantMismatch('Topics belong to platform statutes only.');
            }
        });
    }

    public function legalUnit(): BelongsTo
    {
        return $this->belongsTo(LegalUnit::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

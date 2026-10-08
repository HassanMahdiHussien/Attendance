<?php

namespace App\Models;

use App\Enums\RelevanceCheck;
use App\Exceptions\ImmutableRecordException;
use App\Exceptions\TenantMismatch;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class Citation extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'citations';

    protected $guarded = [];

    protected array $companyUserKeys = ['overridden_by'];

    protected array $companyParents = ['document'];

    protected static function booted(): void
    {
        static::saving(function (self $citation): void {
            $document = Document::withoutGlobalScopes()->find($citation->document_id);
            if ($document?->is_locked) {
                throw new ImmutableRecordException('لا يمكن تغيير استشهادات مستند مقفل.');
            }
            $unit = LegalUnit::withoutGlobalScopes()->find($citation->legal_unit_id);
            if ($unit?->company_id !== null && $document !== null
                && (int) $unit->company_id !== (int) $document->company_id) {
                throw new TenantMismatch('Regulation belongs to another company.');
            }
            if (! $citation->exists && $document !== null && ! LegalUnit::query()->citableFor($document->disciplinaryCase)
                ->whereKey($citation->legal_unit_id)->exists()) {
                throw ValidationException::withMessages(['legal_unit_id' => 'هذه المادة القانونية غير صالحة للاستشهاد في الحالة.']);
            }
        });
        static::deleting(function (self $citation): void {
            if (Document::withoutGlobalScopes()->find($citation->document_id)?->is_locked) {
                throw new ImmutableRecordException('Locked document citations cannot be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'relevance_check' => RelevanceCheck::class,
            'overridden' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function legalUnit(): BelongsTo
    {
        return $this->belongsTo(LegalUnit::class, 'legal_unit_id');
    }

    public function overrider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by');
    }
}

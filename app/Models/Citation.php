<?php

namespace App\Models;

use App\Enums\LawApprovalStatus;
use App\Enums\LawKind;
use App\Enums\LegalUnitStatus;
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
                throw new ImmutableRecordException('Locked document citations cannot be changed.');
            }
            $unit = LegalUnit::withoutGlobalScopes()->find($citation->legal_unit_id);
            if ($unit === null) {
                return;
            }
            if (! in_array($unit->status, [LegalUnitStatus::VerifiedAuto, LegalUnitStatus::VerifiedHuman], true)) {
                throw ValidationException::withMessages(['legal_unit_id' => 'Only verified legal units can be cited.']);
            }
            $law = Law::withoutGlobalScopes()->find($unit->law_id);
            if ($law?->kind !== LawKind::Statute && (int) $unit->company_id !== (int) $document?->company_id) {
                throw new TenantMismatch('Regulation belongs to another company.');
            }
            if ($law?->kind === LawKind::InternalRegulation && $law->approval_status !== LawApprovalStatus::Approved) {
                throw ValidationException::withMessages(['legal_unit_id' => 'The regulation must be approved before citation.']);
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

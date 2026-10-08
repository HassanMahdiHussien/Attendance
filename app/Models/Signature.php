<?php

namespace App\Models;

use App\Enums\PresenceCheck;
use App\Enums\SignaturePurpose;
use App\Enums\SignatureStatus;
use App\Exceptions\ImmutableRecordException;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class Signature extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'signatures';

    protected $guarded = [];

    protected array $companyParents = ['disciplinaryCase'];

    protected array $companyUserKeys = ['checked_by'];

    protected static function booted(): void
    {
        static::saving(function (self $signature): void {
            if ($signature->exists && $signature->isDirty()) {
                throw new ImmutableRecordException('Signatures cannot be updated.');
            }
            if (in_array($signature->status?->value, ['refused_to_sign', 'delivery_refused'], true)
                && (blank($signature->witness_1_name) || blank($signature->witness_2_name))) {
                throw ValidationException::withMessages(['status' => 'يلزم شاهدان عند رفض التسليم أو التوقيع.']);
            }
            if ($signature->isDirty('status') && in_array($signature->status?->value, ['refused_to_sign', 'delivery_refused'], true)
                && ! $signature->witness_scan_attachment_id) {
                throw ValidationException::withMessages(['status' => 'يجب رفع مسح الورقة الموقعة من الشاهدين.']);
            }
        });
        static::deleting(fn () => throw new ImmutableRecordException('Signatures cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'purpose' => SignaturePurpose::class,
            'status' => SignatureStatus::class,
            'presence_check' => PresenceCheck::class,
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

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function committeePoolMember(): BelongsTo
    {
        return $this->belongsTo(CommitteePoolMember::class, 'committee_pool_member_id');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    public function witnessScanAttachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'witness_scan_attachment_id');
    }

    public function presenceSuggestion(): BelongsTo
    {
        return $this->belongsTo(SignaturePresenceSuggestion::class, 'presence_suggestion_id');
    }

    public function aiSuggestionDisposition(): ?string
    {
        if ($this->presence_suggestion_id === null || $this->ai_suggestion === null) {
            return null;
        }

        return match ($this->ai_suggestion) {
            'appears_filled' => $this->presence_check === PresenceCheck::BoxFilled ? 'accepted' : 'changed',
            'appears_empty' => $this->presence_check === PresenceCheck::BoxAppearsEmpty ? 'accepted' : 'changed',
            default => 'changed',
        };
    }
}

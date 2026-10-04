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

    protected static function booted(): void
    {
        static::saving(function (self $signature): void {
            if (in_array($signature->status?->value, ['refused_to_sign', 'delivery_refused'], true)
                && (blank($signature->witness_1_name) || blank($signature->witness_2_name))) {
                throw ValidationException::withMessages(['status' => 'Two witnesses are required for refusal.']);
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
}

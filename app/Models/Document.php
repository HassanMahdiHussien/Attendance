<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\DraftedBy;
use App\Enums\PenaltyType;
use App\Exceptions\ImmutableRecordException;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Document extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'documents';

    protected $guarded = [];

    protected array $companyUserKeys = ['issued_by', 'second_reviewed_by', 'created_by'];

    protected array $companyParents = ['disciplinaryCase'];

    protected static function booted(): void
    {
        static::saving(function (self $document): void {
            if ($document->previous_version_id !== null && blank($document->correction_reason)) {
                throw ValidationException::withMessages(['correction_reason' => 'A correction reason is required.']);
            }
        });
        static::updating(function (self $document): void {
            if (! $document->getRawOriginal('is_locked')) {
                return;
            }

            $dirty = array_keys($document->getDirty());
            foreach ($dirty as $column) {
                if (! in_array($column, ['status', 'superseded_by_id', 'pdf_file_path'], true)) {
                    throw new ImmutableRecordException('Locked documents cannot be changed.');
                }
            }

            if ($document->isDirty('status') && ! ($document->getRawOriginal('status') === 'issued' && $document->status === DocumentStatus::Superseded)) {
                throw new ImmutableRecordException('Invalid document status change.');
            }
            foreach (['superseded_by_id', 'pdf_file_path'] as $column) {
                if ($document->isDirty($column) && ($document->getRawOriginal($column) !== null || $document->{$column} === null)) {
                    throw new ImmutableRecordException('Invalid locked document change.');
                }
            }
        });
        static::deleting(fn () => throw new ImmutableRecordException('Documents cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'drafted_by' => DraftedBy::class,
            'penalty_type' => PenaltyType::class,
            'is_locked' => 'boolean',
            'reference_is_manual' => 'boolean',
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

    public function previousVersion(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'previous_version_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'superseded_by_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function secondReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'second_reviewed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function contents(): HasMany
    {
        return $this->hasMany(DocumentContent::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(DocumentApproval::class);
    }

    public function citations(): HasMany
    {
        return $this->hasMany(Citation::class);
    }
}

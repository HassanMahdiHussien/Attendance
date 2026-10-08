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

    protected array $companyUserKeys = ['issued_by', 'second_reviewed_by', 'created_by', 'last_edited_by'];

    protected array $companyParents = ['disciplinaryCase'];

    protected static function booted(): void
    {
        static::saving(function (self $document): void {
            if (! ($document->exists && $document->getRawOriginal('is_locked'))
                && $document->previous_version_id !== null && blank($document->correction_reason)) {
                throw ValidationException::withMessages(['correction_reason' => 'يلزم بيان سبب التصحيح.']);
            }
        });
        static::updating(function (self $document): void {
            if (! $document->getRawOriginal('is_locked')) {
                return;
            }

            $dirty = array_keys($document->getDirty());
            foreach ($dirty as $column) {
                if (! in_array($column, ['status', 'superseded_by_id', 'pdf_file_path', 'pdf_sha256', 'pdf_status', 'pdf_error'], true)) {
                    throw new ImmutableRecordException('Locked documents cannot be changed.');
                }
            }

            if ($document->isDirty('status') && ! ($document->getRawOriginal('status') === 'issued' && $document->status === DocumentStatus::Superseded)) {
                throw new ImmutableRecordException('Invalid document status change.');
            }
            foreach (['superseded_by_id', 'pdf_file_path', 'pdf_sha256'] as $column) {
                if ($document->isDirty($column) && ($document->getRawOriginal($column) !== null || $document->{$column} === null)) {
                    throw new ImmutableRecordException('Invalid locked document change.');
                }
            }
            if ($document->isDirty('pdf_status')) {
                $old = $document->getRawOriginal('pdf_status');
                $next = $document->pdf_status;
                $allowed = [null => ['pending'], 'pending' => ['failed', 'ready'], 'failed' => ['pending', 'ready']];
                if (! in_array($next, $allowed[$old] ?? [], true)) {
                    throw new ImmutableRecordException('Invalid PDF status change.');
                }
            }
            if ($document->isDirty('pdf_error') && ! in_array($document->pdf_status, ['pending', 'failed', 'ready'], true)) {
                throw new ImmutableRecordException('Invalid PDF error change.');
            }
        });
        static::deleting(fn () => throw new ImmutableRecordException('Documents cannot be deleted.'));
    }

    public function setCompanyIdAttribute($value): void
    {
        if ($this->exists && $this->getRawOriginal('is_locked') && (int) $this->getRawOriginal('company_id') !== (int) $value) {
            throw new ImmutableRecordException('Locked documents cannot change company.');
        }

        $this->attributes['company_id'] = $value;
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
            'render_snapshot' => 'array',
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

    public function aiDraftNotes(): HasMany
    {
        return $this->hasMany(AiDraftNote::class);
    }
}

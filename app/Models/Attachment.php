<?php

namespace App\Models;

use App\Enums\AttachmentKind;
use App\Exceptions\TenantMismatch;
use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Attachment extends Model
{
    use AppendOnly, BelongsToCompany, HasFactory;

    protected $table = 'attachments';

    protected $guarded = [];

    protected array $companyUserKeys = ['uploaded_by'];

    protected static function booted(): void
    {
        static::creating(function (self $attachment): void {
            $model = match ($attachment->attachable_type) {
                'violation_report' => ViolationReport::class,
                'email_draft' => EmailDraft::class,
                'case' => DisciplinaryCase::class,
                'document' => Document::class,
                'report_assistant_session' => ReportAssistantSession::class,
                'case_file_intake' => CaseFileIntake::class,
                default => throw new TenantMismatch('Unknown attachment parent.'),
            };
            $parent = $model::withoutGlobalScopes()->find($attachment->attachable_id);
            if ($parent !== null) {
                if ($attachment->company_id !== null && (int) $attachment->company_id !== (int) $parent->company_id) {
                    throw new TenantMismatch('Attachment parent belongs to another company.');
                }
                $attachment->company_id = $parent->company_id;
            }
        });
    }

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'kind' => AttachmentKind::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function textReading(): HasOne
    {
        return $this->hasOne(AttachmentText::class);
    }
}

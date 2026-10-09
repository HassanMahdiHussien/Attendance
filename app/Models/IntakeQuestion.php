<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntakeQuestion extends Model
{
    use BelongsToCompany, HasFactory;

    protected $guarded = [];

    protected array $companyUserKeys = ['answered_by_user_id'];

    protected array $companyParents = ['report', 'emailDraft', 'disciplinaryCase', 'check'];

    protected static function booted(): void
    {
        static::saving(function (self $question): void {
            $parents = array_filter([$question->violation_report_id, $question->email_draft_id, $question->case_id], fn ($id) => $id !== null);
            if (count($parents) !== 1) {
                throw new \InvalidArgumentException('An intake question must have exactly one parent.');
            }
        });
    }

    protected function casts(): array
    {
        return ['answered_at' => 'datetime'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(ViolationReport::class, 'violation_report_id');
    }

    public function emailDraft(): BelongsTo
    {
        return $this->belongsTo(EmailDraft::class);
    }

    public function disciplinaryCase(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(IntakeCheck::class, 'intake_check_id');
    }
}

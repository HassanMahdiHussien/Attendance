<?php

namespace App\Models;

use App\Enums\EmailDraftStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Search\SearchText;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class EmailDraft extends Model
{
    use BelongsToCompany, HasFactory;

    protected $guarded = [];

    protected array $companyUserKeys = ['sender_user_id'];

    protected array $companyParents = ['branch'];

    protected static function booted(): void
    {
        static::saving(function (self $draft): void {
            $draft->search_subject = SearchText::normalize((string) $draft->subject);
        });
    }

    protected function casts(): array
    {
        return ['status' => EmailDraftStatus::class, 'received_at' => 'datetime',
            'body_truncated' => 'boolean', 'excluded_attachments' => 'array'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function disciplinaryCase(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }
}

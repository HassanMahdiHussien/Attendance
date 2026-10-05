<?php

namespace App\Models;

use App\Enums\ApprovalDecision;
use App\Enums\PoolRole;
use App\Exceptions\ImmutableRecordException;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class DocumentApproval extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'document_approvals';

    protected $guarded = [];

    protected array $companyUserKeys = ['approver_user_id'];

    public const UPDATED_AT = null;

    protected array $companyParents = ['document'];

    protected static function booted(): void
    {
        static::creating(function (self $approval): void {
            if (Document::withoutGlobalScopes()->find($approval->document_id)?->is_locked) {
                throw new ImmutableRecordException('Locked documents cannot receive approvals.');
            }
            if ($approval->decision === ApprovalDecision::Rejected && blank($approval->comment)) {
                throw ValidationException::withMessages(['comment' => 'A rejection comment is required.']);
            }
        });
        static::updating(function (self $approval): void {
            if (array_keys($approval->getDirty()) !== ['invalidated_at']
                || $approval->getRawOriginal('invalidated_at') !== null || $approval->invalidated_at === null) {
                throw new ImmutableRecordException('Only approval invalidation is permitted.');
            }
        });
        static::deleting(fn () => throw new ImmutableRecordException('Approvals cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'role_category' => PoolRole::class,
            'decision' => ApprovalDecision::class,
            'languages_present' => 'array',
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

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    public function poolMember(): BelongsTo
    {
        return $this->belongsTo(CommitteePoolMember::class, 'committee_pool_member_id');
    }
}

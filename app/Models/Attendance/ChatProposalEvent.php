<?php

namespace App\Models\Attendance;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatProposalEvent extends Model
{
    use AppendOnly, BelongsToCompany, HasFactory;

    protected $table = 'attendance_chat_proposal_events';

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyParents = ['proposal'];

    protected array $companyUserKeys = ['actor_id'];

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(ChatProposal::class, 'proposal_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

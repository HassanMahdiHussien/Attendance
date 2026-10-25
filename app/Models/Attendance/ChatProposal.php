<?php

namespace App\Models\Attendance;

use App\Models\Company;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatProposal extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'attendance_chat_proposals';

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyParents = ['conversation', 'turn'];

    protected array $companyUserKeys = ['decided_by'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'result' => 'array', 'decided_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function turn(): BelongsTo
    {
        return $this->belongsTo(ChatTurn::class, 'turn_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ChatProposalEvent::class, 'proposal_id');
    }
}

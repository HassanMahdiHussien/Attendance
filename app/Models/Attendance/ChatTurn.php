<?php

namespace App\Models\Attendance;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatTurn extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'attendance_chat_turns';

    protected $guarded = [];

    protected array $companyParents = ['conversation'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'turn_id');
    }

    public function toolCalls(): HasMany
    {
        return $this->hasMany(ChatToolCall::class, 'turn_id');
    }
}

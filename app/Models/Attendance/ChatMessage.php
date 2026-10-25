<?php

namespace App\Models\Attendance;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    use AppendOnly, BelongsToCompany, HasFactory;

    protected $table = 'attendance_chat_messages';

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyParents = ['conversation', 'turn'];

    protected function casts(): array
    {
        return ['content' => 'array', 'created_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function turn(): BelongsTo
    {
        return $this->belongsTo(ChatTurn::class, 'turn_id');
    }
}

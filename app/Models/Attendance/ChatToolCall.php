<?php

namespace App\Models\Attendance;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatToolCall extends Model
{
    use AppendOnly, BelongsToCompany, HasFactory;

    protected $table = 'attendance_chat_tool_calls';

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyParents = ['turn'];

    protected function casts(): array
    {
        return ['input' => 'array', 'result' => 'array', 'is_error' => 'boolean', 'created_at' => 'datetime'];
    }

    public function turn(): BelongsTo
    {
        return $this->belongsTo(ChatTurn::class, 'turn_id');
    }
}

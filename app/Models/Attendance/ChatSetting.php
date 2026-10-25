<?php

namespace App\Models\Attendance;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatSetting extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'attendance_chat_settings';

    protected $guarded = [];

    protected array $companyUserKeys = ['updated_by'];

    protected function casts(): array
    {
        return ['chat_changes_enabled' => 'boolean'];
    }
}

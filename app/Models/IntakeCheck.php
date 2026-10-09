<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntakeCheck extends Model
{
    use BelongsToCompany, HasFactory;

    protected $guarded = [];

    protected array $companyUserKeys = ['user_id'];

    protected array $companyParents = ['generationLog'];

    protected function casts(): array
    {
        return ['input' => 'array', 'result' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function generationLog(): BelongsTo
    {
        return $this->belongsTo(AiGenerationLog::class, 'ai_generation_log_id');
    }
}

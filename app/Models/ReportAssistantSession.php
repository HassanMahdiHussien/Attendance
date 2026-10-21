<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportAssistantSession extends Model
{
    use BelongsToCompany, HasFactory;

    protected $guarded = [];

    protected array $companyUserKeys = ['user_id'];

    protected array $companyParents = ['report', 'generationLog'];

    protected function casts(): array
    {
        return ['transcript' => 'array', 'facts' => 'array', 'confirmed_fields' => 'array'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(ViolationReport::class, 'violation_report_id');
    }

    public function generationLog(): BelongsTo
    {
        return $this->belongsTo(AiGenerationLog::class, 'ai_generation_log_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

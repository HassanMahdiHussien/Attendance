<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResponseTranscription extends Model
{
    use AppendOnly, BelongsToCompany, HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    protected array $companyParents = ['disciplinaryCase', 'attachment', 'generationLog'];

    protected array $companyUserKeys = ['created_by'];

    protected function casts(): array
    {
        return ['illegible' => 'boolean'];
    }

    public function disciplinaryCase(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    public function generationLog(): BelongsTo
    {
        return $this->belongsTo(AiGenerationLog::class, 'ai_generation_log_id');
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiDraftNote extends Model
{
    use AppendOnly, BelongsToCompany, HasFactory;

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyParents = ['disciplinaryCase', 'document', 'generationLog'];

    protected function casts(): array
    {
        return ['notes' => 'array'];
    }

    public function disciplinaryCase(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function generationLog(): BelongsTo
    {
        return $this->belongsTo(AiGenerationLog::class, 'ai_generation_log_id');
    }
}

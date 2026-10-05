<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Deadline-alert outbox row. CaseAlertDispatcher writes it with row-level claims; the model exists so
 * the table is covered by tenant scoping and the isolation tests like every other tenant table.
 */
class CaseAlert extends Model
{
    use BelongsToCompany, HasFactory;

    protected $guarded = [];

    protected array $companyParents = ['disciplinaryCase'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function disciplinaryCase(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }
}

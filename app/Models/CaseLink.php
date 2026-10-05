<?php

namespace App\Models;

use App\Enums\CaseLinkType;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseLink extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'case_links';

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyParents = ['disciplinaryCase'];

    protected function casts(): array
    {
        return [
            'link_type' => CaseLinkType::class,
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

    public function linkedCase(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'linked_case_id');
    }
}

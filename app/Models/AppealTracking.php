<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppealTracking extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'appeal_trackings';

    protected $guarded = [];

    protected array $companyUserKeys = ['logged_by'];

    protected array $companyParents = ['disciplinaryCase'];

    protected function casts(): array
    {
        return [
            'appeal_filed' => 'boolean',
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

    public function logger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by');
    }
}

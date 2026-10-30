<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchAlias extends Model
{
    use BelongsToCompany, Concerns\Audited;

    protected $guarded = [];

    protected array $companyParents = ['branch'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}

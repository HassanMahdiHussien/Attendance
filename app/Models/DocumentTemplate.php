<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentTemplate extends Model
{
    use AppendOnly, BelongsToCompany, HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    protected array $companyUserKeys = ['created_by'];

    protected function casts(): array
    {
        return ['blocks' => 'array', 'options' => 'array'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}

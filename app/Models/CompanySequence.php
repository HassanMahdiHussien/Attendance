<?php

namespace App\Models;

use App\Enums\SequenceKey;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanySequence extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'company_sequences';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sequence_key' => SequenceKey::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}

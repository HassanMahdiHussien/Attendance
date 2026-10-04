<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyHrPartner extends Model
{
    use HasFactory;

    protected $table = 'company_hr_partner';

    protected $guarded = [];

    public $timestamps = false;

    public $incrementing = false;

    protected function casts(): array
    {
        return ['full_case_access' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function hrPartner(): BelongsTo
    {
        return $this->belongsTo(HrPartner::class, 'hr_partner_id');
    }
}

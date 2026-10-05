<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyLawFirmPartner extends Model
{
    use HasFactory;

    protected $table = 'company_law_firm_partner';

    protected $guarded = [];

    public $timestamps = false;

    public $incrementing = false;

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function lawFirmPartner(): BelongsTo
    {
        return $this->belongsTo(LawFirmPartner::class, 'law_firm_partner_id');
    }
}

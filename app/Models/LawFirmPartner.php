<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LawFirmPartner extends Model
{
    use Concerns\Audited, HasFactory;

    protected $table = 'law_firm_partners';

    protected $guarded = [];
}

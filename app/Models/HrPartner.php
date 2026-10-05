<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HrPartner extends Model
{
    use Concerns\Audited, HasFactory;

    protected $table = 'hr_partners';

    protected $guarded = [];
}

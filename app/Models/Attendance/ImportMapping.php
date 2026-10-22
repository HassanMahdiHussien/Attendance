<?php

namespace App\Models\Attendance;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImportMapping extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'attendance_import_mappings';

    protected $guarded = [];

    protected array $companyUserKeys = ['updated_by'];

    protected function casts(): array
    {
        return ['headers' => 'array', 'mapping' => 'array'];
    }
}

<?php

namespace App\Models\Attendance;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ImportDraft extends Model
{
    use BelongsToCompany;

    protected $table = 'attendance_import_drafts';

    protected $guarded = [];

    protected array $companyUserKeys = ['uploaded_by'];

    protected function casts(): array
    {
        return ['headers' => 'array', 'mapping' => 'array', 'analysis' => 'array', 'export_at' => 'datetime'];
    }
}

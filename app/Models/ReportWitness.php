<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportWitness extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'report_witnesses';

    protected $guarded = [];

    protected array $companyParents = ['violationReport'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function violationReport(): BelongsTo
    {
        return $this->belongsTo(ViolationReport::class, 'violation_report_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}

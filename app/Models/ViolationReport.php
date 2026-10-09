<?php

namespace App\Models;

use App\Enums\ReportSource;
use App\Enums\ReportStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Search\SearchText;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ViolationReport extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'violation_reports';

    protected $guarded = [];

    protected array $companyUserKeys = ['reporter_user_id'];

    protected array $companyParents = ['branch', 'violationType'];

    protected static function booted(): void
    {
        static::saving(function (self $report): void {
            $names = $report->violation_type_id ? ViolationType::query()->find($report->violation_type_id)?->names ?? [] : [];
            $report->search_text = SearchText::normalize((string) $report->violation_type.' '.implode(' ', $names).' '.(string) $report->place_freetext);
        });
    }

    protected function casts(): array
    {
        return [
            'source' => ReportSource::class,
            'status' => ReportStatus::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function violationType(): BelongsTo
    {
        return $this->belongsTo(ViolationType::class, 'violation_type_id');
    }

    public function allegedResponsibleEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'alleged_responsible_employee_id');
    }

    public function disciplinaryCase(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }

    public function witnesses(): HasMany
    {
        return $this->hasMany(ReportWitness::class);
    }

    public function intakeQuestions(): HasMany
    {
        return $this->hasMany(IntakeQuestion::class, 'violation_report_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}

<?php

namespace App\Models;

use App\Enums\CaseStatus;
use App\Enums\DefenseAcceptance;
use App\Enums\Jurisdiction;
use App\Enums\Outcome;
use App\Enums\SequenceKey;
use App\Exceptions\ImmutableRecordException;
use App\Models\Concerns\BelongsToCompany;
use App\Services\SequenceService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class DisciplinaryCase extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'cases';

    protected $guarded = [];

    protected $attributes = ['status' => 'draft'];

    protected array $companyUserKeys = ['opened_by_user_id', 'assigned_hr_user_id', 'legal_review_requested_by', 'employee_response_checked_by'];

    protected array $companyParents = ['branch'];

    protected static function booted(): void
    {
        static::creating(function (self $case): void {
            if (blank($case->case_number)) {
                $case->case_number = app(SequenceService::class)->next((int) $case->company_id, SequenceKey::CaseNumber);
            }
        });
        static::saving(function (self $case): void {
            if (! $case->exists) {
                $branch = Branch::withoutGlobalScopes()->find($case->branch_id);
                if ($branch !== null) {
                    if ($case->jurisdiction_at_opening === null) {
                        $case->jurisdiction_at_opening = $branch->jurisdiction;
                    } elseif ($case->jurisdiction_at_opening !== $branch->jurisdiction) {
                        throw ValidationException::withMessages(['jurisdiction_at_opening' => 'Jurisdiction must match the opening branch.']);
                    }
                }
            } elseif ($case->isDirty('jurisdiction_at_opening')) {
                throw ValidationException::withMessages(['jurisdiction_at_opening' => 'Opening jurisdiction cannot change.']);
            }
            if ($case->status !== CaseStatus::Draft && $case->law_version_id === null) {
                throw ValidationException::withMessages(['law_version_id' => 'A law version is required after draft.']);
            }
            if ($case->exists && $case->getRawOriginal('status') !== 'draft'
                && $case->isDirty('law_version_id')) {
                throw ValidationException::withMessages(['law_version_id' => 'The law version cannot change after draft.']);
            }
        });
        static::deleting(fn () => throw new ImmutableRecordException('Cases cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'status' => CaseStatus::class,
            'jurisdiction_at_opening' => Jurisdiction::class,
            'outcome' => Outcome::class,
            'defense_acceptance' => DefenseAcceptance::class,
            'include_prior_record' => 'boolean',
            'is_sensitive' => 'boolean',
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

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function violationReport(): BelongsTo
    {
        return $this->belongsTo(ViolationReport::class, 'violation_report_id');
    }

    public function lawVersion(): BelongsTo
    {
        return $this->belongsTo(Law::class, 'law_version_id');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function assignedHrUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_hr_user_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function allegedResponsibleEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'alleged_responsible_employee_id');
    }

    public function legalReviewRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'legal_review_requested_by');
    }

    public function committeeMandate(): BelongsTo
    {
        return $this->belongsTo(CommitteeMandate::class, 'committee_mandate_id');
    }

    public function responseChecker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_response_checked_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'case_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CaseEvent::class, 'case_id');
    }

    public function witnesses(): HasMany
    {
        return $this->hasMany(CaseWitness::class, 'case_id');
    }
}

<?php

namespace App\Models;

use App\Enums\CaseStatus;
use App\Enums\DefenseAcceptance;
use App\Enums\Jurisdiction;
use App\Enums\Outcome;
use App\Enums\SequenceKey;
use App\Exceptions\ImmutableRecordException;
use App\Models\Concerns\BelongsToCompany;
use App\Services\Cases\CaseWorkflow;
use App\Services\SequenceService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Validation\ValidationException;

class DisciplinaryCase extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'cases';

    protected $guarded = [];

    protected $attributes = ['status' => 'draft'];

    protected array $companyUserKeys = ['opened_by_user_id', 'assigned_hr_user_id', 'legal_review_requested_by',
        'legal_review_cancelled_by', 'employee_response_checked_by'];

    protected array $companyParents = ['branch', 'violationType', 'incident'];

    protected static function booted(): void
    {
        static::creating(function (self $case): void {
            if (blank($case->case_number)) {
                $case->case_number = app(SequenceService::class)->next((int) $case->company_id, SequenceKey::CaseNumber);
            }
        });
        static::saving(function (self $case): void {
            if ($case->exists && $case->isDirty('incident_id')) {
                throw new ImmutableRecordException('Incident membership cannot be changed.');
            }
            if ($case->exists && $case->isDirty('case_specific_description')
                && $case->getRawOriginal('defense_letter_issued_at') !== null) {
                throw new ImmutableRecordException('Issued case description cannot be changed.');
            }
            if (! $case->exists) {
                $branch = Branch::withoutGlobalScopes()->find($case->branch_id);
                if ($branch !== null) {
                    if ($case->jurisdiction_at_opening === null) {
                        $case->jurisdiction_at_opening = $branch->jurisdiction;
                    } elseif ($case->jurisdiction_at_opening !== $branch->jurisdiction) {
                        throw ValidationException::withMessages(['jurisdiction_at_opening' => __('messages.case.jurisdiction_branch_mismatch')]);
                    }
                }
            } elseif ($case->isDirty('jurisdiction_at_opening')) {
                throw ValidationException::withMessages(['jurisdiction_at_opening' => __('messages.case.jurisdiction_immutable_after_open')]);
            }
            if ($case->exists && $case->isDirty('status') && ! CaseWorkflow::isWritingStatus()) {
                throw new ImmutableRecordException('Case status changes only through the workflow.');
            }
            if ($case->status !== CaseStatus::Draft && $case->law_version_id === null) {
                throw ValidationException::withMessages(['law_version_id' => __('messages.case.law_version_required')]);
            }
            if ($case->exists && $case->getRawOriginal('status') !== 'draft'
                && $case->isDirty('law_version_id')) {
                throw ValidationException::withMessages(['law_version_id' => __('messages.case.law_version_immutable')]);
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
            'violation_time_known' => 'boolean',
            'is_sensitive' => 'boolean',
            'session_majority_reached' => 'boolean',
            'defense_letter_issued_at' => 'datetime',
            'decision_issued_at' => 'datetime',
            'ai_request_started_at' => 'datetime',
            'employee_response_checked_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
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

    public function intakeQuestions(): HasMany
    {
        return $this->hasMany(IntakeQuestion::class, 'case_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function violationType(): BelongsTo
    {
        return $this->belongsTo(ViolationType::class, 'violation_type_id');
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

    public function signatures(): HasMany
    {
        return $this->hasMany(Signature::class, 'case_id');
    }

    public function witnesses(): HasMany
    {
        return $this->hasMany(CaseWitness::class, 'case_id');
    }

    public function confirmedResponseText(): ?string
    {
        return $this->employee_response_checked_at !== null
            && $this->employee_response_origin !== null
            && $this->status !== CaseStatus::ResponseScannedUploaded
            ? $this->employee_response_text : null;
    }
}

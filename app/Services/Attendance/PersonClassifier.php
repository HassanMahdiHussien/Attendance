<?php

namespace App\Services\Attendance;

use App\Models\Attendance\DataIssue;
use App\Models\Attendance\Person;
use App\Models\Company;
use App\Models\Employee;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;

class PersonClassifier
{
    public function __construct(private DataIssues $issues, private AuditLogger $audit) {}

    public function classify(Company $company, string $code, string $department, ?int $batchId = null): Person
    {
        abort_unless(app(TenantContext::class)->companyId() === $company->id, 403);
        $employee = Employee::query()->where('company_id', $company->id)->whereRaw('BINARY employee_code = ?', [$code])->first();
        $nonEmployee = false;
        foreach (config('attendance.non_employee_department_patterns') as $pattern) {
            if (preg_match($pattern, $department) === 1) {
                $nonEmployee = true;
                break;
            }
        }
        $kind = $employee ? 'employee' : ($nonEmployee ? 'non_employee' : 'unknown');
        $person = Person::query()->firstOrNew(['company_id' => $company->id, 'person_code' => $code]);
        $changed = ! $person->exists || $person->kind !== $kind || $person->employee_id !== $employee?->id || $person->department_label !== $department;
        if ($changed) {
            $person->fill([
                'kind' => $kind, 'employee_id' => $employee?->id,
                'department_label' => $department, 'classification_reason' => $employee ? 'employee_code' : ($nonEmployee ? 'department_pattern' : 'unmatched'),
                'classified_at' => now(),
            ])->save();
            $this->audit->log('attendance.person.classified', $person, ['code' => $code, 'kind' => $kind]);
        }
        if ($kind === 'unknown') {
            $this->issues->open($company, 'unknown_person', 'person', $code, $batchId);
        } else {
            $issue = DataIssue::query()->where('company_id', $company->id)->where('open_key', 'unknown_person|person|'.$code)->first();
            if ($issue !== null) {
                $issue->update(['status' => 'resolved', 'open_key' => null, 'note' => __('messages.attendance_import.classified_resolution'), 'resolved_at' => now()]);
                $this->audit->log('attendance.issue.resolved', $issue, ['type' => 'unknown_person', 'subject_key' => $code]);
            }
        }

        return $person;
    }
}

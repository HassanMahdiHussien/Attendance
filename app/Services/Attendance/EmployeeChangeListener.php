<?php

namespace App\Services\Attendance;

use App\Events\EmployeeVersionChanged;
use App\Jobs\Attendance\ProcessAttendanceJob;
use App\Models\Attendance\Person;
use App\Models\Attendance\Transaction;
use App\Models\Company;
use App\Models\EmployeeVersion;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class EmployeeChangeListener
{
    public function handle(EmployeeVersionChanged $event): void
    {
        $companyId = (int) $event->employee->company_id;
        $employeeId = (int) $event->employee->id;
        $code = (string) $event->employee->employee_code;
        // History emits inside its transaction; classification must see the committed employee.
        DB::afterCommit(function () use ($companyId, $employeeId, $code): void {
            $company = Company::findOrFail($companyId);
            app(TenantContext::class)->runForCompany($company, function () use ($company, $employeeId, $code): void {
                $person = Person::query()->where('company_id', $company->id)->where('person_code', $code)->first();
                if ($person === null) {
                    return;
                }
                app(PersonClassifier::class)->classify($company, $code, $person->department_label ?? '');
                $transactions = Transaction::query()->where('company_id', $company->id)->where('person_code', $code);
                $first = (clone $transactions)->min('punched_at');
                $last = (clone $transactions)->max('punched_at');
                if ($first === null || $last === null) {
                    return;
                }
                $from = CarbonImmutable::parse($first)->subDay()->startOfDay()->toDateTimeString();
                $to = CarbonImmutable::parse($last)->addDay()->endOfDay()->toDateTimeString();
                $versionId = EmployeeVersion::query()->where('employee_id', $employeeId)->max('id');
                ProcessAttendanceJob::dispatch($company->id, [$code], $from, $to, 'employees', null, null, (string) $versionId)->afterCommit();
            });
        });
    }
}

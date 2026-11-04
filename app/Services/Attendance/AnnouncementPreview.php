<?php

namespace App\Services\Attendance;

use App\Models\Attendance\ManualPunch;
use App\Models\Attendance\Punch;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeVersion;
use App\Services\Employees\EmployeeHistory;

class AnnouncementPreview
{
    public function forDate(Company $company, string $date, array $scope): array
    {
        $branches = Branch::query()->where('company_id', $company->id)->pluck('name', 'id');
        $selected = [];
        $history = app(EmployeeHistory::class);
        Employee::query()->where('company_id', $company->id)->orderBy('id')->chunkById(250,
            function ($employees) use ($history, $date, $scope, &$selected): void {
                $versions = EmployeeVersion::query()->where('company_id', $employees->first()->company_id)
                    ->whereIn('employee_id', $employees->pluck('id'))->whereDate('effective_from', '<=', $date)
                    ->orderBy('effective_from')->orderBy('id')->get()->groupBy('employee_id');
                foreach ($employees as $employee) {
                    $historyRows = $versions->get($employee->id, collect());
                    $values = $historyRows->isEmpty() ? $history->valuesFrom($employee)
                        : array_fill_keys(EmployeeHistory::FIELDS, null);
                    foreach ($historyRows as $version) {
                        foreach ($version->changes as $field => $value) {
                            $values[$field] = $value;
                        }
                    }
                    $values['employee_id'] = $employee->id;
                    if (($values['hire_date'] !== null && substr((string) $values['hire_date'], 0, 10) > $date)
                        || ($employee->exit_status->value === 'confirmed' && $employee->last_working_day !== null
                            && $employee->last_working_day->toDateString() < $date)) {
                        continue;
                    }
                    if ($this->matches($values, $scope)) {
                        $selected[$employee->employee_code] = ['id' => $employee->id, 'code' => $employee->employee_code,
                            'name' => $values['name'] ?: $employee->name, 'branch_id' => $values['branch_id']];
                    }
                }
            });
        $punched = Punch::query()->where('company_id', $company->id)
            ->whereBetween('punched_at', [$date.' 00:00:00', $date.' 23:59:59'])
            ->whereIn('person_code', array_keys($selected))
            ->where('status', 'used')
            ->whereIn('role', ['check_in', 'check_out'])
            ->whereNull('superseded_by_run_id')->distinct()->pluck('person_code')->flip();
        $manualIds = ManualPunch::query()->where('company_id', $company->id)
            ->whereBetween('punched_at', [$date.' 00:00:00', $date.' 23:59:59'])
            ->whereIn('employee_id', array_column($selected, 'id'))
            ->whereIn('role', ['check_in', 'check_out'])->whereNull('reverses_id')
            ->whereNotIn('id', ManualPunch::query()->where('company_id', $company->id)
                ->whereNotNull('reverses_id')->select('reverses_id'))
            ->distinct()->pluck('employee_id')->flip();
        $withPunches = 0;
        $byBranch = [];
        foreach ($selected as $person) {
            $hasPunch = $punched->has($person['code']) || $manualIds->has($person['id']);
            $withPunches += (int) $hasPunch;
            $branch = $branches[$person['branch_id']] ?? __('messages.announcement.unknown_branch');
            $byBranch[$branch]['total'] = ($byBranch[$branch]['total'] ?? 0) + 1;
            $byBranch[$branch]['with_punches'] = ($byBranch[$branch]['with_punches'] ?? 0) + (int) $hasPunch;
        }

        return ['count' => count($selected), 'with_punches' => $withPunches,
            'without_punches' => count($selected) - $withPunches,
            'by_branch' => $byBranch, 'sample' => array_map(fn (array $person): array => [
                'code' => $person['code'], 'name' => $person['name'],
            ], array_slice(array_values($selected), 0, 10))];
    }

    private function matches(array $values, array $scope): bool
    {
        if (! ($scope['everyone'] ?? false) && (($scope['departments'] ?? []) !== [] || ($scope['employee_ids'] ?? []) !== [])) {
            $department = mb_strtolower(trim((string) ($values['department'] ?? '')));
            $departments = array_map(fn (string $value): string => mb_strtolower(trim($value)), $scope['departments'] ?? []);
            if (! in_array($values['employee_id'], $scope['employee_ids'] ?? [], true)
                && ! in_array($department, $departments, true)) {
                return false;
            }
        }

        $entities = array_map(fn (string $value): string => mb_strtolower(trim($value)), $scope['legal_entities'] ?? []);

        return ($entities === [] || in_array(mb_strtolower(trim((string) ($values['legal_entity'] ?? ''))), $entities, true))
            && (($scope['branch_ids'] ?? []) === [] || in_array((int) $values['branch_id'], $scope['branch_ids'], true))
            && (($scope['work_patterns'] ?? []) === [] || in_array($values['work_pattern'], $scope['work_patterns'], true));
    }
}

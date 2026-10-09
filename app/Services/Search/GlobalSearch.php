<?php

namespace App\Services\Search;

use App\Enums\UserRole;
use App\Models\User;
use App\Policies\EmployeeProfilePolicy;
use App\Support\Access\CaseVisibility;
use App\Support\Access\EmailDraftVisibility;
use App\Support\Access\ViolationReportVisibility;
use App\Support\Format\Ui;
use App\Support\Format\Violation;
use App\Support\Search\SearchText;

class GlobalSearch
{
    public function allowed(User $viewer): bool
    {
        return $viewer->is_active && ! in_array($viewer->primary_role, [UserRole::Reporter, UserRole::DeptManager], true)
            && ($viewer->company_id === null || $viewer->company?->is_active);
    }

    public function for(User $viewer, string $query): array
    {
        $groups = ['cases' => [], 'employees' => [], 'reports' => []];
        $term = SearchText::normalize($query);
        if (! $this->allowed($viewer) || mb_strlen($term) < 2) {
            return $groups;
        }
        $like = '%'.addcslashes($term, '%_\\').'%';
        $prefix = addcslashes($term, '%_\\').'%';
        $number = ctype_digit($term) ? (int) $term : null;

        $cases = CaseVisibility::for($viewer)
            ->join('employees as search_employees', function ($join): void {
                $join->on('search_employees.id', '=', 'cases.employee_id')
                    ->on('search_employees.company_id', '=', 'cases.company_id');
            })
            ->where(function ($scope) use ($like, $prefix, $number): void {
                $scope->where('search_employees.search_name', 'like', $like)
                    ->orWhere('search_employees.employee_code', 'like', $prefix)
                    ->orWhere('cases.violation_type', 'like', $like)
                    ->orWhereExists(fn ($types) => $types->selectRaw('1')->from('violation_types as search_types')
                        ->whereColumn('search_types.id', 'cases.violation_type_id')
                        ->whereColumn('search_types.company_id', 'cases.company_id')
                        ->whereRaw('LOWER(CAST(search_types.names AS CHAR)) LIKE ?', [$like]));
                if ($number !== null) {
                    $scope->orWhere('search_employees.employee_code', 'like', 'EMP-'.$prefix)
                        ->orWhere('cases.case_number', $number)
                        ->orWhereRaw("(CASE WHEN cases.case_number < 10000 THEN LPAD(cases.case_number, 4, '0') ELSE CAST(cases.case_number AS CHAR) END) LIKE ?", [$prefix]);
                }
            })
            ->select('cases.*', 'search_employees.name as employee_name')
            ->orderByRaw('CASE WHEN cases.case_number = ? THEN 0 WHEN search_employees.employee_code IN (?, ?) THEN 1 ELSE 2 END', [$number ?? -1, $term, 'EMP-'.$term])
            ->orderByDesc('cases.created_at')->limit(8)->get();
        $groups['cases'] = $cases->map(fn ($case) => [
            'label' => '#'.str_pad((string) $case->case_number, 4, '0', STR_PAD_LEFT).' · '.$case->employee_name,
            'detail' => Violation::name($case).' · '.Ui::stage($case->status), 'url' => route('cases.show', $case->id),
        ])->all();

        $policy = app(EmployeeProfilePolicy::class);
        if ($policy->search($viewer)) {
            $employees = $policy->scope($viewer)
                ->where(function ($scope) use ($like, $prefix, $number): void {
                    $scope->where('employees.search_name', 'like', $like)
                        ->orWhere('employees.employee_code', 'like', $prefix);
                    if ($number !== null) {
                        $scope->orWhere('employees.employee_code', 'like', 'EMP-'.$prefix);
                    }
                })
                ->orderByRaw('CASE WHEN employees.employee_code IN (?, ?) THEN 0 ELSE 1 END', [$term, 'EMP-'.$term])
                ->orderBy('employees.name')->limit(8)->get();
            $groups['employees'] = $employees->map(fn ($employee) => [
                'label' => $employee->name, 'detail' => $employee->employee_code.' · '.$employee->department,
                'url' => route('employees.show', $employee),
            ])->all();
        }

        if (in_array($viewer->primary_role, [UserRole::CompanyAdmin, UserRole::HrStaff], true)) {
            $reports = ViolationReportVisibility::inbox($viewer)->where(fn ($scope) => $scope
                ->where('search_text', 'like', $like)
                ->orWhereExists(fn ($types) => $types->selectRaw('1')->from('violation_types as search_types')
                    ->whereColumn('search_types.id', 'violation_reports.violation_type_id')
                    ->whereColumn('search_types.company_id', 'violation_reports.company_id')
                    ->whereRaw('LOWER(CAST(search_types.names AS CHAR)) LIKE ?', [$like])))
                ->latest()->limit(8)->get()->map(fn ($report) => [
                    'label' => Violation::name($report), 'detail' => $report->place_freetext ?? '',
                    'url' => route('reports.inbox'), 'time' => $report->created_at,
                ]);
            $drafts = EmailDraftVisibility::inbox($viewer)->where('search_subject', 'like', $like)
                ->latest('received_at')->limit(8)->get()->map(fn ($draft) => [
                    'label' => $draft->subject, 'detail' => __('messages.search.email_draft'),
                    'url' => route('reports.inbox'), 'time' => $draft->received_at,
                ]);
            $groups['reports'] = $reports->concat($drafts)->sortByDesc('time')->take(8)
                ->map(fn ($item) => collect($item)->except('time')->all())->values()->all();
        }

        return $groups;
    }
}

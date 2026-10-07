<?php

namespace App\Services\Statistics;

use App\Documents\IraqiDate;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Policies\EmployeeProfilePolicy;
use App\Services\Cases\Deadlines;
use App\Services\Dashboards\CaseMetrics;
use App\Services\Dashboards\DashboardFilters;
use App\Services\Employees\EmployeeProfile;
use App\Support\Access\CaseVisibility;
use App\Support\Format\ArabicDigits;
use App\Support\Format\OutcomeLabel;
use App\Support\Format\StageIndicator;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ReportBuilder
{
    public function __construct(private CaseMetrics $metrics, private Deadlines $deadlines,
        private EmployeeProfile $profiles, private EmployeeProfilePolicy $employeePolicy) {}

    public function validate(User $viewer, string $type, array $input): array
    {
        abort_unless($viewer->is_active && ($viewer->company_id === null || $viewer->company?->is_active) &&
            in_array($viewer->primary_role, [UserRole::CompanyAdmin,
                UserRole::HrStaff, UserRole::PlatformAdmin, UserRole::HrPartnerStaff], true), 403);
        $data = validator($input, [
            'company_id' => ['nullable', 'integer', 'min:1'],
            'branch' => ['nullable', 'integer', 'min:1'],
            'period' => ['required', 'in:month,quarter,custom'],
            'year' => ['required_unless:period,custom', 'nullable', 'integer', 'between:2000,2100'],
            'month' => ['required_if:period,month', 'nullable', 'integer', 'between:1,12'],
            'quarter' => ['required_if:period,quarter', 'nullable', 'integer', 'between:1,4'],
            'from' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d'],
            'to' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'employee_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();
        abort_unless(in_array($type, ['periodic', 'employee', 'compliance', 'hotspots'], true), 404);
        $companyId = isset($data['company_id']) ? (int) $data['company_id'] : null;
        if ($viewer->primary_role === UserRole::PlatformAdmin) {
            abort_unless($companyId !== null || in_array($type, ['periodic', 'hotspots'], true), 403);
        } else {
            if ($viewer->primary_role !== UserRole::HrPartnerStaff) {
                abort_unless($companyId === null || $companyId === (int) $viewer->company_id, 404);
                $companyId = (int) $viewer->company_id;
            }
            abort_unless($companyId !== null, 403);
        }
        if ($companyId !== null) {
            $company = Company::query()->findOrFail($companyId);
            abort_unless($viewer->primary_role === UserRole::PlatformAdmin || $company->is_active, 404);
        } else {
            abort_unless($viewer->primary_role === UserRole::PlatformAdmin && empty($data['branch']), 403);
        }
        if ($viewer->primary_role === UserRole::HrPartnerStaff) {
            $partner = DB::table('company_hr_partner')->where('company_id', $companyId)
                ->where('hr_partner_id', $viewer->hr_partner_id)->first();
            abort_unless($partner && (in_array($type, ['periodic', 'hotspots'], true) || $partner->full_case_access), 404);
        }
        if (in_array($type, ['employee', 'compliance'], true)) {
            abort_unless($this->employeePolicy->companyAllowed($viewer, $companyId), 404);
        }
        $start = match ($data['period']) {
            'month' => CarbonImmutable::create((int) $data['year'], (int) $data['month'], 1, 0, 0, 0, 'Asia/Baghdad'),
            'quarter' => CarbonImmutable::create((int) $data['year'], ((int) $data['quarter'] - 1) * 3 + 1, 1, 0, 0, 0, 'Asia/Baghdad'),
            'custom' => CarbonImmutable::parse($data['from'], 'Asia/Baghdad')->startOfDay(),
        };
        $end = match ($data['period']) {
            'month' => $start->addMonth(), 'quarter' => $start->addMonths(3),
            'custom' => CarbonImmutable::parse($data['to'], 'Asia/Baghdad')->addDay()->startOfDay(),
        };
        DashboardFilters::fromInput($viewer, ['period' => 'custom', 'from' => $start->toDateString(),
            'to' => $end->subDay()->toDateString(), 'branch' => $data['branch'] ?? null], $companyId);
        if ($type === 'employee') {
            abort_unless(isset($data['employee_id']), 422);
            $employee = Employee::withoutGlobalScopes()->findOrFail($data['employee_id']);
            abort_unless((int) $employee->company_id === $companyId && $this->employeePolicy->view($viewer, $employee), 404);
        }

        return ['company_id' => $companyId, 'branch' => isset($data['branch']) ? (int) $data['branch'] : null,
            'period' => 'custom', 'from' => $start->toDateString(), 'to' => $end->subDay()->toDateString(),
            'employee_id' => $type === 'employee' ? (int) $data['employee_id'] : null];
    }

    public function build(User $viewer, string $type, array $input, ?int $limit = 1000): array
    {
        $params = $this->validate($viewer, $type, $input);
        $work = fn () => $this->buildInContext($viewer, $type, $input, $limit);

        return $params['company_id'] === null ? app(TenantContext::class)->runAcrossAllCompanies($work)
            : app(TenantContext::class)->runForCompany($params['company_id'], $work);
    }

    private function buildInContext(User $viewer, string $type, array $input, ?int $limit): array
    {
        $this->assertCurrentVisibility($viewer, $input);
        $params = $this->validate($viewer, $type, $input);
        $filters = DashboardFilters::fromInput($viewer, ['period' => 'custom', 'from' => $params['from'],
            'to' => $params['to'], 'branch' => $params['branch']], $params['company_id']);
        $partnerAggregate = $viewer->primary_role === UserRole::HrPartnerStaff && in_array($type, ['periodic', 'hotspots'], true);
        $aggregate = $partnerAggregate;
        $title = ['periodic' => 'الملخص الدوري', 'employee' => 'تقرير الموظف',
            'compliance' => 'تقرير الالتزام بالمواعيد', 'hotspots' => 'تقرير بؤر التكرار'][$type];
        $report = ['type' => $type, 'title' => $title, 'parameters' => $params,
            'company' => $params['company_id'] ? Company::find($params['company_id']) : null,
            'summary' => [], 'sections' => [], 'truncated' => false];
        if ($type === 'employee') {
            $employee = Employee::withoutGlobalScopes()->findOrFail($params['employee_id']);
            $profile = $this->profiles->build($viewer, $employee);
            $report['summary'] = ['الموظف' => $employee->name, 'الرقم الوظيفي' => $employee->employee_code,
                'القسم' => $employee->department, 'الوظيفة' => $employee->position,
                'الفرع' => $employee->branch?->name, 'تاريخ التعيين' => $this->date($employee->hire_date),
                'الحالة المفتوحة' => $profile['open']?->case_number,
                'المرحلة' => $profile['open'] ? StageIndicator::label($profile['open']->status) : null,
                'إجمالي الحالات' => $profile['counts']['total'], 'الإنذارات' => $profile['counts']['warnings'],
                'آخر ١٢ شهراً' => $profile['counts']['last_year']];
            $report['sections'][] = $this->section('السجل الانضباطي', ['رقم الحالة', 'الموظف', 'تاريخ الواقعة', 'ISO', 'المخالفة', 'النتيجة', 'المستندات'],
                $profile['history']->map(fn ($case) => [$case->case_number, $employee->name,
                    $this->date($case->violation_time), $this->iso($case->violation_time), $case->violation_type,
                    OutcomeLabel::arabic($case->outcome), $case->documents->pluck('document_type.value')->implode('، ')])->all(), $limit, $report);
        } elseif ($type === 'compliance') {
            $rows = [];
            $totals = ['met' => 0, 'overdue' => 0, 'missed' => 0, 'open' => 0, 'not_started' => 0];
            foreach ($this->cases($viewer, $filters, $params['company_id'])
                ->with(['employee', 'events' => fn ($query) => $query->where('event_type', 'response_handoff_recorded')])
                ->orderBy('cases.id')->lazy(500) as $case) {
                $status = $this->deadlines->procedureStatus($case);
                $bucket = $status === null ? 'not_started' : (in_array($status, ['on_track', 'approaching'], true) ? 'open' : $status);
                $totals[$bucket]++;
                if ($limit === null || count($rows) < $limit) {
                    $rows[] = [$case->case_number, $case->employee?->name, $this->date($case->defense_letter_issued_at),
                        $this->date($this->deadlines->procedureDue($case)), $this->date($case->decision_issued_at ?? $case->closed_at),
                        $this->procedureLabel($status), $this->date($this->deadlines->responseDue($case)),
                        $this->responseLabel($this->deadlines->responseStatus($case)), $this->iso($case->violation_time)];
                } else {
                    $report['truncated'] = true;
                }
            }
            $report['summary'] = ['ضمن المهلة' => $totals['met'], 'تأخر الإنجاز' => $totals['overdue'],
                'فاتت المهلة' => $totals['missed'], 'قيد المهلة' => $totals['open'], 'لم يبدأ' => $totals['not_started']];
            $report['sections'][] = $this->section('الحالات', ['رقم الحالة', 'الموظف', 'كتاب الدفاع', 'مهلة ١٥ يوماً',
                'تاريخ الإنجاز', 'الإجراء', 'مهلة الرد', 'الرد', 'ISO'], $rows, $limit, $report);
        } else {
            if ($type === 'periodic') {
                $counts = $this->metrics->periodCounts($viewer, $filters, $aggregate, $params['company_id']);
                $report['summary'] = ['مفتوحة حتى نهاية الفترة' => $counts['open'], 'أغلقت في الفترة' => $counts['closed_month'],
                    'توصية فقط' => $counts['recommendation_only'], 'إنذار' => $counts['warning'],
                    'إنهاء خدمة' => $counts['termination'], 'متوسط أيام الإجراء' => $counts['average_days'],
                    'نسبة الإنجاز خلال ١٥ يوماً' => $counts['within15_percent']];
            }
            $hotspots = $this->metrics->hotspots($viewer, $filters, $aggregate, $params['company_id']);
            foreach ($hotspots as $group => $items) {
                $rows = array_map(fn ($item) => [$item['label'], $item['count'], $item['previous'], $item['delta']], $items);
                $report['sections'][] = $this->section($this->groupLabel($group), ['التصنيف', 'الفترة', 'الفترة السابقة', 'التغير'], $rows, $limit, $report);
            }
            if ($type === 'periodic' && ! $partnerAggregate) {
                $rows = [];
                foreach ($this->cases($viewer, $filters, $params['company_id'])->with('employee')
                    ->orderBy('cases.id')->lazy(500) as $case) {
                    $rows[] = [$case->case_number, $case->employee?->name, $this->date($case->violation_time),
                        $this->iso($case->violation_time), $case->violation_type,
                        OutcomeLabel::arabic($case->outcome)];
                    if ($limit !== null && count($rows) > $limit) {
                        break;
                    }
                }
                $report['sections'][] = $this->section('قائمة الحالات',
                    ['رقم الحالة', 'الموظف', 'تاريخ الواقعة', 'ISO', 'المخالفة', 'النتيجة'], $rows, $limit, $report);
            }
        }
        $report['partial'] = $viewer->primary_role === UserRole::HrStaff && ! $viewer->can('view_all_company_cases');

        return $report;
    }

    public function snapshotVisibility(User $viewer, string $type, array $params): array
    {
        if ($viewer->primary_role !== UserRole::HrStaff || $viewer->can('view_all_company_cases')) {
            return $params;
        }
        $query = CaseVisibility::for($viewer)->where('cases.company_id', $params['company_id']);
        if ($type === 'employee') {
            $query->where('cases.employee_id', $params['employee_id']);
        } else {
            $query->where('cases.violation_time', '>=', $params['from'])
                ->where('cases.violation_time', '<', CarbonImmutable::parse($params['to'])->addDay());
        }
        if ($params['branch']) {
            $query->where('cases.branch_id', $params['branch']);
        }
        $params['_visible_case_ids'] = $query->pluck('cases.id')->all();

        return $params;
    }

    public function assertCurrentVisibility(User $viewer, array $params): void
    {
        if (! isset($params['_visible_case_ids'])) {
            return;
        }
        abort_unless($viewer->primary_role === UserRole::HrStaff && $viewer->is_active, 404);
        $ids = $params['_visible_case_ids'];
        abort_unless(is_array($ids), 404);
        $visible = CaseVisibility::for($viewer)->where('cases.company_id', $params['company_id'])
            ->whereIn('cases.id', $ids)->count();
        abort_unless($visible === count($ids), 404);
    }

    private function cases(User $viewer, DashboardFilters $filters, ?int $company): Builder
    {
        return CaseVisibility::for($viewer)->where('cases.company_id', $company)
            ->where('cases.status', '!=', 'draft')->when($filters->branch,
                fn (Builder $query) => $query->where('cases.branch_id', $filters->branch))
            ->where('cases.violation_time', '>=', $filters->start)->where('cases.violation_time', '<', $filters->end);
    }

    private function section(string $title, array $headers, array $rows, ?int $limit, array &$report): array
    {
        if ($limit !== null && count($rows) > $limit) {
            $report['truncated'] = true;
            $rows = array_slice($rows, 0, $limit);
        }

        return compact('title', 'headers', 'rows');
    }

    private function procedureLabel(?string $status): string
    {
        return match ($status) {
            'met' => 'ضمن المهلة', 'overdue' => 'تأخر الإنجاز', 'missed' => 'فاتت المهلة',
            'on_track', 'approaching' => 'قيد المهلة', default => 'لم يبدأ',
        };
    }

    private function responseLabel(?string $status): string
    {
        return match ($status) {
            'met' => 'ضمن المهلة', 'missed' => 'فاتت المهلة',
            'on_track', 'approaching' => 'قيد المهلة', default => 'لم يبدأ',
        };
    }

    private function groupLabel(string $group): string
    {
        return ['branch' => 'الفروع', 'department' => 'الأقسام', 'location' => 'المواقع',
            'violation' => 'أنواع المخالفات', 'responsible' => 'الأشخاص المعنيون', 'employee' => 'الموظفون'][$group];
    }

    private function date(mixed $date): string
    {
        return $date ? ArabicDigits::convert(
            IraqiDate::format(CarbonImmutable::parse($date), 'ar')) : '—';
    }

    private function iso(mixed $date): string
    {
        return $date ? CarbonImmutable::parse($date)->toDateString() : '';
    }
}

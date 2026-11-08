<?php

namespace App\Filament\Admin\Pages\Attendance;

use App\Filament\Admin\Resources\Attendance\AttendancePage;
use App\Models\Branch;
use App\Models\Employee;
use App\Services\Attendance\Reporting\AttendanceExports;
use App\Services\Attendance\Reporting\ExportData;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Services\Attendance\Reporting\ResultCache;
use App\Services\Attendance\Reporting\TimeManagementDisplay;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as ArrayPaginator;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class TimeManagementPage extends AttendancePage
{
    use WithPagination;

    protected static ?string $slug = 'attendance/time-management';

    protected string $view = 'filament.admin.pages.attendance.time-management';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $branchId = '';

    #[Url]
    public string $department = '';

    #[Url]
    public string $tab = 'night';

    public bool $exportQueued = false;

    public static function getNavigationLabel(): string
    {
        return __('messages.export_ui.kinds.time_management');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        parent::mount();
        $previous = CarbonImmutable::now('Asia/Baghdad')->startOfMonth()->subMonth();
        $month = (string) request()->query('month', '');
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1) {
            // Older links carried a whole month.
            $previous = CarbonImmutable::parse($month.'-01');
        }
        $this->from = (string) request()->query('from', $previous->toDateString());
        $this->to = (string) request()->query('to', $previous->endOfMonth()->toDateString());
        $tab = request()->query('tab', 'night');
        $this->tab = in_array($tab, ['night', 'friday', 'holiday', 'totals'], true) ? $tab : 'night';
    }

    public function updatedFrom(): void
    {
        if (self::isDate($this->from) && self::isDate($this->to) && $this->to < $this->from) {
            $this->to = $this->from;
        }
        $this->resetDayPages();
        $this->exportQueued = false;
    }

    public function updatedTo(): void
    {
        if (self::isDate($this->from) && self::isDate($this->to) && $this->to < $this->from) {
            $this->from = $this->to;
        }
        $this->resetDayPages();
        $this->exportQueued = false;
    }

    private static function isDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
    }

    public function updatedBranchId(): void
    {
        $this->resetDayPages();
        $this->exportQueued = false;
    }

    public function updatedDepartment(): void
    {
        $this->resetDayPages();
        $this->exportQueued = false;
    }

    private function resetDayPages(): void
    {
        foreach (['night', 'friday', 'holiday'] as $type) {
            $this->resetPage($type.'Page');
        }
    }

    public function showNight(): void
    {
        $this->tab = 'night';
    }

    public function showFriday(): void
    {
        $this->tab = 'friday';
    }

    public function showHoliday(): void
    {
        $this->tab = 'holiday';
    }

    public function showTotals(): void
    {
        $this->tab = 'totals';
    }

    public function updatedTab(): void
    {
        abort_unless(in_array($this->tab, ['night', 'friday', 'holiday', 'totals'], true), 404);
    }

    public function branches(): array
    {
        return Branch::query()->where('company_id', $this->company()->id)->orderBy('name')->pluck('name', 'id')->all();
    }

    public function departments(): array
    {
        return Employee::query()->where('company_id', $this->company()->id)->whereNotNull('department')
            ->where('department', '!=', '')->distinct()->orderBy('department')->pluck('department')->all();
    }

    public function filters(): ReportFilters
    {
        $this->company();
        abort_unless(self::isDate($this->from) && self::isDate($this->to) && $this->from <= $this->to
            && CarbonImmutable::parse($this->from)->diffInDays(CarbonImmutable::parse($this->to)) <= 366, 422);
        $from = $this->from;
        $to = $this->to;

        return ReportFilters::fromArray($this->company(), [
            'from' => $from, 'to' => $to,
            'branch_ids' => $this->branchId === '' ? [] : [$this->branchId],
            'departments' => $this->department === '' ? [] : [$this->department],
        ]);
    }

    public function report(): array
    {
        return app(ResultCache::class)->remember('time_management_page_grouped', $this->filters(),
            fn (): array => app(ExportData::class)->timeManagement($this->filters()), translated: true);
    }

    public function daysPage(array $days, string $type): LengthAwarePaginator
    {
        abort_unless(in_array($type, ['night', 'friday', 'holiday'], true), 404);
        $pageName = $type.'Page';
        $page = $this->getPage($pageName);

        return new ArrayPaginator(array_slice($days, ($page - 1) * 50, 50), count($days), 50, $page,
            ['pageName' => $pageName]);
    }

    public function employeeUrls(array $report): array
    {
        $days = array_merge($report['night'], $report['friday'], $report['holiday']);
        $codes = array_values(array_unique(array_column($days, 'employee_code')));
        if ($codes === []) {
            return [];
        }

        return Employee::query()->where('company_id', $this->company()->id)->whereIn('employee_code', $codes)
            ->pluck('id', 'employee_code')->mapWithKeys(fn ($id, $code): array => [
                $code => route('employees.show', $id).'?'.http_build_query(['from' => $this->filters()->from, 'to' => $this->filters()->to]),
            ])->all();
    }

    public function summary(array $report): array
    {
        return app(TimeManagementDisplay::class)->summary($report);
    }

    public function totalRow(array $totals): array
    {
        return app(TimeManagementDisplay::class)->totalRow($totals);
    }

    public function requestExport(AttendanceExports $exports): void
    {
        $exports->request($this->company(), auth()->user(), 'time_management', $this->filters()->toArray(), app()->getLocale());
        $this->exportQueued = true;
    }
}

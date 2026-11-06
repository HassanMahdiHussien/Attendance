<?php

namespace App\Filament\Admin\Pages\Attendance;

use App\Filament\Admin\Resources\Attendance\AttendancePage;
use App\Models\Attendance\Day;
use App\Models\Branch;
use App\Services\Attendance\HolidayLabels;
use App\Services\Attendance\Overtime;
use App\Services\Attendance\Reporting\AttendanceSummary;
use App\Services\Attendance\Reporting\DayPresentation;
use App\Services\Attendance\Reporting\ReportFilters;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

class DailyAttendance extends AttendancePage
{
    protected static ?string $slug = 'attendance/daily';

    protected string $view = 'filament.admin.pages.attendance.daily';

    #[Url]
    public string $date = '';

    #[Url]
    public string $branchId = '';

    #[Url]
    public string $status = '';

    private ?LengthAwarePaginator $daysPage = null;

    private ?string $daysPageKey = null;

    public static function getNavigationLabel(): string
    {
        return __('messages.office_ui.daily_title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        parent::mount();
        $this->date = (string) request()->query('date', today('Asia/Baghdad')->subDay()->toDateString());
    }

    public function branches(): array
    {
        return Branch::query()->where('company_id', $this->company()->id)->orderBy('name')->pluck('name', 'id')->all();
    }

    public function days(): LengthAwarePaginator
    {
        $key = implode('|', [$this->date, $this->branchId, $this->status, (string) Paginator::resolveCurrentPage()]);
        if ($this->daysPage !== null && $this->daysPageKey === $key) {
            return $this->daysPage;
        }
        $this->daysPageKey = $key;

        return $this->daysPage = $this->query()->with(['employee', 'branch', 'policy'])->orderBy('employee_id')->paginate(50);
    }

    public function holidayNames(): array
    {
        $ids = [];
        foreach ($this->days()->items() as $day) {
            array_push($ids, ...($day->holiday_ids ?? []));
        }

        return app(HolidayLabels::class)->forIds($this->company()->id, $ids);
    }

    public function dayPresentations(): array
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date)) {
            return [];
        }

        $dayIds = array_map(fn (Day $day): int => $day->id, $this->days()->items());
        if ($dayIds === []) {
            return [];
        }

        $filters = ReportFilters::fromArray($this->company(), [
            'from' => $this->date, 'to' => $this->date,
            'branch_ids' => $this->branchId === '' ? [] : [$this->branchId],
            'statuses' => $this->status === '' ? [] : [$this->status],
        ]);
        $presentations = [];
        $display = app(DayPresentation::class);
        foreach (app(AttendanceSummary::class)->presentationRowsForDays($filters, $dayIds) as $id => $row) {
            $presentations[$id] = $display->from($row);
        }

        return $presentations;
    }

    public function overtimeRows(): array
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date)) {
            return [];
        }
        $filters = ReportFilters::fromArray($this->company(), [
            'from' => $this->date, 'to' => $this->date,
            'branch_ids' => $this->branchId === '' ? [] : [$this->branchId],
            'statuses' => $this->status === '' ? [] : [$this->status],
        ]);
        $rows = [];
        $dayIds = array_map(fn (Day $day): int => $day->id, $this->days()->items());
        if ($dayIds === []) {
            return [];
        }
        foreach (app(Overtime::class)->dayRows($filters, $dayIds) as $row) {
            $rows[$row['day_id']] = $row;
        }

        return $rows;
    }

    public function entitlementRows(): array
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date)) {
            return [];
        }
        $filters = ReportFilters::fromArray($this->company(), [
            'from' => $this->date, 'to' => $this->date,
            'branch_ids' => $this->branchId === '' ? [] : [$this->branchId],
            'statuses' => $this->status === '' ? [] : [$this->status],
        ]);
        $rows = [];
        $dayIds = array_map(fn (Day $day): int => $day->id, $this->days()->items());
        if ($dayIds === []) {
            return [];
        }
        foreach (app(Overtime::class)->entitlements($filters, dayIds: $dayIds) as $row) {
            $rows[$row['source_day_id']] = $row;
        }

        return $rows;
    }

    public function summary(): array
    {
        $counts = $this->query(false)->select('status', DB::raw('COUNT(*) AS total'))
            ->groupBy('status')->pluck('total', 'status')->all();
        $statuses = self::statuses();

        return array_replace(array_fill_keys($statuses, 0), $counts);
    }

    public function detailUrl(Day $day): string
    {
        return DayDetail::getUrl(panel: 'admin', tenant: $this->company())
            .'?'.http_build_query(['employee' => $day->employee_id, 'date' => $day->work_date->toDateString(),
                'back_branch' => $this->branchId, 'back_status' => $this->status]);
    }

    private function query(bool $includeStatus = true): Builder
    {
        $query = Day::query()->where('company_id', $this->company()->id)->whereNull('superseded_by_run_id');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date)) {
            return $query->whereRaw('1 = 0');
        }
        $query->where('work_date', $this->date);
        if ($this->branchId !== '') {
            $query->where('branch_id', ctype_digit($this->branchId) ? (int) $this->branchId : 0);
        }
        if ($includeStatus && $this->status !== '') {
            $query->where('status', in_array($this->status, self::statuses(), true) ? $this->status : 'invalid');
        }

        return $query;
    }

    public static function statuses(): array
    {
        return ReportFilters::STATUSES;
    }
}

<?php

namespace App\Filament\Admin\Pages\Attendance;

use App\Filament\Admin\Resources\Attendance\AttendancePage;
use App\Models\Attendance\Day;
use App\Models\Branch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DailyAttendance extends AttendancePage
{
    protected static ?string $slug = 'attendance/daily';

    protected string $view = 'filament.admin.pages.attendance.daily';

    public string $date = '';

    public string $branchId = '';

    public string $status = '';

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
        $this->date = today('Asia/Baghdad')->subDay()->toDateString();
    }

    public function branches(): array
    {
        return Branch::query()->where('company_id', $this->company()->id)->orderBy('name')->pluck('name', 'id')->all();
    }

    public function days(): LengthAwarePaginator
    {
        return $this->query()->with(['employee', 'branch'])->orderBy('employee_id')->paginate(50);
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
            .'?'.http_build_query(['employee' => $day->employee_id, 'date' => $day->work_date->toDateString()]);
    }

    private function query(bool $includeStatus = true): Builder
    {
        $query = Day::query()->where('company_id', $this->company()->id)->whereNull('superseded_by_run_id');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date)) {
            return $query->whereRaw('1 = 0');
        }
        $query->whereDate('work_date', $this->date);
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
        return ['pending', 'present', 'missing_punch', 'absent', 'rest_day', 'not_employed', 'pattern_missing', 'device_outage'];
    }
}

<?php

namespace App\Filament\Admin\Pages\Attendance;

use App\Filament\Admin\Pages\Attendance\Concerns\ReviewsDays;
use App\Filament\Admin\Resources\Attendance\AttendancePage;
use App\Models\Attendance\Day;
use App\Models\Branch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ReviewQueue extends AttendancePage
{
    use ReviewsDays;

    protected static ?string $slug = 'attendance/review';

    protected string $view = 'filament.admin.pages.attendance.review';

    public string $branchId = '';

    public string $from = '';

    public string $to = '';

    public string $reviewState = 'awaiting';

    public static function getNavigationLabel(): string
    {
        return __('messages.office_ui.review_title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        parent::mount();
        $this->from = today('Asia/Baghdad')->subDays(30)->toDateString();
        $this->to = today('Asia/Baghdad')->toDateString();
    }

    public function branches(): array
    {
        return Branch::query()->where('company_id', $this->company()->id)->orderBy('name')->pluck('name', 'id')->all();
    }

    public function days(): LengthAwarePaginator
    {
        $query = Day::query()->with(['employee', 'branch'])->where('company_id', $this->company()->id)
            ->whereNull('superseded_by_run_id');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->from)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->to) || $this->from > $this->to) {
            return $query->whereRaw('1 = 0')->paginate(25);
        }
        $query->whereBetween('work_date', [$this->from, $this->to]);
        if ($this->branchId !== '') {
            $query->where('branch_id', ctype_digit($this->branchId) ? (int) $this->branchId : 0);
        }
        if ($this->reviewState === 'awaiting') {
            $query->whereIn('status', ['missing_punch', 'absent', 'shift_unresolved'])->where('reviewed', false);
        } elseif ($this->reviewState === 'needs_shift') {
            $query->where('status', 'shift_unresolved')->where('reviewed', false);
        } elseif ($this->reviewState === 'reviewed') {
            $query->where(function ($query): void {
                $query->where('reviewed', true)->orWhereExists(function ($subquery): void {
                    $subquery->selectRaw('1')->from('attendance_manual_punches as manual')
                        ->whereColumn('manual.company_id', 'attendance_days.company_id')
                        ->whereColumn('manual.employee_id', 'attendance_days.employee_id')
                        ->where(function ($punchDate): void {
                            $punchDate->whereRaw('DATE(manual.punched_at) = attendance_days.work_date')
                                ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(attendance_days.inputs, '$.work_pattern')) IN ('shift', 'security') AND manual.role = 'check_out' AND DATE(manual.punched_at) = DATE_ADD(attendance_days.work_date, INTERVAL 1 DAY) AND TIME(manual.punched_at) <= '02:00:00'");
                        })
                        ->whereNull('manual.reverses_id')->whereNotExists(function ($reversals): void {
                            $reversals->selectRaw('1')->from('attendance_manual_punches as reversal')
                                ->whereColumn('reversal.company_id', 'manual.company_id')
                                ->whereColumn('reversal.reverses_id', 'manual.id');
                        });
                });
            });
        } else {
            $query->whereRaw('1 = 0');
        }

        return $query->orderByDesc('work_date')->orderByDesc('id')->paginate(25);
    }

    public function detailUrl(Day $day): string
    {
        return DayDetail::getUrl(panel: 'admin', tenant: $this->company())
            .'?'.http_build_query(['employee' => $day->employee_id, 'date' => $day->work_date->toDateString()]);
    }
}

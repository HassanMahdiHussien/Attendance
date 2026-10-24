<?php

namespace App\Filament\Admin\Pages\Attendance;

use App\Filament\Admin\Resources\Attendance\AttendancePage;
use App\Models\Attendance\Holiday;
use App\Models\Branch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class HolidaysPage extends AttendancePage
{
    protected static ?string $slug = 'attendance/holidays';

    protected string $view = 'filament.admin.pages.attendance.holidays';

    public string $status = '';

    public string $year = '';

    public static function getNavigationLabel(): string
    {
        return __('messages.holiday_ui.title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function holidays(): LengthAwarePaginator
    {
        $query = Holiday::query()->with(['currentVersion.source'])->where('company_id', $this->company()->id);
        if ($this->status !== '') {
            $query->where('status', in_array($this->status, ['draft', 'approved', 'rejected', 'withdrawn'], true)
                ? $this->status : 'invalid');
        }
        if ($this->year !== '') {
            $year = ctype_digit($this->year) && strlen($this->year) === 4 ? $this->year : '0000';
            $from = $year.'-01-01';
            $to = $year.'-12-31';
            $matchingIds = Holiday::query()->with('currentVersion')->where('company_id', $this->company()->id)
                ->get()->filter(fn (Holiday $holiday): bool => collect($holiday->currentVersion?->ranges ?? [])
                ->contains(fn (array $range): bool => $range['from'] <= $to && $range['to'] >= $from))
                ->pluck('id');
            $query->whereIn('id', $matchingIds);
        }

        return $query->latest('id')->paginate(25);
    }

    public function detailUrl(Holiday $holiday): string
    {
        return HolidayReview::getUrl(panel: 'admin', tenant: $this->company()).'?'.http_build_query(['holiday' => $holiday->id]);
    }

    public function scopeLabel(array $entities, array $branches, array $patterns): string
    {
        if ($entities === [] && $branches === [] && $patterns === []) {
            return __('messages.holiday_ui.whole_company');
        }

        $branchNames = $branches === [] ? [] : Branch::query()->where('company_id', $this->company()->id)
            ->whereIn('id', $branches)->orderBy('name')->pluck('name')->all();

        return implode(' · ', array_filter([
            $entities === [] ? null : implode(', ', $entities),
            $branchNames === [] ? null : implode(', ', $branchNames),
            $patterns === [] ? null : implode(', ', array_map(fn (string $pattern): string => __('messages.holiday_ui.patterns.'.$pattern), $patterns)),
        ]));
    }
}

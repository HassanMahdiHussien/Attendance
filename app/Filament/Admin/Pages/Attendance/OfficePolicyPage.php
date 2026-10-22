<?php

namespace App\Filament\Admin\Pages\Attendance;

use App\Filament\Admin\Resources\Attendance\AttendancePage;
use App\Models\Attendance\Policy;
use App\Services\Attendance\OfficePolicy;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class OfficePolicyPage extends AttendancePage
{
    protected static ?string $slug = 'attendance/office-policy';

    protected string $view = 'filament.admin.pages.attendance.office-policy';

    public string $effectiveFrom = '';

    public string $officeStart = '08:00';

    public int $flexMinutes = 60;

    public int $workdayMinutes = 540;

    public int $minPresenceMinutes = 480;

    public int $closeGraceMinutes = 120;

    public bool $exitsCountTowardBalance = false;

    public array $officeRestDays = [5, 6];

    public string $reason = '';

    public static function getNavigationLabel(): string
    {
        return __('messages.office_ui.policy_title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        parent::mount();
        $this->effectiveFrom = today('Asia/Baghdad')->toDateString();
        $policy = app(OfficePolicy::class)->asOf($this->company(), $this->effectiveFrom);
        if ($policy !== null) {
            $this->officeStart = substr($policy->office_start, 0, 5);
            $this->flexMinutes = $policy->flex_minutes;
            $this->workdayMinutes = $policy->workday_minutes;
            $this->minPresenceMinutes = $policy->min_presence_minutes;
            $this->closeGraceMinutes = $policy->close_grace_minutes;
            $this->exitsCountTowardBalance = $policy->exits_count_toward_balance;
            $this->officeRestDays = $policy->office_rest_days;
        }
    }

    public function current(): ?Policy
    {
        return app(OfficePolicy::class)->asOf($this->company(), today('Asia/Baghdad'));
    }

    public function history(): LengthAwarePaginator
    {
        return Policy::query()->with('creator')->where('company_id', $this->company()->id)
            ->orderByDesc('version')->paginate(20);
    }

    public function addVersion(OfficePolicy $officePolicy): void
    {
        $this->company();
        $this->validate([
            'effectiveFrom' => 'required|date_format:Y-m-d', 'officeStart' => 'required|date_format:H:i',
            'flexMinutes' => 'required|integer|min:0|max:1440', 'workdayMinutes' => 'required|integer|min:1|max:1440',
            'minPresenceMinutes' => 'required|integer|min:1|max:1440', 'closeGraceMinutes' => 'required|integer|min:0|max:1440',
            'exitsCountTowardBalance' => 'boolean', 'officeRestDays' => 'array',
            'officeRestDays.*' => 'integer|between:1,7', 'reason' => 'required|string|max:500',
        ]);
        $officePolicy->add($this->company(), [
            'office_start' => $this->officeStart.':00', 'flex_minutes' => $this->flexMinutes,
            'workday_minutes' => $this->workdayMinutes, 'min_presence_minutes' => $this->minPresenceMinutes,
            'close_grace_minutes' => $this->closeGraceMinutes,
            'exits_count_toward_balance' => $this->exitsCountTowardBalance,
            'office_rest_days' => array_values(array_map('intval', $this->officeRestDays)),
        ], $this->effectiveFrom, auth()->user(), $this->reason);
        $this->reason = '';
    }
}

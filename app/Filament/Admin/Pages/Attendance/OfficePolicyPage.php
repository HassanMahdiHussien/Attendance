<?php

namespace App\Filament\Admin\Pages\Attendance;

use App\Filament\Admin\Resources\Attendance\AttendancePage;
use App\Models\Attendance\Policy;
use App\Services\Attendance\OfficePolicy;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Locked;

class OfficePolicyPage extends AttendancePage
{
    protected static ?string $slug = 'attendance/office-policy';

    protected string $view = 'filament.admin.pages.attendance.office-policy';

    public string $effectiveFrom = '';

    public string $officeStart = '08:00';

    public int $flexMinutes = 60;

    public int $workdayMinutes = 540;

    public int $minPresenceMinutes = 540;

    public int $absenceMinutes = 480;

    public int $closeGraceMinutes = 120;

    public bool $exitsCountTowardBalance = false;

    public array $officeRestDays = [5, 6];

    public array $shiftStarts = ['00:00', '08:00', '16:00'];

    public int $shiftMinutes = 480;

    public int $shiftBreakMinutes = 30;

    public int $shiftMinPresenceMinutes = 480;

    public array $shiftRestDays = [5];

    public int $securityMinPresenceMinutes = 480;

    public int $inferenceToleranceMinutes = 120;

    #[Locked]
    public array $initialValues = [];

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
            $this->absenceMinutes = $policy->absence_minutes;
            $this->closeGraceMinutes = $policy->close_grace_minutes;
            $this->exitsCountTowardBalance = $policy->exits_count_toward_balance;
            $this->officeRestDays = $policy->office_rest_days;
            $this->shiftStarts = $policy->shift_starts;
            $this->shiftMinutes = $policy->shift_minutes;
            $this->shiftBreakMinutes = $policy->shift_break_minutes;
            $this->shiftMinPresenceMinutes = $policy->shift_min_presence_minutes;
            $this->shiftRestDays = $policy->shift_rest_days;
            $this->securityMinPresenceMinutes = $policy->security_min_presence_minutes;
            $this->inferenceToleranceMinutes = $policy->inference_tolerance_minutes;
        }
        $this->initialValues = $this->values();
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
            'absenceMinutes' => 'required|integer|min:1|max:1440',
            'exitsCountTowardBalance' => 'boolean', 'officeRestDays' => 'array',
            'officeRestDays.*' => 'integer|between:1,7', 'reason' => 'required|string|max:500',
            'shiftStarts' => 'required|array|min:1', 'shiftStarts.*' => 'required|date_format:H:i',
            'shiftMinutes' => 'required|integer|min:1|max:1440', 'shiftBreakMinutes' => 'required|integer|min:0|max:1440',
            'shiftMinPresenceMinutes' => 'required|integer|min:1|max:1440',
            'shiftRestDays' => 'array', 'shiftRestDays.*' => 'integer|between:1,7',
            'securityMinPresenceMinutes' => 'required|integer|min:1|max:1440',
            'inferenceToleranceMinutes' => 'required|integer|min:1|max:1440',
        ]);
        $values = $this->values();
        $changed = [];
        foreach ($values as $field => $value) {
            if ($value !== ($this->initialValues[$field] ?? null)) {
                $changed[$field] = $value;
            }
        }
        $officePolicy->add($this->company(), $changed, $this->effectiveFrom, auth()->user(), $this->reason);
        $this->initialValues = $values;
        $this->reason = '';
    }

    private function values(): array
    {
        $restDays = array_values(array_map('intval', $this->officeRestDays));
        $shiftRestDays = array_values(array_map('intval', $this->shiftRestDays));
        $starts = array_values($this->shiftStarts);
        sort($restDays);
        sort($shiftRestDays);
        sort($starts);

        return [
            'office_start' => $this->officeStart.':00', 'flex_minutes' => $this->flexMinutes,
            'workday_minutes' => $this->workdayMinutes, 'min_presence_minutes' => $this->minPresenceMinutes,
            'absence_minutes' => $this->absenceMinutes, 'close_grace_minutes' => $this->closeGraceMinutes,
            'exits_count_toward_balance' => $this->exitsCountTowardBalance, 'office_rest_days' => $restDays,
            'shift_starts' => $starts, 'shift_minutes' => $this->shiftMinutes,
            'shift_break_minutes' => $this->shiftBreakMinutes,
            'shift_min_presence_minutes' => $this->shiftMinPresenceMinutes, 'shift_rest_days' => $shiftRestDays,
            'security_min_presence_minutes' => $this->securityMinPresenceMinutes,
            'inference_tolerance_minutes' => $this->inferenceToleranceMinutes,
        ];
    }
}

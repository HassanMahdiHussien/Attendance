<?php

namespace App\Filament\Admin\Pages\Attendance;

use App\Filament\Admin\Resources\Attendance\AttendancePage;
use App\Models\Attendance\Meal;
use App\Models\Attendance\Punch;
use App\Models\Attendance\Transaction;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

class AttendancePunches extends AttendancePage
{
    protected static ?string $slug = 'attendance/punches';

    protected string $view = 'filament.admin.pages.attendance.punches';

    public ?int $employeeId = null;

    public string $personCode = '';

    public string $from = '';

    public string $to = '';

    public static function getNavigationLabel(): string
    {
        return __('messages.attendance_ui.punches_title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        parent::mount();
        $this->from = today()->subDays(7)->toDateString();
        $this->to = today()->toDateString();

        if (request()->has('employee')) {
            $employee = request()->query('employee');
            $from = request()->query('from');
            $to = request()->query('to');
            abort_unless(is_string($employee) && ctype_digit($employee)
                && Validator::make(compact('from', 'to'), [
                    'from' => 'required|date_format:Y-m-d',
                    'to' => 'required|date_format:Y-m-d|after_or_equal:from',
                ])->passes(), 404);
            Employee::query()->where('company_id', $this->company()->id)->findOrFail((int) $employee);
            $this->employeeId = (int) $employee;
            $this->from = $from;
            $this->to = $to;
        }
    }

    public function employees(): array
    {
        return Employee::query()->where('company_id', $this->company()->id)->orderBy('name')->limit(1000)
            ->get()->mapWithKeys(fn (Employee $employee): array => [$employee->id => $employee->employee_code.' — '.$employee->name])->all();
    }

    public function days(): array
    {
        $company = $this->company();
        if (Validator::make([
            'employeeId' => $this->employeeId, 'personCode' => $this->personCode,
            'from' => $this->from, 'to' => $this->to,
        ], [
            'employeeId' => 'nullable|integer', 'personCode' => 'nullable|string|max:255',
            'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from',
        ])->fails()) {
            return [];
        }
        $from = CarbonImmutable::parse($this->from);
        $to = CarbonImmutable::parse($this->to);
        if ($from->diffInDays($to) > 31) {
            return [];
        }
        $employee = $this->employeeId === null ? null : Employee::query()->where('company_id', $company->id)->findOrFail($this->employeeId);
        $code = $employee?->employee_code ?? trim($this->personCode);
        if ($code === '') {
            return [];
        }
        $raw = Transaction::query()->where('company_id', $company->id)->where('person_code', $code)
            ->whereBetween('punch_date', [$this->from, $this->to])->orderBy('punched_at')->orderBy('id')->limit(2000)->get();
        $punches = Punch::query()->where('company_id', $company->id)->where('person_code', $code)
            ->whereNull('superseded_by_run_id')->whereBetween('punched_at', [$this->from.' 00:00:00', $this->to.' 23:59:59'])
            ->get()->keyBy('transaction_id');
        $meals = Meal::query()->where('company_id', $company->id)->where('person_code', $code)
            ->whereNull('superseded_by_run_id')->whereBetween('meal_date', [$this->from, $this->to])->get()->keyBy(fn (Meal $meal): string => $meal->meal_date->toDateString());
        $days = [];
        foreach ($raw as $transaction) {
            $day = $transaction->punch_date;
            $days[$day]['transactions'][] = ['raw' => $transaction, 'clean' => $punches->get($transaction->id)];
        }
        foreach ($meals as $day => $meal) {
            $days[$day]['meal'] = $meal;
        }
        ksort($days);

        return $days;
    }
}

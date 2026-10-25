<?php

namespace App\Filament\Admin\Pages\Attendance;

use App\Filament\Admin\Resources\Attendance\AttendancePage;
use App\Models\Employee;
use App\Services\Attendance\FollowUp\ApproverMappings;
use Illuminate\Support\Facades\Gate;

class ApproverMappingsPage extends AttendancePage
{
    protected static ?string $slug = 'attendance/approver-mappings';

    protected string $view = 'filament.admin.pages.attendance.approver-mappings';

    public string $search = '';

    public array $employeeIds = [];

    public static function getNavigationLabel(): string
    {
        return __('messages.follow_up_ui.approver_mappings');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function canManage(): bool
    {
        return Gate::allows('sendAttendanceNotifications', $this->company());
    }

    public function unmapped(): array
    {
        return app(ApproverMappings::class)->unmappedWithCounts($this->company());
    }

    public function mappings()
    {
        return app(ApproverMappings::class)->confirmed($this->company());
    }

    public function employees()
    {
        $search = trim($this->search);
        if (mb_strlen($search) < 2) {
            return collect();
        }

        return Employee::query()->where('company_id', $this->company()->id)
            ->where(fn ($query) => $query->where('name', 'like', '%'.$search.'%')
                ->orWhere('name_en', 'like', '%'.$search.'%')->orWhere('employee_code', 'like', $search.'%'))
            ->orderBy('name')->limit(20)->get();
    }

    public function confirm(string $text, ApproverMappings $mappings): void
    {
        $key = sha1(ApproverMappings::key($text));
        $this->validate(['employeeIds.'.$key => 'required|integer']);
        $employee = Employee::query()->where('company_id', $this->company()->id)->findOrFail($this->employeeIds[$key]);
        $mappings->confirm($this->company(), $text, $employee, auth()->user());
    }
}

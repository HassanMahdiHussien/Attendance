<?php

namespace App\Filament\Admin\Pages\Attendance;

use App\Filament\Admin\Resources\Attendance\AttendancePage;
use App\Models\Attendance\AttendanceExport;
use App\Models\Attendance\AttendanceExportEvent;
use App\Services\Attendance\Reporting\AttendanceExports;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AttendanceExportsPage extends AttendancePage
{
    protected static ?string $slug = 'attendance/exports';

    protected string $view = 'filament.admin.pages.attendance.exports';

    public static function getNavigationLabel(): string
    {
        return __('messages.export_ui.exports');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function exports(): LengthAwarePaginator
    {
        return AttendanceExport::query()->where('company_id', $this->company()->id)
            ->with('requester')->latest('id')->paginate(25);
    }

    public function latestEvent(AttendanceExport $export): ?AttendanceExportEvent
    {
        abort_unless((int) $export->company_id === (int) $this->company()->id, 404);

        return $export->events()->latest('sequence')->first();
    }

    public function retry(int $exportId, AttendanceExports $exports): void
    {
        $export = AttendanceExport::query()->where('company_id', $this->company()->id)->findOrFail($exportId);
        $exports->retry($export, auth()->user());
        Notification::make()->title(__('messages.export_ui.retry_queued'))->success()->send();
    }
}

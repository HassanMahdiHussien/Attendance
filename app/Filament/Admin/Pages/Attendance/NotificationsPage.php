<?php

namespace App\Filament\Admin\Pages\Attendance;

use App\Filament\Admin\Resources\Attendance\AttendancePage;
use App\Models\Attendance\NotificationBatch;
use App\Models\Attendance\NotificationMessage;
use App\Services\Attendance\FollowUp\NotificationBatches;
use App\Services\Attendance\FollowUp\NotificationOverview;
use App\Services\Attendance\FollowUp\NotificationSettings;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

class NotificationsPage extends AttendancePage
{
    protected static ?string $slug = 'attendance/notifications';

    protected string $view = 'filament.admin.pages.attendance.notifications';

    public string $mode = 'review';

    public string $buildTime = '09:00';

    public int $reminderIntervalDays = 2;

    public int $reminderMax = 3;

    public string $language = 'en';

    public string $reason = '';

    public array $messageReasons = [];

    public ?int $previewMessageId = null;

    public static function getNavigationLabel(): string
    {
        return __('messages.follow_up_ui.notifications');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        parent::mount();
        $setting = app(NotificationSettings::class)->forCompany($this->company());
        $this->mode = $setting->mode;
        $this->buildTime = substr($setting->build_time, 0, 5);
        $this->reminderIntervalDays = $setting->reminder_interval_days;
        $this->reminderMax = $setting->reminder_max;
        $this->language = $setting->language;
    }

    public function canManage(): bool
    {
        return Gate::allows('sendAttendanceNotifications', $this->company());
    }

    public function saveSettings(NotificationSettings $settings): void
    {
        $settings->set($this->company(), [
            'mode' => $this->mode, 'build_time' => $this->buildTime,
            'reminder_interval_days' => $this->reminderIntervalDays,
            'reminder_max' => $this->reminderMax, 'language' => $this->language,
            'reason' => $this->reason,
        ], auth()->user());
        $this->reason = '';
    }

    public function batches(): LengthAwarePaginator
    {
        return app(NotificationOverview::class)->batches($this->company());
    }

    public function build(NotificationBatches $batches): void
    {
        $batches->build($this->company(), auth()->user());
    }

    public function send(int $id, NotificationBatches $batches): void
    {
        $batches->send($this->batch($id), auth()->user());
    }

    public function cancel(int $id, NotificationBatches $batches): void
    {
        $this->validate(['reason' => 'required|string|max:1000']);
        $batches->cancel($this->batch($id), auth()->user(), $this->reason);
        $this->reason = '';
    }

    public function exclude(int $id, NotificationBatches $batches): void
    {
        $this->validate(['messageReasons.'.$id => 'required|string|max:1000']);
        $batches->exclude($this->message($id), auth()->user(), $this->messageReasons[$id]);
        unset($this->messageReasons[$id]);
    }

    public function retry(int $id, NotificationBatches $batches): void
    {
        $batches->retry($this->message($id), auth()->user());
    }

    public function resolve(int $id, string $action, NotificationBatches $batches): void
    {
        $batches->resolveUncertain($this->message($id), $action, auth()->user());
    }

    public function preview(int $id): void
    {
        $this->message($id);
        $this->previewMessageId = $id;
    }

    public function previewHtml(): string
    {
        if ($this->previewMessageId === null) {
            return '';
        }
        $message = $this->message($this->previewMessageId)->load('cases');

        $original = app()->getLocale();
        try {
            app()->setLocale($message->language);

            return view('mail.attendance-follow-up', [
                'entries' => $message->cases->pluck('snapshot')->all(),
                'language' => $message->language,
            ])->render();
        } finally {
            app()->setLocale($original);
        }
    }

    private function batch(int $id): NotificationBatch
    {
        return app(NotificationOverview::class)->batch($this->company(), $id);
    }

    private function message(int $id): NotificationMessage
    {
        return app(NotificationOverview::class)->message($this->company(), $id);
    }
}

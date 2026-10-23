<?php

use App\Services\Attendance\PendingDaysScheduler;
use App\Services\Attendance\Reporting\AttendanceExports;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('attendance:assess-pending', function (PendingDaysScheduler $scheduler): void {
    $this->info((string) $scheduler->dispatch());
})->purpose(__('messages.office_attendance.reassess_command'));

Artisan::command('attendance:clean-exports', function (AttendanceExports $exports): void {
    $this->info((string) $exports->cleanup());
})->purpose(__('messages.export_ui.cleanup_command'));

Schedule::command('app:heartbeat --dispatch')->everyMinute();
Schedule::command('employees:apply-effective-changes')->dailyAt('00:15')->timezone('Asia/Baghdad')->withoutOverlapping();
Schedule::command('attendance:assess-pending')->hourly()->timezone('Asia/Baghdad')->withoutOverlapping();
Schedule::command('attendance:clean-exports')->dailyAt('03:00')->timezone('Asia/Baghdad')->withoutOverlapping();

Schedule::command('backup:run')->dailyAt('01:00')->timezone('Asia/Baghdad')
    ->when(fn () => config('displanery.backups.enabled'))->withoutOverlapping();
Schedule::command('backup:clean')->dailyAt('02:30')->timezone('Asia/Baghdad')
    ->when(fn () => config('displanery.backups.enabled'))->withoutOverlapping();
Schedule::command('backup:monitor')->hourly()->timezone('Asia/Baghdad')
    ->when(fn () => config('displanery.backups.enabled'))->withoutOverlapping();
Schedule::command('backup:restore-drill')->monthlyOn(1, '03:30')->timezone('Asia/Baghdad')
    ->when(fn () => config('displanery.backups.enabled'))->withoutOverlapping();
Schedule::command('ops:check-failures')->hourly()->timezone('Asia/Baghdad')
    ->when(fn () => filled(config('displanery.healthchecks.queue_failures')))->withoutOverlapping();

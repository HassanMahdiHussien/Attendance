<?php

use App\Services\Cases\CaseAlertDispatcher;
use App\Services\Cases\ExpiredAppealArchiver;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:heartbeat --dispatch')->everyMinute();

Artisan::command('cases:send-deadline-alerts', function (): void {
    app(CaseAlertDispatcher::class)->run();
})->purpose('Send case deadline alerts');

Artisan::command('cases:archive-expired-appeals', function (): void {
    app(ExpiredAppealArchiver::class)->run();
})->purpose('Archive expired appeal windows');

Schedule::command('cases:send-deadline-alerts')->hourly();
Schedule::command('cases:archive-expired-appeals')->hourly();

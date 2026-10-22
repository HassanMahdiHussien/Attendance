<?php

use App\Jobs\HeartbeatJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;

it('renders the English LTR home page', function () {
    $this->get('/')->assertOk()
        ->assertSee('lang="en"', false)
        ->assertSee('dir="ltr"', false)
        ->assertSee('Readex+Pro')
        ->assertSee('Attendance');
});

it('dispatches the heartbeat job and schedules it every minute', function () {
    Queue::fake();

    Artisan::call('app:heartbeat', ['--dispatch' => true]);
    Queue::assertPushed(HeartbeatJob::class);

    $event = collect(Schedule::events())->first(fn ($scheduled) => str_contains($scheduled->command ?? '', 'app:heartbeat --dispatch'));
    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *');
});

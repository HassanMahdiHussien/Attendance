<?php

use App\Enums\UserRole;
use App\Jobs\Attendance\SendNotificationMessageJob;
use App\Mail\AttendanceFollowUpMail;
use App\Models\Attendance\CaseEvent;
use App\Models\Attendance\FollowUpCase;
use App\Models\Attendance\NotificationAttempt;
use App\Models\Attendance\NotificationBatch;
use App\Models\Attendance\NotificationMessage;
use App\Models\Attendance\NotificationMessageCase;
use App\Models\Attendance\NotificationRoute;
use App\Models\Attendance\NotificationSetting;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\FollowUp\NotificationBatches;
use App\Services\Attendance\FollowUp\NotificationSettings;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    Queue::fake();
    Mail::fake();
    $this->travelTo(now('Asia/Baghdad')->setDate(2026, 10, 25)->setTime(10, 0));
    $this->company = Company::factory()->create();
    app(TenantContext::class)->setCompany($this->company);
    $this->hr = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    $this->actingAs($this->hr);
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function notificationCase(Company $company, string $code, string $address, string $date = '2026-10-21', string $kind = 'manager'): FollowUpCase
{
    $employee = Employee::factory()->create([
        'company_id' => $company->id, 'employee_code' => $code,
        'email' => 'employee-'.$code.'@example.test',
        'fallback_notification_email' => $kind === 'manager' ? $address : null,
    ]);
    $recipient = $kind === 'approver' ? Employee::factory()->create([
        'company_id' => $company->id, 'email' => $address,
    ]) : null;
    $route = [
        'key' => $kind === 'approver' ? 'approver:101' : 'manager', 'kind' => $kind,
        'request_id' => $kind === 'approver' ? 101 : null,
        'external_id' => $kind === 'approver' ? 'FORM-101' : null,
        'approver_text' => $kind === 'approver' ? 'Approver' : null,
        'approver_key' => $kind === 'approver' ? 'approver' : null,
        'recipient_employee_id' => $recipient?->id, 'recipient_available' => true,
        'items' => [['type' => 'absence', 'minutes' => 480]],
    ];

    return FollowUpCase::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id,
        'work_date' => $date, 'state' => $kind === 'approver' ? 'waiting_approver' : 'open',
        'routes' => [$route],
    ]);
}

function deliverNotification(NotificationMessage $message): void
{
    app()->call([new SendNotificationMessageJob($message->id), 'handle']);
}

test('review groups all employees for a fallback address into one message and sends once', function (): void {
    notificationCase($this->company, '00000001', 'fallback@example.test');
    notificationCase($this->company, '00000002', 'fallback@example.test');
    $service = app(NotificationBatches::class);
    $batch = $service->build($this->company, $this->hr, 'scheduled:2026-10-25');
    $message = $batch->messages()->firstOrFail();
    expect($batch->status)->toBe('draft')
        ->and($batch->messages()->count())->toBe(1)
        ->and($message->cases()->count())->toBe(2)
        ->and($service->build($this->company, $this->hr, 'scheduled:2026-10-25')->id)->toBe($batch->id)
        ->and($service->build($this->company, $this->hr)->messages()->count())->toBe(0);
    Queue::assertNotPushed(SendNotificationMessageJob::class);

    $service->send($batch, $this->hr);
    Queue::assertPushed(SendNotificationMessageJob::class, 1);
    deliverNotification($message);
    expect($message->fresh()->status)->toBe('sent')
        ->and($batch->fresh()->status)->toBe('sent')
        ->and(NotificationRoute::query()->where('sent_count', 1)->count())->toBe(2)
        ->and(CaseEvent::query()->where('event', 'sent')->count())->toBe(2);
    Mail::assertSent(AttendanceFollowUpMail::class, 1);
    deliverNotification($message);
    Mail::assertSent(AttendanceFollowUpMail::class, 1);
});

test('reminders count leave-calendar working days and stop after the configured maximum', function (): void {
    $this->travelTo(now()->setDate(2026, 10, 29));
    notificationCase($this->company, '00000003', 'manager@example.test');
    $service = app(NotificationBatches::class);
    $first = $service->build($this->company, $this->hr);
    $service->send($first, $this->hr);
    deliverNotification($first->messages()->firstOrFail());
    $this->travelTo(now()->addDay());
    expect($service->build($this->company, $this->hr)->messages()->count())->toBe(0);
    $this->travelTo(now()->addDay());
    expect($service->build($this->company, $this->hr)->messages()->count())->toBe(0);
    $this->travelTo(now()->addDay());
    $reminder = $service->build($this->company, $this->hr);
    expect($reminder->messages()->count())->toBe(1)
        ->and($reminder->messages()->firstOrFail()->cases()->firstOrFail()->kind)->toBe('reminder');
    $service->send($reminder, $this->hr);
    deliverNotification($reminder->messages()->firstOrFail());
    foreach (range(1, 2) as $index) {
        $this->travelTo(now()->addDays(4));
        $batch = $service->build($this->company, $this->hr);
        expect($batch->messages()->count())->toBe(1);
        $service->send($batch, $this->hr);
        deliverNotification($batch->messages()->firstOrFail());
    }
    $this->travelTo(now()->addDays(7));
    expect($service->build($this->company, $this->hr)->messages()->count())->toBe(0)
        ->and(NotificationRoute::query()->firstOrFail()->sent_count)->toBe(4);
});

test('auto mode queues immediately and excluding a draft releases its reservation', function (): void {
    notificationCase($this->company, '00000004', 'manager@example.test');
    $service = app(NotificationBatches::class);
    $draft = $service->build($this->company, $this->hr);
    $message = $draft->messages()->firstOrFail();
    $service->exclude($message, $this->hr, 'Handled by HR');
    expect($message->fresh()->status)->toBe('excluded')
        ->and(NotificationMessageCase::query()->count())->toBe(0);
    NotificationSetting::query()->firstOrFail()->update(['mode' => 'auto']);
    $auto = $service->build($this->company, $this->hr);
    expect($auto->status)->toBe('sending')
        ->and($auto->messages()->firstOrFail()->status)->toBe('queued');
    Queue::assertPushed(SendNotificationMessageJob::class);
});

test('a changed route is dropped before sending and becomes due again', function (): void {
    $case = notificationCase($this->company, '00000005', 'manager@example.test');
    $service = app(NotificationBatches::class);
    $batch = $service->build($this->company, $this->hr);
    $service->send($batch, $this->hr);
    $case->employee->update(['fallback_notification_email' => 'new-manager@example.test']);
    deliverNotification($batch->messages()->firstOrFail());
    expect($batch->messages()->firstOrFail()->fresh()->status)->toBe('skipped')
        ->and(NotificationMessageCase::query()->count())->toBe(0)
        ->and(CaseEvent::query()->where('event', 'skipped_stale')->count())->toBe(1)
        ->and($service->build($this->company, $this->hr)->messages()->count())->toBe(1);
    Mail::assertNothingSent();
});

test('a failed send retries up to three attempts and a later manual retry can succeed', function (): void {
    notificationCase($this->company, '00000006', 'manager@example.test');
    $service = app(NotificationBatches::class);
    $batch = $service->build($this->company, $this->hr);
    $service->send($batch, $this->hr);
    $message = $batch->messages()->firstOrFail();
    Mail::shouldReceive('to')->times(3)->andThrow(new RuntimeException('SMTP unavailable'));
    foreach (range(1, 3) as $number) {
        deliverNotification($message);
    }
    expect($message->fresh()->status)->toBe('failed')
        ->and($message->fresh()->attempts)->toBe(3)
        ->and(NotificationAttempt::query()->where('result', 'failed')->count())->toBe(3)
        ->and(NotificationRoute::query()->firstOrFail()->sent_count)->toBe(0);
    Mail::swap(new MailManager(app()));
    Mail::fake();
    $service->retry($message, $this->hr);
    deliverNotification($message);
    expect($message->fresh()->status)->toBe('sent');
});

test('an unresolved started attempt is uncertain and requires an audited operator choice', function (): void {
    notificationCase($this->company, '00000007', 'manager@example.test');
    $service = app(NotificationBatches::class);
    $batch = $service->build($this->company, $this->hr);
    $service->send($batch, $this->hr);
    $message = $batch->messages()->firstOrFail();
    NotificationAttempt::factory()->create([
        'company_id' => $this->company->id, 'message_id' => $message->id,
        'attempt_number' => 1, 'result' => 'started',
    ]);
    $message->update(['status' => 'sending', 'attempts' => 1]);
    deliverNotification($message);
    expect($message->fresh()->status)->toBe('uncertain');
    Mail::assertNothingSent();
    $service->resolveUncertain($message, 'mark_sent', $this->hr);
    expect($message->fresh()->status)->toBe('sent')
        ->and(NotificationRoute::query()->firstOrFail()->sent_count)->toBe(1);
});

test('an uncertain message can be explicitly resent, and a cancelled draft releases routes', function (): void {
    notificationCase($this->company, '00000012', 'manager@example.test');
    $service = app(NotificationBatches::class);
    $batch = $service->build($this->company, $this->hr);
    $service->send($batch, $this->hr);
    $message = $batch->messages()->firstOrFail();
    NotificationAttempt::factory()->create([
        'company_id' => $this->company->id, 'message_id' => $message->id,
        'attempt_number' => 1, 'result' => 'started',
    ]);
    $message->update(['status' => 'sending', 'attempts' => 1]);
    deliverNotification($message);
    $service->resolveUncertain($message, 'resend', $this->hr);
    expect($message->fresh()->status)->toBe('queued')
        ->and(NotificationAttempt::query()->where('result', 'failed')->count())->toBe(1);
    deliverNotification($message);
    expect($message->fresh()->status)->toBe('sent')
        ->and(NotificationRoute::query()->firstOrFail()->sent_count)->toBe(1);

    notificationCase($this->company, '00000013', 'other@example.test');
    $draft = $service->build($this->company, $this->hr);
    $service->cancel($draft, $this->hr, 'Review replaced');
    expect($draft->fresh()->status)->toBe('cancelled')
        ->and($service->build($this->company, $this->hr)->messages()->count())->toBe(1);
});

test('employee address is never used and an approver without an address is omitted', function (): void {
    $managerCase = notificationCase($this->company, '00000014', 'fallback@example.test');
    $managerCase->employee->update(['fallback_notification_email' => $managerCase->employee->email]);
    $approverCase = notificationCase($this->company, '00000015', 'approver@example.test', kind: 'approver');
    $recipientId = $approverCase->routes[0]['recipient_employee_id'];
    Employee::findOrFail($recipientId)->update(['email' => null]);
    $batch = app(NotificationBatches::class)->build($this->company, $this->hr);
    expect($batch->messages()->count())->toBe(0);
    Mail::assertNothingSent();
});

test('approver mail shows only form number and dates, while missing addresses are never used', function (): void {
    $case = notificationCase($this->company, '00000008', 'approver@example.test', kind: 'approver');
    $service = app(NotificationBatches::class);
    $batch = $service->build($this->company, $this->hr);
    $service->send($batch, $this->hr);
    deliverNotification($batch->messages()->firstOrFail());
    Mail::assertSent(AttendanceFollowUpMail::class, function (AttendanceFollowUpMail $mail): bool {
        $html = $mail->render();

        return str_contains($html, 'FORM-101') && ! str_contains($html, 'Sick Leave')
            && ! str_contains($html, 'medical') && $mail->messageUuid !== '';
    });
    $case->routes = [array_merge($case->routes[0], ['recipient_available' => false])];
    $case->save();
    expect($service->build($this->company, $this->hr)->messages()->count())->toBe(0);
});

test('Arabic mail is right-to-left and a foreign tenant or inactive HR cannot build or send', function (): void {
    $other = Company::factory()->create();
    app(TenantContext::class)->runForCompany($other,
        fn (): FollowUpCase => notificationCase($other, '00000009', 'other@example.test'));
    notificationCase($this->company, '00000010', 'manager@example.test');
    app(NotificationSettings::class)->forCompany($this->company)->update(['language' => 'ar']);
    $service = app(NotificationBatches::class);
    $batch = $service->build($this->company, $this->hr);
    expect($batch->messages()->count())->toBe(1)
        ->and(fn () => $service->build($other, $this->hr))->toThrow(HttpException::class);
    $service->send($batch, $this->hr);
    deliverNotification($batch->messages()->firstOrFail());
    Mail::assertSent(AttendanceFollowUpMail::class, function (AttendanceFollowUpMail $mail): bool {
        return $mail->language === 'ar' && str_contains($mail->render(), 'dir="rtl"');
    });
    $inactive = User::factory()->create([
        'company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff, 'is_active' => false,
    ]);
    $this->actingAs($inactive);
    expect(fn () => $service->build($this->company, $inactive))->toThrow(AuthorizationException::class);
});

test('the scheduled command builds only after company time and only once per Baghdad date', function (): void {
    notificationCase($this->company, '00000011', 'manager@example.test');
    $this->app['auth']->logout();
    $this->travelTo(now()->setTime(8, 45));
    $this->artisan('attendance:build-notifications')->assertExitCode(0);
    expect(NotificationBatch::query()->count())->toBe(0);
    $this->travelTo(now()->setTime(9, 15));
    $this->artisan('attendance:build-notifications')->assertExitCode(0);
    $this->artisan('attendance:build-notifications')->assertExitCode(0);
    expect(NotificationBatch::query()->count())->toBe(1)
        ->and(NotificationBatch::query()->firstOrFail()->run_key)->toBe('scheduled:2026-10-25');
});

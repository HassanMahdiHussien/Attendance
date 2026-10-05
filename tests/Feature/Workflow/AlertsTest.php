<?php

use App\Enums\CaseStatus;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Law;
use App\Models\User;
use App\Notifications\CaseDeadlineAlert;
use App\Services\Cases\CaseAlertDispatcher;
use App\Services\Cases\ExpiredAppealArchiver;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

test('an alert has one claim, retries a failed delivery, and notifies active recipients in Arabic', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'hr_staff']);
    $inactive = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin', 'is_active' => false]);
    $law = Law::factory()->create();
    $case = DisciplinaryCase::factory()->create(['law_version_id' => $law->id, 'status' => CaseStatus::Open,
        'opened_by_user_id' => $admin->id, 'assigned_hr_user_id' => $hr->id]);
    $dispatcher = app(CaseAlertDispatcher::class);

    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Mailer failed'));
    $dispatcher->deliver($case, 'procedure_approaching', '2026-07-03');
    expect(DB::table('case_alerts')->where('case_id', $case->id)->value('status'))->toBe('failed');

    Notification::fake();
    $dispatcher->deliver($case, 'procedure_approaching', '2026-07-03');
    $dispatcher->deliver($case, 'procedure_approaching', '2026-07-03');
    $alert = DB::table('case_alerts')->where('case_id', $case->id)->first();
    expect($alert->status)->toBe('sent');
    expect((int) $alert->attempts)->toBe(2);
    expect($case->events()->where('event_type', 'deadline_alert_sent')->count())->toBe(1);
    Notification::assertSentTo([$admin, $hr], CaseDeadlineAlert::class);
    Notification::assertNotSentTo($inactive, CaseDeadlineAlert::class);
    $notification = new CaseDeadlineAlert($case, 'procedure_approaching');
    expect($notification->toMail($admin)->subject)->toContain('تنبيه');
});

test('the scheduled dispatcher iterates companies and the archiver waits until the day after appeal end', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-09 12:00', 'Asia/Baghdad'));
    try {
        $company = Company::factory()->create();
        app(TenantContext::class)->setCompany($company);
        $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
        $law = Law::factory()->create();
        $case = DisciplinaryCase::factory()->create(['law_version_id' => $law->id,
            'status' => CaseStatus::AwaitingAppealWindow, 'opened_by_user_id' => $admin->id,
            'defense_letter_issued_at' => CarbonImmutable::parse('2026-08-01', 'Asia/Baghdad'),
            'decision_issued_at' => CarbonImmutable::parse('2026-08-10', 'Asia/Baghdad')]);
        DB::table('appeal_trackings')->insert(['company_id' => $company->id, 'case_id' => $case->id,
            'window_start' => '2026-08-10', 'window_end' => '2026-09-09', 'appeal_filed' => false,
            'created_at' => now(), 'updated_at' => now()]);
        Notification::fake();
        app(CaseAlertDispatcher::class)->run();
        app(CaseAlertDispatcher::class)->run();
        expect(DB::table('case_alerts')->where('case_id', $case->id)->where('alert_type', 'appeal_window_ending')->count())->toBe(1);
        app(ExpiredAppealArchiver::class)->run();
        expect($case->fresh()->status)->toBe(CaseStatus::AwaitingAppealWindow);
        $this->travelTo(CarbonImmutable::parse('2026-09-10 00:00', 'Asia/Baghdad'));
        app(ExpiredAppealArchiver::class)->run();
        expect($case->fresh()->status)->toBe(CaseStatus::ArchivedFinal);
    } finally {
        $this->travelBack();
    }
});

test('a fresh pending claim cannot be reclaimed by an overlapping run', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $actor = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create();
    $case = DisciplinaryCase::factory()->create(['law_version_id' => $law->id,
        'status' => CaseStatus::Open, 'opened_by_user_id' => $actor->id]);
    DB::table('case_alerts')->insert(['company_id' => $company->id, 'case_id' => $case->id,
        'alert_type' => 'consultation_pending', 'stage' => '2026-10-05', 'status' => 'pending',
        'attempts' => 1, 'created_at' => now(), 'updated_at' => now()]);
    Notification::fake();
    app(CaseAlertDispatcher::class)->deliver($case, 'consultation_pending', '2026-10-05');
    Notification::assertNothingSent();
    expect(DB::table('case_alerts')->where('case_id', $case->id)->value('attempts'))->toBe(1);
    DB::table('case_alerts')->where('case_id', $case->id)->update(['updated_at' => now()->subMinutes(16)]);
    app(CaseAlertDispatcher::class)->deliver($case, 'consultation_pending', '2026-10-05');
    Notification::assertSentTo($actor, CaseDeadlineAlert::class);
    expect(DB::table('case_alerts')->where('case_id', $case->id)->value('status'))->toBe('sent');
});

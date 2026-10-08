<?php

use App\Enums\AttachmentKind;
use App\Enums\EmailDraftStatus;
use App\Enums\UserRole;
use App\Livewire\Reports\Inbox;
use App\Livewire\Reports\MyReports;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\CaseEvent;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\EmailDraft;
use App\Models\EmailIntakeMessage;
use App\Models\EmailIntakeRejection;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use App\Services\Dashboards\CaseMetrics;
use App\Services\Dashboards\DashboardFilters;
use App\Services\EmailIntake\EmailContentSanitizer;
use App\Services\EmailIntake\EmailDraftService;
use App\Services\EmailIntake\EmailIntakeFetcher;
use App\Services\EmailIntake\EmailSenderVerifier;
use App\Services\EmailIntake\IntakeMailbox;
use App\Services\EmailIntake\IntakeMessage;
use App\Support\Access\EmailDraftVisibility;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Fakes\FakeIntakeMailbox;

beforeEach(function (): void {
    config()->set('displanery.email_intake.trusted_authserv_id', 'mx.example.test');
    config()->set('displanery.email_intake.max_message_bytes', 25 * 1024 * 1024);
    $this->mailbox = new FakeIntakeMailbox;
    app()->instance(IntakeMailbox::class, $this->mailbox);
});

function intakeHeaders(string $address, string $verdict = 'dmarc=pass', string $id = 'mx.example.test', string $extra = ''): string
{
    return "Authentication-Results: {$id}; {$verdict}\r\n{$extra}From: Sender <{$address}>\r\nSubject: Incident\r\nMessage-ID: <incident@example.test>\r\n";
}

function intakeMessage(int $uid, string $headers, string $body = 'Incident text', array $attachments = []): IntakeMessage
{
    return new IntakeMessage($uid, 1024, $headers, CarbonImmutable::parse('2026-10-08 08:00:00'), $body, '', $attachments);
}

function intakeRun(FakeIntakeMailbox $mailbox): array
{
    return app(EmailIntakeFetcher::class)->run($mailbox);
}

test('only the topmost trusted raw authentication result is considered', function (): void {
    $verifier = app(EmailSenderVerifier::class);
    $base = "From: Sender <manager@example.test>\r\n";
    expect($verifier->authenticated("Authentication-Results: mx.example.test; dmarc=pass\r\n".$base, 'example.test'))->toBeTrue();
    expect($verifier->authenticated($base, 'example.test'))->toBeFalse();
    expect($verifier->authenticated("Authentication-Results: other.test; dmarc=pass\r\n".$base, 'example.test'))->toBeFalse();
    expect($verifier->authenticated("Authentication-Results: mx.example.test; dmarc=fail\r\nAuthentication-Results: mx.example.test; dmarc=pass\r\n".$base, 'example.test'))->toBeFalse();
    expect($verifier->authenticated("Authentication-Results: mx.example.test; spf=pass smtp.mailfrom=mail.example.test\r\n".$base, 'example.test'))->toBeTrue();
    expect($verifier->authenticated("Authentication-Results: mx.example.test; dkim=pass header.d=mail.example.test\r\n".$base, 'example.test'))->toBeTrue();
    expect($verifier->authenticated("Authentication-Results: mx.example.test; spf=pass smtp.mailfrom=else.test; dkim=pass header.d=else.test\r\n".$base, 'example.test'))->toBeFalse();
});

test('empty emails and long bodies sanitize without inventing structured facts', function (): void {
    $empty = intakeMessage(1, "From: sender@example.test\r\nSubject: Empty\r\n", '');
    expect(app(EmailContentSanitizer::class)->content($empty))->toBe(['Empty', '', false]);
    $long = intakeMessage(2, "Subject: Long\r\n", str_repeat('x', 50001));
    [$subject, $body, $truncated] = app(EmailContentSanitizer::class)->content($long);
    expect($subject)->toBe('Long')->and(mb_strlen($body))->toBe(50000)->and($truncated)->toBeTrue();
});

test('accepted manager mail creates one branch-scoped draft and UID move retry stays idempotent', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $branch = Branch::factory()->create();
    $manager = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::DeptManager]);
    BranchUser::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $manager->id]);
    $this->mailbox->messages[1] = intakeMessage(1, intakeHeaders($manager->email));
    $this->mailbox->failMove = true;
    expect(intakeRun($this->mailbox)['accepted'])->toBe(1);
    $draft = EmailDraft::firstOrFail();
    expect($draft->branch_id)->toBe($branch->id);
    expect(EmailIntakeMessage::firstOrFail()->outcome)->toBe('accepted');
    $this->mailbox->failMove = false;
    intakeRun($this->mailbox);
    expect(EmailDraft::count())->toBe(1);
    expect($this->mailbox->moved[1])->toBe('Processed');
});

test('missing Message-ID falls back to UID and several or no branches leave branch empty', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $manager = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::DeptManager]);
    $headers = str_replace("Message-ID: <incident@example.test>\r\n", '', intakeHeaders($manager->email));
    $this->mailbox->messages[1] = intakeMessage(1, $headers);
    intakeRun($this->mailbox);
    expect(EmailDraft::firstOrFail()->branch_id)->toBeNull();
    $branches = Branch::factory()->count(2)->create();
    foreach ($branches as $branch) {
        BranchUser::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $manager->id]);
    }
    $this->mailbox->messages[2] = intakeMessage(2, $headers);
    intakeRun($this->mailbox);
    expect(EmailDraft::count())->toBe(2);
    expect(EmailDraft::latest('id')->first()->branch_id)->toBeNull();
});

test('unknown inactive and disallowed senders leave metadata-only rejection rows', function (): void {
    $company = Company::factory()->create();
    $inactive = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::Reporter, 'is_active' => false]);
    $disallowed = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CommitteeMember]);
    $platform = User::factory()->create(['primary_role' => UserRole::PlatformAdmin]);
    foreach (['unknown@example.test', $inactive->email, $disallowed->email, $platform->email] as $index => $address) {
        $this->mailbox->messages[$index + 1] = intakeMessage($index + 1, intakeHeaders($address));
    }
    intakeRun($this->mailbox);
    expect(EmailDraft::withoutGlobalScopes()->count())->toBe(0);
    expect(EmailIntakeRejection::pluck('reason')->all())->toBe(['unknown_sender', 'inactive_sender', 'not_allowed_role', 'not_allowed_role']);
    expect(array_keys((array) DB::table('email_intake_rejections')->first()))->not->toContain('subject', 'body_text', 'sender_address');
    $company->update(['is_active' => false]);
    $active = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::Reporter]);
    $this->mailbox->messages[5] = intakeMessage(5, intakeHeaders($active->email));
    intakeRun($this->mailbox);
    expect(EmailIntakeRejection::latest('id')->first()->reason)->toBe('inactive_sender');
});

test('HTML is reduced to text, attachments are filtered, and oversize messages are rejected', function (): void {
    $company = Company::factory()->create();
    $sender = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::Reporter]);
    $message = intakeMessage(1, intakeHeaders($sender->email), '', [
        ['name' => 'bad.exe', 'content' => 'plain'], ['name' => 'image.png', 'content' => 'plain'],
    ]);
    $message->html = '<p>Hello</p><script>steal()</script><img src="https://remote.test/x">';
    $this->mailbox->messages[1] = $message;
    intakeRun($this->mailbox);
    app(TenantContext::class)->setCompany($company);
    $draft = EmailDraft::firstOrFail();
    expect($draft->body_text)->toContain('Hello')->not->toContain('steal')->not->toContain('<img');
    expect($draft->excluded_attachments)->toBe(['bad.exe', 'image.png']);
    $big = intakeMessage(2, intakeHeaders($sender->email));
    $big->size = 26 * 1024 * 1024;
    $this->mailbox->messages[2] = $big;
    intakeRun($this->mailbox);
    expect(EmailIntakeRejection::latest('id')->first()->reason)->toBe('too_large');
});

test('three parse failures are quarantined and health pings contain counts only', function (): void {
    config()->set('displanery.healthchecks.email_intake', 'https://ping.example.test/check');
    Http::fake();
    Log::spy();
    intakeRun($this->mailbox);
    Http::assertSent(fn ($request) => ! str_ends_with($request->url(), '/fail'));
    $company = Company::factory()->create();
    $sender = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::Reporter]);
    $this->mailbox->messages[1] = intakeMessage(1, intakeHeaders($sender->email));
    $this->mailbox->failHydrate = true;
    intakeRun($this->mailbox);
    intakeRun($this->mailbox);
    intakeRun($this->mailbox);
    expect(EmailIntakeMessage::firstOrFail()->attempts)->toBe(3);
    expect($this->mailbox->moved[1])->toBe('Discarded');
    expect($this->mailbox->purged)->toBe(['Processed' => 30, 'Discarded' => 7]);
    $this->mailbox->failConnect = true;
    expect(intakeRun($this->mailbox)['failed'])->toBe(1);
    Http::assertSent(fn ($request) => ! str_contains($request->body(), $sender->email) && ! str_contains($request->body(), 'Incident'));
    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => ! str_contains(json_encode([$message, $context]), $sender->email)
        && ! str_contains(json_encode([$message, $context]), 'Incident'))->atLeast()->once();
});

test('tenant inbox, draft URL and download do not reveal another company', function (): void {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    $sender = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::Reporter]);
    $foreignHr = User::factory()->create(['company_id' => $other->id, 'primary_role' => UserRole::CompanyAdmin]);
    app(TenantContext::class)->setCompany($other);
    $this->mailbox->messages[1] = intakeMessage(1, intakeHeaders($sender->email));
    intakeRun($this->mailbox);
    expect(EmailDraft::count())->toBe(0);
    app(TenantContext::class)->setCompany($company);
    $draft = EmailDraft::firstOrFail();
    expect(EmailDraftVisibility::inbox($foreignHr)->whereKey($draft->id)->exists())->toBeFalse();
    $this->actingAs($foreignHr)->get('/cases/new?email='.$draft->id)->assertNotFound();
    $this->actingAs($foreignHr)->get('/reports/inbox')->assertDontSee('Incident text');
});

test('HR converts with entered facts and sender sees bare status; dismissal closes another draft', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $branch = Branch::factory()->create();
    $employee = Employee::factory()->create(['branch_id' => $branch->id]);
    $sender = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::DeptManager]);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);
    $this->mailbox->messages[1] = intakeMessage(1, intakeHeaders($sender->email));
    intakeRun($this->mailbox);
    $draft = EmailDraft::firstOrFail();
    $this->actingAs($hr)->get('/cases/new?email='.$draft->id)->assertOk()->assertSee('Incident text');
    $facts = ['branch_id' => $branch->id, 'employee_id' => $employee->id, 'violation_type' => 'Entered fact',
        'violation_time' => now(), 'violation_place_freetext' => 'Entered place'];
    $case = app(EmailDraftService::class)->convert($draft, $hr, $facts);
    expect($case->violation_type)->toBe('Entered fact');
    expect($case->description)->toBeNull();
    expect($draft->fresh()->status)->toBe(EmailDraftStatus::Converted);
    $this->actingAs($sender)->get('/reports')->assertSee('Under review');
    $this->mailbox->messages[2] = intakeMessage(2, str_replace('incident@example.test', 'second@example.test', intakeHeaders($sender->email)));
    intakeRun($this->mailbox);
    $second = EmailDraft::latest('id')->first();
    app(EmailDraftService::class)->dismiss($second, 'Not a report', $hr);
    expect($second->fresh()->status)->toBe(EmailDraftStatus::Dismissed);
    $this->actingAs($sender)->get('/reports')->assertSee('Closed');
});

test('draft downloads require HR visibility and conversion copies evidence to the case', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $branch = Branch::factory()->create();
    $employee = Employee::factory()->create(['branch_id' => $branch->id]);
    $sender = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::Reporter]);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);
    $foreign = Company::factory()->create();
    $foreignHr = User::factory()->create(['company_id' => $foreign->id, 'primary_role' => UserRole::CompanyAdmin]);
    $draft = EmailDraft::create(['company_id' => $company->id, 'sender_user_id' => $sender->id,
        'subject' => 'Incident', 'body_text' => 'Raw email', 'received_at' => now()]);
    $attachment = app(AttachmentService::class)->storeEmailBytes($draft, 'proof.pdf', "%PDF-1.4\n1 0 obj\n", $sender);
    $this->actingAs($foreignHr)->get('/attachments/'.$attachment->id)->assertNotFound();
    $this->actingAs($sender)->get('/attachments/'.$attachment->id)->assertNotFound();
    $this->actingAs($hr)->get('/attachments/'.$attachment->id)->assertOk();
    $facts = ['branch_id' => $branch->id, 'employee_id' => $employee->id, 'violation_type' => 'Entered',
        'violation_time' => now(), 'violation_place_freetext' => 'Office'];
    $case = app(EmailDraftService::class)->convert($draft, $hr, $facts);
    $copy = Attachment::where('attachable_type', 'case')->where('attachable_id', $case->id)->firstOrFail();
    expect($copy->path)->not->toBe($attachment->path);
    expect(Storage::disk('local')->exists($copy->path))->toBeTrue();
    expect($copy->kind)->toBe(AttachmentKind::ReportEvidence);
    expect(AuditLog::where('action', 'email_draft.converted')->exists())->toBeTrue();
});

test('linking copies evidence and a failed copy rolls back without orphan files', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $branch = Branch::factory()->create();
    $employee = Employee::factory()->create(['branch_id' => $branch->id]);
    $sender = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::Reporter]);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);
    $case = DisciplinaryCase::factory()->create(['branch_id' => $branch->id, 'employee_id' => $employee->id, 'opened_by_user_id' => $hr->id]);
    $draft = EmailDraft::create(['company_id' => $company->id, 'sender_user_id' => $sender->id,
        'subject' => 'Incident', 'body_text' => 'Raw email', 'received_at' => now()]);
    app(AttachmentService::class)->storeEmailBytes($draft, 'proof.pdf', "%PDF-1.4\n1 0 obj\n", $sender);
    app(EmailDraftService::class)->link($draft, $case, $hr);
    expect($draft->fresh()->status)->toBe(EmailDraftStatus::Linked);
    expect(CaseEvent::where('case_id', $case->id)->where('event_type', 'duplicate_report_linked')->exists())->toBeTrue();
    expect(Attachment::where('attachable_type', 'case')->where('attachable_id', $case->id)->count())->toBe(1);

    $second = EmailDraft::create(['company_id' => $company->id, 'sender_user_id' => $sender->id,
        'subject' => 'Second', 'body_text' => 'Raw email', 'received_at' => now()]);
    app(AttachmentService::class)->storeEmailBytes($second, 'proof.pdf', "%PDF-1.4\n1 0 obj\n", $sender);
    app()->instance(AttachmentService::class, new class extends AttachmentService
    {
        public function copyEmailEvidence(Attachment $source, DisciplinaryCase $case, array &$copiedPaths): Attachment
        {
            parent::copyEmailEvidence($source, $case, $copiedPaths);
            throw new RuntimeException('test copy failure');
        }
    });
    expect(fn () => app(EmailDraftService::class)->link($second, $case, $hr))->toThrow(RuntimeException::class);
    expect($second->fresh()->status)->toBe(EmailDraftStatus::Received);
    expect(Attachment::where('attachable_type', 'case')->where('attachable_id', $case->id)->count())->toBe(1);
    expect(Storage::disk('local')->files('attachments/'.$company->id.'/cases/'.$case->id))->toHaveCount(1);
});

test('a sixth attachment and an oversized attachment are excluded while valid evidence is kept', function (): void {
    $company = Company::factory()->create();
    $sender = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::Reporter]);
    $parts = [['name' => 'huge.pdf', 'content' => "%PDF-1.4\n".str_repeat('a', 10 * 1024 * 1024)]];
    for ($i = 1; $i <= 6; $i++) {
        $parts[] = ['name' => "proof{$i}.pdf", 'content' => "%PDF-1.4\n1 0 obj\n"];
    }
    $this->mailbox->messages[1] = intakeMessage(1, intakeHeaders($sender->email), 'Facts', $parts);
    intakeRun($this->mailbox);
    app(TenantContext::class)->setCompany($company);
    $draft = EmailDraft::firstOrFail();
    expect($draft->attachments()->count())->toBe(5);
    expect($draft->excluded_attachments)->toBe(['huge.pdf', 'proof6.pdf']);
});

test('duplicate Message-ID in a second UID uses one draft and drafts never enter dashboard metrics', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);
    $sender = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::Reporter]);
    $filters = DashboardFilters::fromInput($hr, ['period' => '6']);
    $before = app(CaseMetrics::class)->counts($hr, $filters);
    $this->mailbox->messages[1] = intakeMessage(1, intakeHeaders($sender->email));
    $this->mailbox->messages[2] = intakeMessage(2, intakeHeaders($sender->email));
    intakeRun($this->mailbox);
    expect(EmailDraft::count())->toBe(1);
    expect(EmailIntakeMessage::count())->toBe(2);
    expect(app(CaseMetrics::class)->counts($hr, $filters))->toBe($before);
});

test('Livewire inbox dismisses an email and MyReports shows only the bare closed status', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $sender = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::Reporter]);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);
    $draft = EmailDraft::create(['company_id' => $company->id, 'sender_user_id' => $sender->id,
        'subject' => 'Private subject', 'body_text' => 'Private body', 'received_at' => now()]);
    $this->actingAs($hr);
    Livewire::test(Inbox::class)->assertSee('Forwarded email')->assertSee('Private body')
        ->set('emailReasons.'.$draft->id, 'Outside policy')->call('dismissEmail', $draft->id);
    expect($draft->fresh()->status)->toBe(EmailDraftStatus::Dismissed);
    $this->actingAs($sender);
    Livewire::test(MyReports::class)->assertSee('Closed')->assertDontSee('Private subject')->assertDontSee('Private body');
});

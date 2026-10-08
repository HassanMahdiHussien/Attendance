<?php

use App\Enums\AttachmentKind;
use App\Enums\CaseStatus;
use App\Enums\ReportStatus;
use App\Enums\SignaturePurpose;
use App\Enums\SignatureStatus;
use App\Enums\UserRole;
use App\Livewire\Auth\Login;
use App\Livewire\Cases\CreateCase;
use App\Models\Attachment;
use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\Employee;
use App\Models\HrPartner;
use App\Models\LawFirmPartner;
use App\Models\User;
use App\Models\ViolationReport;
use App\Services\Attachments\AttachmentService;
use App\Services\Cases\CaseAssignment;
use App\Services\Cases\CaseBoard;
use App\Services\Cases\CaseDocumentIssuance;
use App\Services\Cases\CaseDrafts;
use App\Services\Cases\HandOffService;
use App\Services\Documents\DocumentCorrector;
use App\Services\Reports\ReportService;
use App\Support\Access\CaseVisibility;
use App\Support\Access\Landing;
use App\Support\Access\ViolationReportVisibility;
use App\Support\Format\ArabicDigits;
use App\Support\Format\StageIndicator;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

test('the shared login sends roles to their own workspace', function (UserRole $role, string $target): void {
    $company = Company::factory()->create();
    $user = User::factory()->create(['primary_role' => $role, 'company_id' => $company->id]);
    RateLimiter::clear('login-email:'.hash('sha256', $user->email));
    RateLimiter::clear('login-ip:'.hash('sha256', (string) request()->ip()));
    Livewire::test(Login::class)->set('email', $user->email)->set('password', 'password')
        ->call('authenticate')->assertRedirect($target);
})->with([
    [UserRole::CompanyAdmin, '/cases'], [UserRole::HrStaff, '/cases'],
    [UserRole::Reporter, '/reports'], [UserRole::DeptManager, '/reports'],
    [UserRole::CommitteeMember, '/my-cases'],
]);

test('cross-company roles have their own landing pages', function (): void {
    expect(Landing::for(User::factory()->make(['primary_role' => UserRole::PlatformAdmin])))->toBe('/platform');
    expect(Landing::for(User::factory()->make(['primary_role' => UserRole::ExternalLegalReviewer])))->toBe('/my-cases');
    expect(Landing::for(User::factory()->make(['primary_role' => UserRole::HrPartnerStaff])))->toBe('/home');
});

test('panel and workspace failures share the email and IP throttle', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);
    $emailKey = 'login-email:'.hash('sha256', $user->email);
    $ipKey = 'login-ip:'.hash('sha256', (string) request()->ip());
    RateLimiter::clear($emailKey);
    RateLimiter::clear($ipKey);
    Filament::setCurrentPanel('admin');
    Livewire::test(App\Filament\Auth\Login::class)->fillForm(['email' => $user->email, 'password' => 'wrong'])
        ->call('authenticate')->assertHasFormErrors(['email']);
    Livewire::test(Login::class)->set('email', $user->email)->set('password', 'wrong')
        ->call('authenticate')->assertHasErrors(['email']);
    expect(RateLimiter::attempts($emailKey))->toBe(2);
    expect(RateLimiter::attempts($ipKey))->toBe(2);
});

test('shared sign-in refuses an inactive account with the generic message', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::Reporter,
        'is_active' => false]);
    RateLimiter::clear('login-email:'.hash('sha256', $user->email));
    RateLimiter::clear('login-ip:'.hash('sha256', (string) request()->ip()));
    Livewire::test(Login::class)->set('email', $user->email)->set('password', 'password')
        ->call('authenticate')->assertHasErrors(['email']);
    expect(auth()->check())->toBeFalse();
});

test('workspace URLs refuse other roles', function (): void {
    $company = Company::factory()->create();
    $reporter = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::Reporter]);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    $this->actingAs($reporter)->get('/cases')->assertForbidden();
    $this->actingAs($reporter)->get('/cases/new')->assertForbidden();
    $this->actingAs($reporter)->get('/reports/inbox')->assertForbidden();
    $this->actingAs($hr)->get('/reports')->assertForbidden();
    $this->actingAs($hr)->get('/reports/new')->assertForbidden();
    $this->actingAs($hr)->get('/my-cases')->assertForbidden();
    $committee = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CommitteeMember]);
    $this->actingAs($committee)->get('/cases')->assertForbidden();
    $this->actingAs($committee)->get('/reports')->assertForbidden();
    $this->actingAs($committee)->get('/my-cases')->assertOk();
});

test('each non-panel role reaches only its workspace list', function (UserRole $role, string $allowed): void {
    $company = Company::factory()->create();
    $attributes = ['primary_role' => $role];
    if (in_array($role, [UserRole::Reporter, UserRole::DeptManager, UserRole::CommitteeMember], true)) {
        $attributes['company_id'] = $company->id;
    } elseif ($role === UserRole::ExternalLegalReviewer) {
        $attributes['law_firm_partner_id'] = LawFirmPartner::factory()->create()->id;
    } else {
        $attributes['hr_partner_id'] = HrPartner::factory()->create()->id;
    }
    $user = User::factory()->create($attributes);
    $this->actingAs($user)->get($allowed)->assertOk();
    foreach (['/cases', '/reports', '/reports/inbox', '/my-cases', '/home'] as $path) {
        if ($path !== $allowed) {
            $this->get($path)->assertForbidden();
        }
    }
})->with([
    [UserRole::Reporter, '/reports'], [UserRole::DeptManager, '/reports'],
    [UserRole::CommitteeMember, '/my-cases'], [UserRole::ExternalLegalReviewer, '/my-cases'],
    [UserRole::HrPartnerStaff, '/home'],
]);

test('report submission requires branch membership and the inbox includes company HR', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $reporter = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::Reporter]);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    $data = ['branch_id' => $branch->id, 'place_freetext' => 'البوابة', 'occurred_at' => now(),
        'violation_type' => 'تأخير', 'description' => 'وصف البلاغ'];
    expect(fn () => app(ReportService::class)->submit($data, $reporter))->toThrow(HttpException::class);
    BranchUser::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $reporter->id]);
    $report = app(ReportService::class)->submit($data, $reporter);
    expect(ViolationReportVisibility::inbox($hr)->whereKey($report->id)->exists())->toBeTrue();
    expect(ViolationReportVisibility::for($reporter)->whereKey($report->id)->exists())->toBeTrue();
    $anotherReporter = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::Reporter]);
    expect(ViolationReportVisibility::for($anotherReporter)->whereKey($report->id)->exists())->toBeFalse();
    app(ReportService::class)->dismiss($report, 'ليس مخالفة', $hr);
    expect($report->fresh()->status)->toBe(ReportStatus::Dismissed);
});

test('a reporter cannot create a case draft directly', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id]);
    $reporter = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::Reporter]);
    $facts = ['branch_id' => $branch->id, 'employee_id' => $employee->id, 'violation_type' => 'تأخير',
        'violation_time' => now(), 'violation_place_freetext' => 'المكتب'];
    expect(fn () => app(CaseDrafts::class)->create($facts, $reporter))->toThrow(AuthorizationException::class);
});

test('attachments reject disguised files and an unauthorized case parent', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $reporter = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::Reporter]);
    $report = ViolationReport::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id,
        'reporter_user_id' => $reporter->id]);
    $file = UploadedFile::fake()->createWithContent('proof.pdf', 'plain text');
    expect(fn () => app(AttachmentService::class)->store($report, AttachmentKind::ReportEvidence, $file, $reporter))
        ->toThrow(ValidationException::class);
    expect(fn () => app(AttachmentService::class)->store($report, AttachmentKind::DeliveryProof, $file, $reporter))
        ->toThrow(ValidationException::class);
    $large = UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf');
    expect(fn () => app(AttachmentService::class)->store($report, AttachmentKind::ReportEvidence, $large, $reporter))
        ->toThrow(ValidationException::class);
    $other = Company::factory()->create();
    $foreignReporter = User::factory()->create(['company_id' => $other->id, 'primary_role' => UserRole::Reporter]);
    expect(fn () => app(AttachmentService::class)->store($report, AttachmentKind::ReportEvidence, $file, $foreignReporter))
        ->toThrow(HttpException::class);
});

test('case mutation services refuse reporter, committee and foreign HR callers', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $owner = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);
    $reporter = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::Reporter]);
    $committee = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CommitteeMember]);
    $other = Company::factory()->create();
    $foreignHr = User::factory()->create(['company_id' => $other->id, 'primary_role' => UserRole::HrStaff]);
    $case = DisciplinaryCase::factory()->create(['company_id' => $company->id, 'opened_by_user_id' => $owner->id]);
    $document = Document::factory()->create(['company_id' => $company->id, 'case_id' => $case->id]);
    foreach ([$reporter, $committee, $foreignHr] as $actor) {
        expect(fn () => app(CaseDocumentIssuance::class)->issue($document, $actor))
            ->toThrow(AuthorizationException::class);
        expect(fn () => app(DocumentCorrector::class)->startCorrection($document, 'تصحيح', $actor))
            ->toThrow(AuthorizationException::class);
        expect(fn () => app(HandOffService::class)->record($case,
            SignaturePurpose::DefenseLetterReceipt, SignatureStatus::Signed,
            ['signed_at' => now()], $actor))->toThrow(AuthorizationException::class);
    }
});

test('an invisible case URL returns 404', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $owner = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);
    $case = DisciplinaryCase::factory()->create(['company_id' => $company->id, 'opened_by_user_id' => $owner->id]);
    $reporter = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::Reporter]);
    $this->actingAs($reporter)->get('/cases/'.$case->id)->assertNotFound();
});

test('linking a duplicate report uses the existing case without consuming a case number', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id]);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);
    $case = DisciplinaryCase::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id,
        'employee_id' => $employee->id, 'opened_by_user_id' => $hr->id, 'violation_time' => now()]);
    $report = ViolationReport::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id,
        'employee_id' => $employee->id, 'occurred_at' => now()]);
    $count = DisciplinaryCase::count();
    $linked = app(ReportService::class)->convert($report, $hr, $case);
    expect($linked->id)->toBe($case->id);
    expect(DisciplinaryCase::count())->toBe($count);
    expect($report->fresh()->status)->toBe(ReportStatus::ConvertedToCase);
    expect($case->events()->where('event_type', 'duplicate_report_linked')->exists())->toBeTrue();
});

test('HR can convert a report into a prefilled case draft', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id]);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);
    $report = ViolationReport::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id,
        'employee_id' => null, 'place_freetext' => 'المخزن', 'violation_type' => 'تأخير']);
    $case = app(ReportService::class)->convert($report, $hr, null, ['employee_id' => $employee->id]);
    expect($case->violation_report_id)->toBe($report->id);
    expect($case->violation_place_freetext)->toBe('المخزن');
    expect($report->fresh()->status)->toBe(ReportStatus::ConvertedToCase);
    expect((int) $report->fresh()->case_id)->toBe($case->id);
});

test('board labels render Arabic day counts and five stage positions', function (): void {
    expect(StageIndicator::remaining(1))->toBe('باقي يوم');
    expect(StageIndicator::remaining(2))->toBe('باقي يومان');
    expect(StageIndicator::step(CaseStatus::Draft))->toBe(1);
    expect(StageIndicator::step(CaseStatus::ArchivedFinal))->toBe(5);
});

test('the board orders urgent cases first and keeps an HR user in their visibility scope', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $owner = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    $other = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    $dueSoon = DisciplinaryCase::factory()->create(['company_id' => $company->id,
        'opened_by_user_id' => $owner->id, 'defense_letter_issued_at' => now()->subDays(14)]);
    $later = DisciplinaryCase::factory()->create(['company_id' => $company->id,
        'opened_by_user_id' => $owner->id, 'defense_letter_issued_at' => now()->subDays(2)]);
    DisciplinaryCase::factory()->create(['company_id' => $company->id,
        'opened_by_user_id' => $other->id, 'assigned_hr_user_id' => $other->id,
        'defense_letter_issued_at' => now()->subDays(15)]);
    $board = app(CaseBoard::class);
    expect($board->page($owner)->pluck('id')->all())->toBe([$dueSoon->id, $later->id]);
    expect($board->urgentCount($owner))->toBe(1);
});

test('assignment changes HR visibility', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);
    $old = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    $new = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    $case = DisciplinaryCase::factory()->create(['company_id' => $company->id,
        'opened_by_user_id' => $admin->id, 'assigned_hr_user_id' => $old->id]);
    $reporter = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::Reporter]);
    expect(fn () => app(CaseAssignment::class)->assign($case, $new, $reporter))
        ->toThrow(AuthorizationException::class);
    expect(CaseVisibility::for($old)->whereKey($case->id)->exists())->toBeTrue();
    app(CaseAssignment::class)->assign($case, $new, $admin);
    expect(CaseVisibility::for($old)->whereKey($case->id)->exists())->toBeFalse();
    expect(CaseVisibility::for($new)->whereKey($case->id)->exists())->toBeTrue();
});

test('case intake shows a same-employee duplicate before opening', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id]);
    $earlier = DisciplinaryCase::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id,
        'employee_id' => $employee->id, 'opened_by_user_id' => $admin->id, 'violation_time' => now()->subHours(30)]);
    $this->actingAs($admin);
    Livewire::test(CreateCase::class)
        ->set('branchId', (string) $branch->id)->set('employeeId', (string) $employee->id)
        ->set('place', 'البوابة')->set('violationTime', now()->format('Y-m-d H:i:s'))
        ->set('violationType', 'تأخير')->call('checkDuplicates')
        ->assertSet('checkedDuplicates', true)->assertSee($earlier->case_number);
});

test('case attachment downloads require case visibility', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);
    $reporter = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::Reporter]);
    $case = DisciplinaryCase::factory()->create(['company_id' => $company->id, 'opened_by_user_id' => $hr->id]);
    Storage::disk('local')->put('attachments/proof.pdf', '%PDF-1.4');
    $attachment = Attachment::create(['company_id' => $company->id, 'attachable_type' => 'case',
        'attachable_id' => $case->id, 'kind' => AttachmentKind::DeliveryProof, 'disk' => 'local',
        'path' => 'attachments/proof.pdf', 'original_name' => 'proof.pdf', 'mime_type' => 'application/pdf',
        'size_bytes' => 8, 'uploaded_by' => $hr->id]);
    $this->actingAs($reporter)->get('/attachments/'.$attachment->id)->assertNotFound();
    $other = Company::factory()->create();
    $foreignHr = User::factory()->create(['company_id' => $other->id, 'primary_role' => UserRole::HrStaff]);
    $this->actingAs($foreignHr)->get('/attachments/'.$attachment->id)->assertNotFound();
    $this->actingAs($hr)->get('/attachments/'.$attachment->id)->assertOk();
});

test('report evidence is private to the HR inbox and converted case viewers', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);
    $reporter = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::Reporter]);
    $report = ViolationReport::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id,
        'reporter_user_id' => $reporter->id]);
    Storage::disk('local')->put('attachments/report.pdf', '%PDF-1.4');
    $attachment = Attachment::create(['company_id' => $company->id, 'attachable_type' => 'violation_report',
        'attachable_id' => $report->id, 'kind' => AttachmentKind::ReportEvidence, 'disk' => 'local',
        'path' => 'attachments/report.pdf', 'original_name' => 'report.pdf', 'mime_type' => 'application/pdf',
        'size_bytes' => 8, 'uploaded_by' => $reporter->id]);
    $this->actingAs($reporter)->get('/attachments/'.$attachment->id)->assertNotFound();
    $this->actingAs($hr)->get('/attachments/'.$attachment->id)->assertOk();
    $case = DisciplinaryCase::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id,
        'opened_by_user_id' => $hr->id]);
    $report->update(['status' => ReportStatus::ConvertedToCase, 'case_id' => $case->id]);
    $this->actingAs($hr)->get('/attachments/'.$attachment->id)->assertOk();
});

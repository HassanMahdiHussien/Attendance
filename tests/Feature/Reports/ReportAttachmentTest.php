<?php

use App\Ai\ClaudeClient;
use App\Ai\PayloadBuilder;
use App\Ai\ScanBlocks;
use App\Enums\AttachmentKind;
use App\Jobs\AskReportAssistant;
use App\Livewire\Cases\CreateCase;
use App\Livewire\Reports\SubmitReport;
use App\Models\Attachment;
use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\CaseFileIntake;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Employee;
use App\Models\ReportAssistantSession;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use App\Services\Cases\CaseFileSuggestions;
use App\Services\Documents\DocumentText;
use App\Services\Documents\MsgReader;
use App\Services\Reports\ReportAssistant;
use App\Services\Reports\ReportFiles;
use App\Services\Reports\ReportService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Fakes\FakeClaudeClient;

function reportFilesFixture(): array
{
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $reporter = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'reporter']);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'hr_staff']);
    BranchUser::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $reporter->id]);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id,
        'name' => 'Ali Hayder', 'name_en' => 'Ali Hayder']);
    $session = ReportAssistantSession::factory()->create(['company_id' => $company->id,
        'user_id' => $reporter->id, 'language' => 'en', 'facts' => ['branch_id' => $branch->id],
        'transcript' => [['role' => 'reporter', 'text' => 'A batch error happened']]]);

    return compact('company', 'branch', 'reporter', 'hr', 'employee', 'session');
}

function reportFile(ReportAssistantSession $session, string $name, string $bytes): Attachment
{
    $path = 'attachments/'.$session->company_id.'/test-'.uniqid().'.'.pathinfo($name, PATHINFO_EXTENSION);
    Storage::disk('local')->put($path, $bytes);

    return Attachment::create(['company_id' => $session->company_id,
        'attachable_type' => $session->getMorphClass(), 'attachable_id' => $session->id,
        'kind' => AttachmentKind::ReportEvidence, 'disk' => 'local', 'path' => $path,
        'original_name' => $name, 'mime_type' => 'application/octet-stream',
        'size_bytes' => strlen($bytes), 'uploaded_by' => $session->user_id, 'created_at' => now()]);
}

function reportAttachmentTurn(array $facts): array
{
    return ['facts' => array_replace(['what' => null, 'place_text' => null, 'location_name' => null,
        'when_text' => null, 'when_iso' => null, 'people' => [], 'no_witnesses' => false,
        'how_started' => null, 'injuries_or_damage' => null], $facts),
        'missing' => ['who', 'where', 'when'],
        'next_question' => ['text' => 'Where did it happen?', 'quick_replies' => []],
        'done' => false, 'summary' => null];
}

test('a report can start with only files and keeps ten validated private attachments', function (): void {
    $f = reportFilesFixture();
    config()->set('services.anthropic.key', 'test-key');
    Bus::fake([AskReportAssistant::class]);
    $this->actingAs($f['reporter']);
    $mail = file_get_contents(base_path('tests/fixtures/report-message.eml'));
    Livewire::test(SubmitReport::class)->set('attachments', [UploadedFile::fake()->createWithContent('incident.eml', $mail)])
        ->call('startChat')->assertHasNoErrors()->assertSee('incident.eml');
    $session = ReportAssistantSession::query()->latest('id')->firstOrFail();
    expect($session->attachments()->count())->toBe(1);

    $files = app(ReportFiles::class);
    for ($i = 1; $i < 10; $i++) {
        $files->add($session, [UploadedFile::fake()->createWithContent("mail-{$i}.eml", $mail)], $f['reporter']);
    }
    expect($files->active($session)->count())->toBe(10);
    expect(fn () => $files->add($session, [UploadedFile::fake()->createWithContent('too-many.eml', $mail)], $f['reporter']))
        ->toThrow(ValidationException::class);
    expect(fn () => app(AttachmentService::class)->store($session, AttachmentKind::ReportEvidence,
        UploadedFile::fake()->createWithContent('disguised.pdf', 'plain text'), $f['reporter']))
        ->toThrow(ValidationException::class);
});

test('video uses a 50 MB limit and other evidence uses a 10 MB limit', function (): void {
    $f = reportFilesFixture();
    $service = app(AttachmentService::class);
    $video = UploadedFile::fake()->createWithContent('clip.mp4', "\0\0\0\x18ftypisom".str_repeat('0', 100));
    expect($service->store($f['session'], AttachmentKind::ReportEvidence, $video, $f['reporter'])->original_name)
        ->toBe('clip.mp4');
    $path = tempnam(sys_get_temp_dir(), 'oversize-');
    try {
        file_put_contents($path, '%PDF-1.4');
        $handle = fopen($path, 'c+b');
        ftruncate($handle, 10 * 1024 * 1024 + 1);
        fclose($handle);
        expect(fn () => $service->store($f['session'], AttachmentKind::ReportEvidence,
            new UploadedFile($path, 'oversize.pdf', null, null, true), $f['reporter']))
            ->toThrow(ValidationException::class);
    } finally {
        unlink($path);
    }
    $largeVideo = tempnam(sys_get_temp_dir(), 'large-video-');
    try {
        file_put_contents($largeVideo, "\0\0\0\x18ftypisom");
        $handle = fopen($largeVideo, 'c+b');
        ftruncate($handle, 50 * 1024 * 1024 + 1);
        fclose($handle);
        expect(fn () => $service->store($f['session'], AttachmentKind::ReportEvidence,
            new UploadedFile($largeVideo, 'large.mp4', null, null, true), $f['reporter']))
            ->toThrow(ValidationException::class);
    } finally {
        unlink($largeVideo);
    }
});

test('saved email Outlook WebP and MOV evidence pass content checks', function (): void {
    $f = reportFilesFixture();
    $service = app(AttachmentService::class);
    $examples = [
        ['mail.eml', file_get_contents(base_path('tests/fixtures/report-message.eml'))],
        ['mail.msg', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 24)],
        ['image.webp', 'RIFF'.pack('V', 16).'WEBPVP8 '.str_repeat("\0", 12)],
        ['clip.mov', "\0\0\0\x18ftypqt  ".str_repeat("\0", 12)],
    ];
    foreach ($examples as [$name, $bytes]) {
        expect($service->store($f['session'], AttachmentKind::ReportEvidence,
            UploadedFile::fake()->createWithContent($name, $bytes), $f['reporter'])->original_name)->toBe($name);
    }
    expect(fn () => $service->store($f['session'], AttachmentKind::ReportEvidence,
        UploadedFile::fake()->createWithContent('notes.txt', 'plain text'), $f['reporter']))
        ->toThrow(ValidationException::class);
});

test('email Word and PDF text layers are read without an AI call', function (): void {
    $f = reportFilesFixture();
    $fake = new FakeClaudeClient;
    $reader = new DocumentText(app(MsgReader::class), $fake, app(ScanBlocks::class));
    $mail = reportFile($f['session'], 'message.eml', file_get_contents(base_path('tests/fixtures/report-message.eml')));
    expect($reader->read($mail)[0])->toContain('Subject: Batch 1810', 'production line 2', 'supervisor@example.test', '2026-10-08');

    $path = tempnam(sys_get_temp_dir(), 'report-docx-');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<Types/>');
    $zip->addFromString('word/document.xml', '<w:document><w:body><w:p><w:r><w:t>The conveyor stopped</w:t></w:r></w:p></w:body></w:document>');
    $zip->close();
    $word = reportFile($f['session'], 'note.docx', file_get_contents($path));
    unlink($path);
    expect($reader->read($word)[0])->toContain('The conveyor stopped');

    $pdf = reportFile($f['session'], 'note.pdf', file_get_contents(base_path('tests/fixtures/report-text.pdf')));
    expect($reader->read($pdf)[0])->toContain('The conveyor stopped on production line 2');
    expect($fake->requests)->toBe([]);
});

test('the MSG reader seam supplies fields and a video never calls AI', function (): void {
    $f = reportFilesFixture();
    // A binary Outlook fixture is impractical to author; the parser path is exercised through its reader seam.
    $messages = Mockery::mock(MsgReader::class);
    $messages->shouldReceive('read')->once()->andReturn("From: Supervisor\nDate: 2026-10-08\nSubject: Batch\nThe conveyor stopped");
    $fake = new FakeClaudeClient;
    $reader = new DocumentText($messages, $fake, app(ScanBlocks::class));
    expect($reader->read(reportFile($f['session'], 'mail.msg', "\xD0\xCF\x11\xE0"))[0])->toContain('Subject: Batch');
    expect($reader->read(reportFile($f['session'], 'clip.mp4', "\0\0\0\x18ftypisom"))[0])->toBeNull();
    expect($fake->requests)->toBe([]);
});

test('vision retains text from a message image and ignores a scene image', function (): void {
    $f = reportFilesFixture();
    $fake = new FakeClaudeClient;
    $fake->push(['mostly_text' => true, 'text' => 'The batch was rejected']);
    $fake->push(['mostly_text' => false, 'text' => '']);
    $reader = new DocumentText(app(MsgReader::class), $fake, app(ScanBlocks::class));
    $image = app(AttachmentService::class)->store($f['session'], AttachmentKind::ReportEvidence,
        UploadedFile::fake()->image('message.png'), $f['reporter']);
    expect($reader->read($image)[0])->toBe('The batch was rejected');
    expect($reader->read($image)[0])->toBeNull();
    expect(array_column($fake->requests, 'touchpoint'))->toBe(['read_report_attachment', 'read_report_attachment']);
});

test('a PDF without a text layer is transcribed as a document block', function (): void {
    $f = reportFilesFixture();
    $fake = new FakeClaudeClient;
    $fake->push(['mostly_text' => true, 'text' => 'Written note on the scan']);
    $reader = new DocumentText(app(MsgReader::class), $fake, app(ScanBlocks::class));
    $pdf = reportFile($f['session'], 'scan.pdf', file_get_contents(base_path('tests/fixtures/report-blank.pdf')));
    expect($reader->read($pdf)[0])->toBe('Written note on the scan')
        ->and($fake->requests[0]['messages'][0]['content'][0]['type'])->toBe('document');
});

test('an unreadable file stays attached while the assistant turn continues', function (): void {
    $f = reportFilesFixture();
    $file = reportFile($f['session'], 'broken.eml', 'broken content');
    $f['session']->update(['status' => 'pending', 'request_token' => (string) Str::uuid()]);
    $documents = Mockery::mock(DocumentText::class);
    $documents->shouldReceive('read')->once()->andThrow(new RuntimeException('Cannot parse'));
    $fake = new FakeClaudeClient;
    $fake->push(reportAttachmentTurn(['what' => 'A batch error happened']));
    (new AskReportAssistant($f['session']->id, $f['company']->id, $f['session']->request_token))
        ->handle($fake, app(ReportAssistant::class), app(ReportFiles::class), $documents);
    expect($file->textReading()->firstOrFail()->reading_status)->toBe('unreadable')
        ->and($f['session']->refresh()->status)->toBe('ready')
        ->and($f['session']->attachments()->count())->toBe(1);
});

test('file text is masked in the turn and supports facts while invented facts are dropped', function (): void {
    $f = reportFilesFixture();
    $file = reportFile($f['session'], 'mail.eml', file_get_contents(base_path('tests/fixtures/report-message.eml')));
    $file->textReading()->create(['company_id' => $f['company']->id, 'reading_status' => 'read',
        'extracted_text' => 'Ali Hayder reported a flavor error in batch 1810 on production line 2. Phone 07701234567. Salary 900000 IQD. ID 123456789.',
        'created_at' => now()]);
    $assistant = app(ReportAssistant::class);
    $f['session']->update(['facts' => ['branch_id' => $f['branch']->id, 'show_found_facts' => true]]);
    $builder = app(PayloadBuilder::class);
    $context = $assistant->context($f['session'], $builder);
    $payload = json_encode($context['messages'], JSON_UNESCAPED_UNICODE);
    expect($payload)->not->toContain('Ali Hayder', '07701234567', '900000', '123456789');
    $assistant->acceptTurn($f['session'], reportAttachmentTurn([
        'what' => 'flavor error in batch 1810', 'place_text' => 'moon base',
    ]), $context, $builder);
    expect($f['session']->refresh()->facts['description'])->toBe('flavor error in batch 1810')
        ->and($f['session']->facts['place'] ?? null)->toBeNull()
        ->and(collect($f['session']->transcript)->last()['text'])->toContain('What happened: flavor error in batch 1810');
});

test('session evidence is copied to the sent report and is invisible across tenants', function (): void {
    $f = reportFilesFixture();
    $file = reportFile($f['session'], 'note.pdf', file_get_contents(base_path('tests/fixtures/report-text.pdf')));
    $report = app(ReportService::class)->submit(['branch_id' => $f['branch']->id,
        'employee_id' => $f['employee']->id, 'description' => 'A batch error happened',
        'violation_type' => 'Other', 'place_freetext' => 'Line 2',
        'occurred_at_text' => 'I do not know', 'witnesses_unknown' => true,
        'assistant_session_id' => $f['session']->id], $f['reporter']);
    $copy = $report->attachments()->firstOrFail();
    expect($copy->original_name)->toBe('note.pdf')
        ->and($copy->path)->toBe($file->path);
    $this->actingAs($f['reporter'])->get('/attachments/'.$copy->id)->assertNotFound();
    $this->actingAs($f['hr'])->get('/attachments/'.$copy->id)->assertOk();
    $other = Company::factory()->create();
    app(TenantContext::class)->setCompany($other);
    $otherHr = User::factory()->create(['company_id' => $other->id, 'primary_role' => 'hr_staff']);
    $this->actingAs($otherHr)->get('/attachments/'.$copy->id)->assertNotFound();
});

test('removed session files are excluded from report evidence', function (): void {
    $f = reportFilesFixture();
    $kept = reportFile($f['session'], 'kept.eml', file_get_contents(base_path('tests/fixtures/report-message.eml')));
    $removed = reportFile($f['session'], 'removed.eml', file_get_contents(base_path('tests/fixtures/report-message.eml')));
    app(ReportFiles::class)->remove($f['session'], $removed->id, $f['reporter']);

    $report = app(ReportService::class)->submit(['branch_id' => $f['branch']->id,
        'employee_id' => $f['employee']->id, 'description' => 'A batch error happened',
        'violation_type' => 'Other', 'place_freetext' => 'Line 2',
        'occurred_at_text' => 'I do not know', 'witnesses_unknown' => true,
        'assistant_session_id' => $f['session']->id], $f['reporter']);

    expect($report->attachments()->pluck('original_name')->all())->toBe([$kept->original_name]);
});

test('HR file suggestions populate editable fields but leave people unselected', function (): void {
    $f = reportFilesFixture();
    $intake = CaseFileIntake::create(['company_id' => $f['company']->id, 'user_id' => $f['hr']->id]);
    $file = reportFile($f['session'], 'message.eml', file_get_contents(base_path('tests/fixtures/report-message.eml')));
    $file->textReading()->create(['company_id' => $f['company']->id, 'reading_status' => 'read',
        'extracted_text' => 'Ali Hayder reported a flavor error in batch 1810 on production line 2.',
        'created_at' => now()]);
    $intake->attachments()->create($file->only(['company_id', 'kind', 'disk', 'path', 'original_name', 'mime_type', 'size_bytes', 'uploaded_by'])
        + ['created_at' => now()]);
    $fake = new FakeClaudeClient;
    $fake->push(['description' => 'flavor error in batch 1810', 'place' => 'production line 2',
        'when_text' => null, 'when_iso' => null, 'violation_type_id' => null, 'names' => ['⟦employee_1⟧']]);
    app()->instance(ClaudeClient::class, $fake);
    $values = app(CaseFileSuggestions::class)->analyse($intake, $f['hr'], $f['branch']->id);
    expect($values['description'])->toBe('flavor error in batch 1810')
        ->and($values['place'])->toBe('production line 2')
        ->and($values['names'])->toBe(['Ali Hayder']);
    $otherCompany = Company::factory()->create();
    app(TenantContext::class)->setCompany($otherCompany);
    $otherHr = User::factory()->create(['company_id' => $otherCompany->id, 'primary_role' => 'hr_staff']);
    expect(fn () => app(CaseFileSuggestions::class)->analyse($intake, $otherHr, null))
        ->toThrow(HttpException::class);
    app(TenantContext::class)->setCompany($f['company']);
    $fake->push(['description' => 'flavor error in batch 1810', 'place' => 'production line 2',
        'when_text' => null, 'when_iso' => null, 'violation_type_id' => null, 'names' => ['⟦employee_1⟧']]);
    $this->actingAs($f['hr']);
    $form = Livewire::test(CreateCase::class)->set('branchId', (string) $f['branch']->id)
        ->set('caseFiles', [UploadedFile::fake()->createWithContent('mail.eml', file_get_contents(base_path('tests/fixtures/report-message.eml')))])
        ->assertSet('description', 'flavor error in batch 1810')
        ->assertSet('place', 'production line 2')
        ->assertSet('suggestedNames', ['Ali Hayder'])
        ->assertSet('people.employees', []);
    $form->call('addPerson', 'employees', $f['employee']->id)
        ->set('violationTime', now()->subHour()->format('Y-m-d\TH:i'))
        ->set('violationType', 'Quality incident')->call('save')->assertHasNoErrors();
    $case = DisciplinaryCase::query()->latest('id')->firstOrFail();
    $saved = $case->attachments()->firstOrFail();
    expect($saved->original_name)->toBe('mail.eml')
        ->and($saved->textReading?->reading_status)->toBe('read');
});

test('a file reading or an HR file intake cannot point at another company', function (): void {
    $f = reportFilesFixture();
    $file = reportFile($f['session'], 'note.pdf', '%PDF-1.4');
    $other = Company::factory()->create();
    // The composite tenant keys refuse a row whose company differs from the attachment's or the user's.
    expect(fn () => DB::table('attachment_texts')->insert(['company_id' => $other->id, 'attachment_id' => $file->id,
        'reading_status' => 'read', 'created_at' => now()]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('case_file_intakes')->insert(['company_id' => $other->id, 'user_id' => $f['hr']->id,
            'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
});

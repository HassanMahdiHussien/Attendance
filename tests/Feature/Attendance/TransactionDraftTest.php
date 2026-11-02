<?php

use App\Enums\UserRole;
use App\Filament\Admin\Pages\Attendance\UploadAttendance;
use App\Jobs\Attendance\AnalyseTransactionDraftJob;
use App\Jobs\Attendance\ImportTransactionsJob;
use App\Models\Attendance\ImportBatch;
use App\Models\Attendance\ImportDraft;
use App\Models\Attendance\ImportMapping;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Services\Attendance\ImportDraftPruner;
use App\Services\Attendance\TransactionFile;
use App\Services\Attendance\TransactionImport;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function transactionDraftFile(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('Transaction_20261020084331.csv', "Transaction\nEmployee ID,First Name,Department,Date,Time,Punch State,Serial Number,Device Name,Data Sources\n12345678,Fake Employee,Operations,2026-10-20,08:00,Check In,SER-1,Hilla_In_Face,Face\n12345678,Fake Employee,Operations,2026-10-20,08:00,Check In,SER-1,Hilla_In_Face,Face\n99999999,Fake Unknown,Operations,2026-10-20,08:12,Check In,SER-2,Hilla_In_Face,Face\n44,Fake Visitor,Guest,invalid,08:12,Check Out,SER-2,Hilla_Out_Face,Face\n");
}

beforeEach(function (): void {
    Queue::fake();
    Storage::fake('local');
    $this->company = Company::factory()->create();
    app(TenantContext::class)->setCompany($this->company);
    $this->branch = Branch::factory()->create(['company_id' => $this->company->id, 'name' => 'Hilla']);
    $this->actor = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    actingInAdminTenant($this, $this->actor, $this->company);
});

afterEach(function (): void {
    Filament::setTenant(null);
    app(TenantContext::class)->clear();
});

test('analysis and finalisation reproduce preview for CSV', function (): void {
    $file = transactionDraftFile();
    $reader = app(TransactionFile::class);
    $mapping = $reader->suggest($reader->inspect($file)['headers']);
    $import = app(TransactionImport::class);
    $coverage = ['branch_ids' => [$this->branch->id]];
    $result = $import->finalise($this->company, $import->analyse($this->company, $file, $mapping), $coverage);
    expect($result)->toEqual($import->preview($this->company, $file, $mapping, $coverage))
        ->and($result['counts']['rows'])->toBe(4)
        ->and($result['counts']['duplicate_in_file'])->toBe(1)
        ->and($result['counts']['errors'])->toBe(1)
        ->and($result['per_device']['SER-1'])->toBe(1)
        ->and($result['per_day']['2026-10-20'])->toBe(2)
        ->and($result['suggested_coverage']['branch_ids'])->toBe([$this->branch->id])
        ->and($result['already_imported'])->toBeNull()
        ->and($result['token'])->toBeString();
});

test('continue stores a draft without reading rows and saved mappings dispatch analysis', function (): void {
    $file = transactionDraftFile();
    $reader = app(TransactionFile::class);
    $headers = $reader->inspect($file)['headers'];
    ImportMapping::create(['company_id' => $this->company->id, 'header_signature' => hash('sha256', json_encode($headers, JSON_THROW_ON_ERROR)), 'headers' => $headers, 'mapping' => $reader->suggest($headers), 'updated_by' => $this->actor->id]);
    $spy = Mockery::mock($reader)->makePartial();
    $spy->shouldNotReceive('rows');
    app()->instance(TransactionFile::class, $spy);
    Livewire::test(UploadAttendance::class)->set('sourceFile', $file)->call('prepare')->assertSet('step', 'coverage')->assertSet('sourceFile', null);
    $draft = ImportDraft::query()->sole();
    Storage::disk('local')->assertExists($draft->file_path);
    Queue::assertPushed(AnalyseTransactionDraftJob::class, 1);
});

test('mapping confirmation dispatches and a mapping change invalidates the old analysis', function (): void {
    $page = Livewire::test(UploadAttendance::class)->set('sourceFile', transactionDraftFile())->call('prepare');
    Queue::assertNotPushed(AnalyseTransactionDraftJob::class);
    $page->call('setCoverageStep');
    $draft = ImportDraft::query()->sole();
    Queue::assertPushed(AnalyseTransactionDraftJob::class, 1);
    $page->call('changeMapping')->set('mapping.0', '')->set('mapping.0', 'person_code')->call('setCoverageStep');
    expect($draft->fresh()->revision)->toBeGreaterThan($draft->revision);
    Queue::assertPushed(AnalyseTransactionDraftJob::class, 2);
    Livewire::test(UploadAttendance::class)->assertSet('draftId', $draft->id)->assertSet('step', 'coverage');
});

test('analysis job records result and progress and rejects a broken stored file', function (): void {
    $page = Livewire::test(UploadAttendance::class)->set('sourceFile', transactionDraftFile())->call('prepare')->call('setCoverageStep');
    $draft = ImportDraft::query()->sole();
    $page->call('preview')->assertHasErrors(['coverage']);
    app()->call([new AnalyseTransactionDraftJob($draft->id, $draft->revision), 'handle']);
    expect($draft->fresh()->status)->toBe('ready')->and($draft->fresh()->rows_processed)->toBe(4)
        ->and($draft->fresh()->analysis['counts']['rows'])->toBe(4);
    $page->call('$refresh')->call('preview')->assertHasNoErrors()->assertSet('step', 'preview');

    Livewire::test(UploadAttendance::class)->set('sourceFile', transactionDraftFile())->call('prepare')->call('setCoverageStep');
    $broken = ImportDraft::query()->latest('id')->first();
    Storage::disk('local')->put($broken->file_path, 'changed');
    app()->call([new AnalyseTransactionDraftJob($broken->id, $broken->revision), 'handle']);
    expect($broken->fresh()->status)->toBe('failed')->and($broken->fresh()->error_code)->toBe('stored_file_unavailable');
});

test('ready draft commits once with the stored original and no row scan', function (): void {
    $page = Livewire::test(UploadAttendance::class)->set('sourceFile', transactionDraftFile())->call('prepare')->call('setCoverageStep');
    $draft = ImportDraft::query()->sole();
    app()->call([new AnalyseTransactionDraftJob($draft->id, $draft->revision), 'handle']);
    $page->call('$refresh')->call('preview');
    $spy = Mockery::mock(app(TransactionFile::class))->makePartial();
    $spy->shouldNotReceive('rows');
    app()->instance(TransactionFile::class, $spy);
    $page->call('confirmImport')->assertSet('step', 'progress');
    $batch = ImportBatch::query()->sole();
    expect($batch->file_path)->toBe($draft->file_path)
        ->and($batch->summary['preview_counts']['rows'])->toBe(4)
        ->and($draft->fresh()->batch_id)->toBe($batch->id)
        ->and(DB::table('attendance_coverage_intervals')->where('batch_id', $batch->id)->count())->toBe(1)
        ->and(ImportMapping::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'attendance.import.committed')->count())->toBe(1);
    Queue::assertPushed(ImportTransactionsJob::class, 1);
    $import = app(TransactionImport::class);
    expect(fn () => $import->commitDraft($this->company, $draft->fresh(), $this->actor, $batch->coverage_branch_ids === [] ? [] : [
        'from' => $batch->coverage_from->format('Y-m-d H:i:s'), 'to' => $batch->coverage_to->format('Y-m-d H:i:s'), 'branch_ids' => $batch->coverage_branch_ids,
    ], 'bad'))->toThrow(ValidationException::class);
});

test('tampered ready draft cannot commit', function (): void {
    $page = Livewire::test(UploadAttendance::class)->set('sourceFile', transactionDraftFile())->call('prepare')->call('setCoverageStep');
    $draft = ImportDraft::query()->sole();
    app()->call([new AnalyseTransactionDraftJob($draft->id, $draft->revision), 'handle']);
    $page->call('$refresh')->call('preview');
    Storage::disk('local')->put($draft->file_path, 'changed');
    $page->call('confirmImport')->assertHasErrors(['sourceFile']);
    expect(ImportBatch::query()->count())->toBe(0);
});

test('a second draft of the same file cannot create a second batch', function (): void {
    $page = Livewire::test(UploadAttendance::class)->set('sourceFile', transactionDraftFile())->call('prepare')->call('setCoverageStep');
    $first = ImportDraft::query()->sole();
    app()->call([new AnalyseTransactionDraftJob($first->id, $first->revision), 'handle']);
    $page->call('$refresh')->call('preview')->call('confirmImport');
    $page = Livewire::test(UploadAttendance::class)->set('sourceFile', transactionDraftFile())->call('prepare');
    $second = ImportDraft::query()->latest('id')->first();
    app()->call([new AnalyseTransactionDraftJob($second->id, $second->revision), 'handle']);
    $page->call('$refresh')->call('preview');
    expect($page->instance()->previewData()['already_imported'])->toBe(ImportBatch::query()->sole()->id)
        ->and(ImportBatch::query()->count())->toBe(1);
    $page->call('confirmImport')->assertForbidden();
    expect(ImportBatch::query()->count())->toBe(1);
});

test('drafts are tenant scoped and old abandoned files are pruned without deleting a batch original', function (): void {
    Livewire::test(UploadAttendance::class)->set('sourceFile', transactionDraftFile())->call('prepare');
    $draft = ImportDraft::query()->sole();
    $draft->update(['created_at' => now()->subDays(3)]);
    $other = Company::factory()->create();
    app(TenantContext::class)->runForCompany($other, function () use ($draft): void {
        expect(ImportDraft::query()->find($draft->id))->toBeNull();
    });
    Storage::disk('local')->assertExists($draft->file_path);
    expect(app(ImportDraftPruner::class)->prune())->toBe(1);
    Storage::disk('local')->assertMissing($draft->file_path);
});

test('pruning a committed draft preserves the batch original', function (): void {
    Livewire::test(UploadAttendance::class)->set('sourceFile', transactionDraftFile())->call('prepare');
    $draft = ImportDraft::query()->sole();
    $batch = ImportBatch::factory()->create(['company_id' => $this->company->id, 'file_path' => $draft->file_path]);
    $draft->update(['batch_id' => $batch->id, 'status' => 'committed', 'created_at' => now()->subDays(3)]);
    expect(app(ImportDraftPruner::class)->prune())->toBe(1);
    Storage::disk('local')->assertExists($batch->file_path);
});

test('another company draft cannot be resumed on the page', function (): void {
    $other = Company::factory()->create();
    app(TenantContext::class)->runForCompany($other, function () use ($other): void {
        ImportDraft::create([
            'company_id' => $other->id, 'uploaded_by' => User::factory()->create(['company_id' => $other->id, 'primary_role' => UserRole::HrStaff])->id,
            'file_path' => 'attendance-imports/'.$other->id.'/drafts/fake.csv', 'file_name' => 'fake.csv',
            'file_sha256' => str_repeat('a', 64), 'headers' => [], 'header_row' => 1, 'status' => 'pending',
        ]);
    });
    Livewire::test(UploadAttendance::class)->assertSet('draftId', null)->assertSet('step', 'upload');
});

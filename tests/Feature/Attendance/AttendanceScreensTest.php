<?php

use App\Enums\UserRole;
use App\Filament\Admin\Pages\Attendance\AttendanceBatches;
use App\Filament\Admin\Pages\Attendance\AttendanceDevices;
use App\Filament\Admin\Pages\Attendance\AttendanceIssues;
use App\Filament\Admin\Pages\Attendance\AttendancePunches;
use App\Filament\Admin\Pages\Attendance\UploadAttendance;
use App\Jobs\Attendance\ImportTransactionsJob;
use App\Jobs\Attendance\ProcessAttendanceJob;
use App\Models\Attendance\DataIssue;
use App\Models\Attendance\Device;
use App\Models\Attendance\ImportBatch;
use App\Models\Attendance\ImportBatchEvent;
use App\Models\Attendance\Transaction;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function attendanceUiFile(): UploadedFile
{
    $content = "Transaction\nEmployee ID,First Name,Department,Date,Time,Punch State,Serial Number,Device Name,Data Sources\n12345678,Fake Employee,Operations,2026-10-20,08:00,Check In,SER-1,Hilla_In_Face,Face\n";

    return UploadedFile::fake()->createWithContent('Transaction_20261020084331.csv', $content);
}

beforeEach(function (): void {
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

test('HR maps, confirms coverage, previews and commits a CSV upload', function (): void {
    config(['cache.default' => 'database', 'queue.default' => 'sync']);
    Livewire::test(UploadAttendance::class)->set('sourceFile', attendanceUiFile())
        ->call('prepare')->assertSet('step', 'mapping')
        ->call('setCoverageStep')->assertSet('step', 'coverage')
        ->assertSet('coverage.branch_ids', [$this->branch->id])
        ->call('preview')->assertSet('step', 'preview')
        ->call('commit')->assertSet('step', 'progress');
    $batch = ImportBatch::query()->sole();
    if (ImportBatchEvent::query()->where('batch_id', $batch->id)->latest('sequence')->value('state') === 'queued') {
        app()->call([new ImportTransactionsJob($batch->id), 'handle']);
    }
    expect(ImportBatchEvent::query()->where('batch_id', $batch->id)->latest('sequence')->value('state'))->toBe('completed');
    Livewire::test(UploadAttendance::class)->assertSet('step', 'progress')->assertSee($batch->file_name);
    Livewire::test(AttendanceBatches::class)->assertSee($batch->file_name);
});

test('saved header mapping skips the mapping screen', function (): void {
    Queue::fake();
    $file = attendanceUiFile();
    Livewire::test(UploadAttendance::class)->set('sourceFile', $file)->call('prepare')->call('setCoverageStep')->call('preview')->call('commit');
    Livewire::test(UploadAttendance::class)->set('sourceFile', attendanceUiFile())->call('prepare')->assertSet('step', 'coverage');
});

test('pages hide another company and actions remain tenant scoped', function (): void {
    Filament::setTenant(null);
    $other = Company::factory()->create();
    $foreign = app(TenantContext::class)->runForCompany($other, function () use ($other): array {
        $branch = Branch::factory()->create(['company_id' => $other->id]);

        return [
            'device' => Device::factory()->create(['company_id' => $other->id, 'suggested_branch_id' => $branch->id]),
            'issue' => DataIssue::factory()->create(['company_id' => $other->id]),
            'batch' => ImportBatch::factory()->create(['company_id' => $other->id]),
        ];
    });
    actingInAdminTenant($this, $this->actor, $this->company);
    foreach ([UploadAttendance::class, AttendanceBatches::class, AttendanceDevices::class, AttendanceIssues::class, AttendancePunches::class] as $page) {
        Livewire::test($page)->assertSee($page::getNavigationLabel());
    }
    Livewire::test(AttendanceDevices::class)->call('selectDevice', $foreign['device']->id, 'approve')->assertNotFound();
    Livewire::test(AttendanceIssues::class)->call('close', $foreign['issue']->id, 'resolve')->assertNotFound();
    Livewire::test(AttendanceBatches::class)->call('retry', $foreign['batch']->id)->assertNotFound();
    $this->get(route('attendance.import.file', ['company' => $other->id, 'attendanceBatch' => $foreign['batch']->id]))->assertNotFound();
});

test('every attendance page rejects an HR user of another tenant and admits a platform admin', function (): void {
    $other = Company::factory()->create();
    $platform = User::factory()->create(['company_id' => null, 'primary_role' => UserRole::PlatformAdmin]);
    foreach ([UploadAttendance::class, AttendanceBatches::class, AttendanceDevices::class, AttendanceIssues::class, AttendancePunches::class] as $page) {
        Filament::setTenant($other);
        Livewire::test($page)->assertForbidden();
        actingInAdminTenant($this, $platform, $other);
        Livewire::test($page)->assertSee($page::getNavigationLabel());
        actingInAdminTenant($this, $this->actor, $this->company);
    }
});

test('HR closes a data issue with a note through the issue page', function (): void {
    $issue = DataIssue::factory()->create();
    Livewire::test(AttendanceIssues::class)->set('notes.'.$issue->id, 'Confirmed visitor.')
        ->call('close', $issue->id, 'dismiss');
    expect($issue->fresh()->status)->toBe('dismissed')->and($issue->fresh()->note)->toBe('Confirmed visitor.');
});

test('original download is audited and restricted to the company', function (): void {
    $other = Company::factory()->create();
    $otherActor = User::factory()->create(['company_id' => $other->id, 'primary_role' => UserRole::HrStaff]);
    $platform = User::factory()->create(['company_id' => null, 'primary_role' => UserRole::PlatformAdmin]);
    $batch = ImportBatch::factory()->create(['company_id' => $this->company->id, 'uploaded_by' => $this->actor->id, 'file_path' => 'attendance-imports/original.csv']);
    Storage::disk('local')->put($batch->file_path, 'private source');
    $url = route('attendance.import.file', ['company' => $this->company->id, 'attendanceBatch' => $batch->id]);
    $this->get($url)->assertOk();
    expect(AuditLog::query()->where('action', 'attendance.import.file.downloaded')->count())->toBe(1);
    $this->actingAs($otherActor);
    $this->get($url)->assertNotFound();
    $this->actingAs($platform);
    $this->get($url)->assertOk();
});

test('failed batches can be retried and pending devices can be approved through pages', function (): void {
    Queue::fake();
    $batch = ImportBatch::factory()->create(['uploaded_by' => $this->actor->id]);
    ImportBatchEvent::factory()->create(['batch_id' => $batch->id, 'state' => 'failed']);
    Livewire::test(AttendanceBatches::class)->call('retry', $batch->id)->assertSee($batch->file_name);
    Queue::assertPushed(ImportTransactionsJob::class, fn (ImportTransactionsJob $job): bool => $job->batchId === $batch->id);
    $device = Device::factory()->create(['status' => 'pending', 'suggested_branch_id' => $this->branch->id]);
    Transaction::factory()->create(['device_id' => $device->id, 'serial_number' => $device->serial_number, 'first_batch_id' => $batch->id]);
    Livewire::test(AttendanceDevices::class)->call('selectDevice', $device->id, 'approve')->call('save')->assertSee($device->serial_number);
    expect($device->fresh()->status)->toBe('approved');
    Queue::assertPushed(ProcessAttendanceJob::class);
});

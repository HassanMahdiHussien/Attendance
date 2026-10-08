<?php

use App\Documents\PdfGenerator;
use App\Enums\CaseStatus;
use App\Exceptions\TenantMismatch;
use App\Jobs\GenerateReportExport;
use App\Models\CaseEvent;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\HrPartner;
use App\Models\Law;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Dashboards\CaseMetrics;
use App\Services\Dashboards\DashboardFilters;
use App\Services\Statistics\ReportBuilder;
use App\Services\Statistics\ReportExporter;
use App\Services\UserAccountService;
use App\Statistics\ExcelReport;
use App\Statistics\PdfReport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Component\HttpKernel\Exception\HttpException;

test('past quarter open and closed counts describe that quarter', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $viewer = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create();
    DisciplinaryCase::factory()->create(['law_version_id' => $law->id, 'status' => CaseStatus::Open,
        'created_at' => '2025-01-15 10:00:00', 'violation_time' => '2025-01-15 10:00:00']);
    DisciplinaryCase::factory()->create(['law_version_id' => $law->id, 'status' => CaseStatus::ArchivedFinal,
        'created_at' => '2025-01-20 10:00:00', 'closed_at' => '2025-02-10 10:00:00',
        'violation_time' => '2025-01-20 10:00:00']);
    DisciplinaryCase::factory()->create(['law_version_id' => $law->id, 'status' => CaseStatus::ArchivedFinal,
        'created_at' => '2024-11-20 10:00:00', 'closed_at' => '2024-12-20 10:00:00',
        'violation_time' => '2024-11-20 10:00:00']);
    $report = app(ReportBuilder::class)->build($viewer, 'periodic',
        ['period' => 'quarter', 'year' => 2025, 'quarter' => 1]);
    expect($report['summary']['Open at period end'])->toBe(1)
        ->and($report['summary']['Closed in period'])->toBe(1);
});

test('compliance uses due-day boundary and recommendation-only closing date', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $viewer = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create();
    $case = DisciplinaryCase::factory()->create(['law_version_id' => $law->id,
        'status' => CaseStatus::ArchivedFinal, 'outcome' => 'recommendation_only',
        'violation_time' => '2025-01-01 09:00:00', 'defense_letter_issued_at' => '2025-01-01 09:00:00',
        'closed_at' => '2025-01-16 23:59:00']);
    $report = app(ReportBuilder::class)->build($viewer, 'compliance',
        ['period' => 'month', 'year' => 2025, 'month' => 1]);
    expect($report['summary']['Within deadline'])->toBe(1)
        ->and($report['sections'][0]['rows'][0][4])->not->toBe('—');
    $case->forceFill(['closed_at' => '2025-01-17 00:01:00'])->save();
    $late = app(ReportBuilder::class)->build($viewer, 'compliance',
        ['period' => 'month', 'year' => 2025, 'month' => 1]);
    expect($late['summary']['Completed late'])->toBe(1);
    CaseEvent::factory()->create(['case_id' => $case->id, 'event_type' => 'response_handoff_recorded',
        'created_at' => '2025-01-04 23:59:00']);
    $onDay = app(ReportBuilder::class)->build($viewer, 'compliance',
        ['period' => 'month', 'year' => 2025, 'month' => 1]);
    expect($onDay['sections'][0]['rows'][0][7])->toBe('Within deadline');
    $other = DisciplinaryCase::factory()->create(['law_version_id' => $law->id,
        'status' => CaseStatus::Open, 'violation_time' => '2025-01-02 09:00:00',
        'defense_letter_issued_at' => '2025-01-01 09:00:00']);
    CaseEvent::factory()->create(['case_id' => $other->id, 'event_type' => 'response_handoff_recorded',
        'created_at' => '2025-01-05 00:01:00']);
    $afterDay = app(ReportBuilder::class)->build($viewer, 'compliance',
        ['period' => 'month', 'year' => 2025, 'month' => 1]);
    expect($afterDay['sections'][0]['rows'][1][7])->toBe('Deadline missed');
});

test('live period report outcome figures use dashboard metric definitions', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $viewer = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    DisciplinaryCase::factory()->create(['law_version_id' => Law::factory()->create()->id,
        'status' => CaseStatus::Open, 'violation_time' => now()->subDay()]);
    $params = ['period' => 'custom', 'from' => now()->subDays(7)->toDateString(), 'to' => now()->toDateString()];
    $report = app(ReportBuilder::class)->build($viewer, 'periodic', $params);
    $metrics = app(CaseMetrics::class)->counts($viewer, DashboardFilters::fromInput($viewer, $params));
    expect($report['summary']['Open at period end'])->toBe($metrics['open'])
        ->and($report['summary']['Warning'])->toBe($metrics['warning'])
        ->and($report['summary']['Termination'])->toBe($metrics['termination']);
});

test('export job publishes once, downloads for owner, and expires only ready rows', function (): void {
    Queue::fake();
    Storage::fake('local');
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $viewer = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $other = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $params = ['period' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31'];
    $export = app(ReportExporter::class)->request($viewer, 'periodic', $params, 'xlsx');
    expect($export->status)->toBe('queued');
    Queue::assertPushed(GenerateReportExport::class);
    $job = new GenerateReportExport($export->id, $company->id);
    $job->handle(app(ReportBuilder::class), app(PdfReport::class), app(ExcelReport::class));
    $ready = $export->fresh();
    expect($ready->status)->toBe('ready')->and($ready->file_sha256)->toHaveLength(64);
    $reader = new Reader;
    $reader->open(Storage::disk('local')->path($ready->file_path));
    expect(iterator_count($reader->getSheetIterator()))->toBeGreaterThan(1);
    $reader->close();
    $job->handle(app(ReportBuilder::class), app(PdfReport::class), app(ExcelReport::class));
    $this->actingAs($other)->get('/statistics/exports/'.$ready->id)->assertNotFound();
    $otherCompany = Company::factory()->create();
    $outsider = User::factory()->create(['company_id' => $otherCompany->id, 'primary_role' => 'company_admin']);
    $this->actingAs($outsider)->get('/statistics/exports/'.$ready->id)->assertNotFound();
    $this->actingAs($viewer)->get('/statistics/exports/'.$ready->id)->assertOk();
    $platform = User::factory()->create(['company_id' => null, 'primary_role' => 'platform_admin']);
    $this->actingAs($platform)->get('/statistics/exports/'.$ready->id)->assertOk();
    $original = Storage::disk('local')->get($ready->file_path);
    Storage::disk('local')->put($ready->file_path, 'tampered');
    $this->get('/statistics/exports/'.$ready->id)->assertNotFound();
    Storage::disk('local')->put($ready->file_path, $original);
    expect(DB::table('audit_logs')->where('action', 'report_export.requested')->exists())->toBeTrue()
        ->and(DB::table('audit_logs')->where('action', 'report_export.downloaded')->exists())->toBeTrue();
    $queued = app(ReportExporter::class)->request($viewer, 'periodic', $params, 'pdf');
    app(TenantContext::class)->runForCompany($company, fn () => $ready->forceFill(['expires_at' => now()->subSecond()])->save());
    $this->actingAs($viewer)->get('/statistics/exports/'.$ready->id)->assertNotFound();
    Artisan::call('report-exports:prune');
    expect(app(TenantContext::class)->runForCompany($company, fn () => $ready->fresh()->status))->toBe('expired')
        ->and(app(TenantContext::class)->runForCompany($company, fn () => $queued->fresh()->status))->toBe('queued');
});

test('failed exports are marked failed and all-company runs require Platform Admin', function (): void {
    Queue::fake();
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $params = ['period' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31'];
    $export = app(ReportExporter::class)->request($admin, 'periodic', $params, 'pdf');
    (new GenerateReportExport($export->id, $company->id))->failed(new RuntimeException('render failed'));
    expect($export->fresh()->status)->toBe('failed');
    $platform = User::factory()->create(['company_id' => null, 'primary_role' => 'platform_admin']);
    $all = app(ReportExporter::class)->request($platform, 'hotspots', $params, 'xlsx');
    expect($all->company_id)->toBeNull();
    $this->expectException(TenantMismatch::class);
    app(TenantContext::class)->runAcrossAllCompanies(fn () => ReportExport::create([
        'company_id' => null, 'requested_by' => $admin->id, 'report_type' => 'hotspots',
        'parameters' => $params, 'format' => 'xlsx', 'status' => 'queued',
    ]));
});

test('Platform Admin all-company periodic figures include both companies', function (): void {
    $law = Law::factory()->create();
    foreach ([Company::factory()->create(), Company::factory()->create()] as $company) {
        app(TenantContext::class)->runForCompany($company, fn () => DisciplinaryCase::factory()->create([
            'law_version_id' => $law->id, 'status' => CaseStatus::Open,
            'created_at' => '2025-01-15 09:00:00', 'violation_time' => '2025-01-15 09:00:00',
        ]));
    }
    $platform = User::factory()->create(['company_id' => null, 'primary_role' => 'platform_admin']);
    $report = app(ReportBuilder::class)->build($platform, 'periodic',
        ['period' => 'quarter', 'year' => 2025, 'quarter' => 1]);
    expect($report['company'])->toBeNull()->and($report['summary']['Open at period end'])->toBe(2);
});

test('a stats-only partner sees aggregate labels and cannot request person reports', function (): void {
    Queue::fake();
    Storage::fake('local');
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $case = DisciplinaryCase::factory()->create(['law_version_id' => Law::factory()->create()->id,
        'status' => CaseStatus::Open, 'opened_by_user_id' => $admin->id]);
    $partner = HrPartner::factory()->create();
    DB::table('company_hr_partner')->insert(['company_id' => $company->id, 'hr_partner_id' => $partner->id,
        'full_case_access' => false]);
    $viewer = User::factory()->create(['company_id' => null, 'primary_role' => 'hr_partner_staff',
        'hr_partner_id' => $partner->id]);
    $params = ['company_id' => $company->id, 'period' => 'custom', 'from' => now()->subDay()->toDateString(),
        'to' => now()->toDateString()];
    $report = app(ReportBuilder::class)->build($viewer, 'hotspots', $params);
    expect(json_encode($report))->not->toContain($case->employee->name);
    $export = app(ReportExporter::class)->request($viewer, 'hotspots', $params, 'xlsx');
    (new GenerateReportExport($export->id, $company->id))->handle(app(ReportBuilder::class),
        app(PdfReport::class), app(ExcelReport::class));
    $path = app(TenantContext::class)->runForCompany($company, fn () => $export->fresh()->file_path);
    $reader = new Reader;
    $reader->open(Storage::disk('local')->path($path));
    $values = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCells() as $cell) {
                $values[] = (string) $cell->getValue();
            }
        }
    }
    $reader->close();
    expect(implode(' ', $values))->not->toContain($case->employee->name);
    $this->expectException(HttpException::class);
    app(ReportBuilder::class)->build($viewer, 'compliance', $params);
});

test('partner grant removal revokes a ready download and deletes its file', function (): void {
    Queue::fake();
    Storage::fake('local');
    $company = Company::factory()->create();
    $partner = HrPartner::factory()->create();
    DB::table('company_hr_partner')->insert(['company_id' => $company->id, 'hr_partner_id' => $partner->id,
        'full_case_access' => false]);
    $viewer = User::factory()->create(['company_id' => null, 'primary_role' => 'hr_partner_staff',
        'hr_partner_id' => $partner->id]);
    $export = app(ReportExporter::class)->request($viewer, 'periodic', ['company_id' => $company->id,
        'period' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31'], 'pdf');
    $path = "report-exports/{$export->id}.pdf";
    Storage::disk('local')->put($path, '%PDF'.str_repeat('x', 120));
    app(TenantContext::class)->runForCompany($company, fn () => $export->forceFill([
        'status' => 'ready', 'file_path' => $path,
        'file_sha256' => hash('sha256', Storage::disk('local')->get($path)),
        'ready_at' => now(), 'expires_at' => now()->addDays(7),
    ])->save());
    DB::table('company_hr_partner')->where('company_id', $company->id)->delete();
    $this->actingAs($viewer)->get('/statistics/exports/'.$export->id)->assertNotFound();
    expect(app(TenantContext::class)->runForCompany($company, fn () => $export->fresh()->status))->toBe('revoked')
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

test('deactivating the owner revokes the ready file', function (): void {
    Queue::fake();
    Storage::fake('local');
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $owner = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $export = app(ReportExporter::class)->request($owner, 'periodic',
        ['period' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31'], 'pdf');
    $path = "report-exports/{$export->id}.pdf";
    Storage::disk('local')->put($path, '%PDF'.str_repeat('x', 120));
    $export->forceFill(['status' => 'ready', 'file_path' => $path,
        'file_sha256' => hash('sha256', Storage::disk('local')->get($path)),
        'ready_at' => now(), 'expires_at' => now()->addDays(7)])->save();
    // Deactivation through the account service revokes the user's ready exports at once.
    $platform = User::factory()->create(['company_id' => null, 'primary_role' => 'platform_admin']);
    User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    app(UserAccountService::class)->update($owner, ['is_active' => false], $platform);
    $this->actingAs($platform)->get('/statistics/exports/'.$export->id)->assertNotFound();
    expect(app(TenantContext::class)->runForCompany($company, fn () => $export->fresh()->status))->toBe('revoked')
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

test('HR assignment removal revokes an exported employee report', function (): void {
    Queue::fake();
    Storage::fake('local');
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'hr_staff']);
    $opener = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $case = DisciplinaryCase::factory()->create(['law_version_id' => Law::factory()->create()->id,
        'status' => CaseStatus::Open, 'opened_by_user_id' => $opener->id, 'assigned_hr_user_id' => $hr->id]);
    $export = app(ReportExporter::class)->request($hr, 'employee', ['period' => 'custom',
        'from' => '2026-01-01', 'to' => '2026-01-31', 'employee_id' => $case->employee_id], 'pdf');
    $path = "report-exports/{$export->id}.pdf";
    Storage::disk('local')->put($path, '%PDF'.str_repeat('x', 120));
    $export->forceFill(['status' => 'ready', 'file_path' => $path,
        'file_sha256' => hash('sha256', Storage::disk('local')->get($path)),
        'ready_at' => now(), 'expires_at' => now()->addDays(7)])->save();
    $case->forceFill(['assigned_hr_user_id' => null])->save();
    $this->actingAs($hr)->get('/statistics/exports/'.$export->id)->assertNotFound();
    expect(app(TenantContext::class)->runForCompany($company, fn () => $export->fresh()->status))->toBe('revoked');
});

test('partner losing full case access revokes a compliance export', function (): void {
    Queue::fake();
    Storage::fake('local');
    $company = Company::factory()->create();
    $partner = HrPartner::factory()->create();
    DB::table('company_hr_partner')->insert(['company_id' => $company->id, 'hr_partner_id' => $partner->id,
        'full_case_access' => true]);
    $viewer = User::factory()->create(['company_id' => null, 'primary_role' => 'hr_partner_staff',
        'hr_partner_id' => $partner->id]);
    $export = app(ReportExporter::class)->request($viewer, 'compliance', ['company_id' => $company->id,
        'period' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31'], 'pdf');
    $path = "report-exports/{$export->id}.pdf";
    Storage::disk('local')->put($path, '%PDF'.str_repeat('x', 120));
    app(TenantContext::class)->runForCompany($company, fn () => $export->forceFill([
        'status' => 'ready', 'file_path' => $path,
        'file_sha256' => hash('sha256', Storage::disk('local')->get($path)),
        'ready_at' => now(), 'expires_at' => now()->addDays(7),
    ])->save());
    DB::table('company_hr_partner')->where('company_id', $company->id)->update(['full_case_access' => false]);
    $this->actingAs($viewer)->get('/statistics/exports/'.$export->id)->assertNotFound();
    expect(app(TenantContext::class)->runForCompany($company, fn () => $export->fresh()->status))->toBe('revoked');
});

test('PDF report sends landscape to the generator', function (): void {
    $generator = Mockery::mock(PdfGenerator::class);
    $generator->shouldReceive('generate')->once()->withArgs(fn ($html, $orientation) => $orientation === 'landscape' && str_contains($html, 'CONFIDENTIAL'))->andReturn('%PDF'.str_repeat('x', 120));
    $renderer = new PdfReport($generator);
    $bytes = $renderer->render(['company' => null, 'title' => 'اختبار', 'parameters' => ['from' => '2026-01-01',
        'to' => '2026-01-31'], 'partial' => false, 'truncated' => false, 'summary' => [], 'sections' => []]);
    expect(str_starts_with($bytes, '%PDF'))->toBeTrue();
});

test('Excel uses RTL sheets and stores formula-looking names as string cells', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'report-');
    $previousLocale = app()->getLocale();
    app()->setLocale('ar');
    try {
        app(ExcelReport::class)->render(['summary' => [], 'sections' => [[
            'title' => 'السجل', 'headers' => ['الموظف', 'ISO'],
            'rows' => [['=HYPERLINK("x")', '2026-01-01']],
        ]]], $path);
        $reader = new Reader;
        $reader->open($path);
        $sheets = iterator_to_array($reader->getSheetIterator());
        $rows = iterator_to_array($sheets[2]->getRowIterator()); // sheet 1 is the summary
        // OpenSpout's reader turns any text starting with "=" back into a FormulaCell, so the stored XML is the proof:
        // the value is an inline string and the sheet contains no formula element.
        expect($rows[2]->getCells()[0]->getValue())->toBe('=HYPERLINK("x")');
        $reader->close();
        $zip = new ZipArchive;
        $zip->open($path);
        $sheet = $zip->getFromName('xl/worksheets/sheet2.xml');
        expect($sheet)->toContain('rightToLeft="true"')
            ->toContain('t="inlineStr"><is><t>=HYPERLINK(&quot;x&quot;)</t></is>')->not->toContain('<f>');
        $zip->close();
    } finally {
        app()->setLocale($previousLocale);
        @unlink($path);
    }
});

test('Excel writer preserves five thousand detail rows', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'report-');
    try {
        $rows = [];
        for ($i = 1; $i <= 5000; $i++) {
            $rows[] = [(string) $i, 'اسم '.$i, '2026-01-01'];
        }
        app(ExcelReport::class)->render(['summary' => [], 'sections' => [[
            'title' => 'الحالات', 'headers' => ['العدد', 'الموظف', 'ISO'], 'rows' => $rows,
        ]]], $path);
        $reader = new Reader;
        $reader->open($path);
        $sheets = iterator_to_array($reader->getSheetIterator());
        expect(iterator_count($sheets[2]->getRowIterator()))->toBe(5001); // sheet 1 is the summary
        $reader->close();
    } finally {
        @unlink($path);
    }
});

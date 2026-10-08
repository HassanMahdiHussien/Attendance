<?php

use App\Jobs\GenerateReportExport;
use App\Mail\OperationsMessage;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\User;
use App\Notifications\ApprovalNeeded;
use App\Notifications\CaseDeadlineAlert;
use App\Services\Statistics\ReportBuilder;
use App\Services\Statistics\ReportExporter;
use App\Statistics\ExcelReport;
use App\Statistics\PdfReport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;

test('notification mail follows recipient language while database payload stays translatable', function (): void {
    $user = User::factory()->create(['locale' => 'ar']);
    $notification = new ApprovalNeeded(42);
    $subjects = [];
    $mailChannel = Mockery::mock(MailChannel::class);
    $mailChannel->shouldReceive('send')->twice()->andReturnUsing(function ($notifiable, $message) use (&$subjects): void {
        $subjects[] = [app()->getLocale(), $message->toMail($notifiable)->subject];
    });
    app()->instance(MailChannel::class, $mailChannel);

    expect($user->preferredLocale())->toBe('ar');
    Notification::sendNow($user, $notification, ['mail', 'database']);
    $payload = $user->notifications()->latest()->firstOrFail()->data;
    expect($payload)->toHaveKey('message_key', 'messages.notifications.approval_needed_body')
        ->not->toHaveKey('message');
    app()->setLocale('ar');
    expect(__($payload['message_key'], $payload['message_params']))->toBe('لديك توصيات لجنة بانتظار مراجعتك في النظام.');

    $user->forceFill(['locale' => 'en'])->save();
    Notification::sendNow($user, $notification, ['mail', 'database']);
    expect($subjects)->toBe([
        ['ar', 'توصيات بانتظار قرارك'],
        ['en', 'Recommendations awaiting your decision'],
    ]);
    expect($user->notifications()->count())->toBe(2);
    app()->setLocale('en');
    expect(__($payload['message_key'], $payload['message_params']))->toBe('Committee recommendations await your review in the system.');
});

test('deadline notification no longer forces Arabic and stores a message key', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $case = DisciplinaryCase::factory()->create();
    $notification = new CaseDeadlineAlert($case, 'procedure_approaching');

    app()->setLocale('en');
    expect($notification->toMail(new stdClass)->subject)->toContain('deadline alert');
    $payload = $notification->toArray(new stdClass);
    expect($payload['message_key'])->toBe('messages.notifications.procedure_approaching')
        ->and($payload)->not->toHaveKey('message');
    app()->setLocale('ar');
    expect($notification->toMail(new stdClass)->subject)->toContain('تنبيه موعد القضية');
    expect(__($payload['message_key'], $payload['message_params']))->toBe('اقترب الموعد النهائي للإجراء التأديبي.');
});

test('queued PDF and XLSX exports use the language captured when requested', function (): void {
    Queue::fake();
    Storage::fake('local');
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin', 'locale' => 'en']);
    $params = ['period' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31'];
    $exporter = app(ReportExporter::class);

    $pdfExport = $exporter->request($user, 'periodic', $params, 'pdf');
    expect($pdfExport->locale)->toBe('en');
    $user->forceFill(['locale' => 'ar'])->save();
    app()->setLocale('ar');
    $pdf = Mockery::mock(PdfReport::class);
    $pdf->shouldReceive('render')->once()->andReturnUsing(function (array $report): string {
        expect(app()->getLocale())->toBe('en')->and($report['title'])->toBe('Periodic summary');

        return '%PDF'.str_repeat('x', 120);
    });
    (new GenerateReportExport($pdfExport->id, $company->id))->handle(app(ReportBuilder::class), $pdf, app(ExcelReport::class));
    expect(app()->getLocale())->toBe('ar')->and($pdfExport->fresh()->status)->toBe('ready');

    $xlsxExport = $exporter->request($user, 'periodic', $params, 'xlsx');
    expect($xlsxExport->locale)->toBe('ar');
    expect(app(ReportBuilder::class)->build($user, 'periodic', $params)['title'])->toBe('الملخص الدوري');
    $user->forceFill(['locale' => 'en'])->save();
    app()->setLocale('en');
    (new GenerateReportExport($xlsxExport->id, $company->id))->handle(app(ReportBuilder::class), app(PdfReport::class), app(ExcelReport::class));
    expect(app()->getLocale())->toBe('en')->and($xlsxExport->fresh()->status)->toBe('ready');
    $reader = new Reader;
    $reader->open(Storage::disk('local')->path($xlsxExport->fresh()->file_path));
    foreach ($reader->getSheetIterator() as $sheet) {
        expect($sheet->getName())->toBe('الملخص');
        break;
    }
    $reader->close();
    $archive = new ZipArchive;
    expect($archive->open(Storage::disk('local')->path($xlsxExport->fresh()->file_path)))->toBeTrue();
    expect($archive->getFromName('xl/worksheets/sheet1.xml'))->toContain('rightToLeft="true"');
    $archive->close();
});

test('statistical PDF markup follows the request locale', function (): void {
    $report = ['title' => 'Periodic summary', 'company' => null, 'parameters' => ['from' => '2026-01-01', 'to' => '2026-01-31'],
        'summary' => ['Cases' => 12], 'sections' => [], 'partial' => false, 'truncated' => false];
    $data = ['report' => $report, 'letterhead' => null, 'logo' => null, 'headerImage' => null];

    app()->setLocale('en');
    expect(view('reports.pdf', $data)->render())->toContain('<html lang="en" dir="ltr">', '12', '1 Jan 2026');
    app()->setLocale('ar');
    expect(view('reports.pdf', $data)->render())->toContain('<html lang="ar" dir="rtl">', '١٢', '١ – كانون الثاني – ٢٠٢٦');
});

test('operational mail remains English under an Arabic request locale', function (): void {
    app()->setLocale('ar');
    Mail::fake();
    Artisan::call('ops:test-mail', ['address' => 'operator@example.test']);
    Mail::assertSent(OperationsMessage::class, fn (OperationsMessage $mail): bool => $mail->locale === 'en' && $mail->subjectLine === 'Displanery mail test');
});

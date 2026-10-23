<?php

namespace App\Jobs\Attendance;

use App\Models\Attendance\AttendanceExport;
use App\Models\Company;
use App\Services\Attendance\Reporting\AttendanceExports;
use App\Services\Attendance\Reporting\AttendanceSummary;
use App\Services\Attendance\Reporting\ExportData;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use Spatie\Browsershot\Browsershot;
use Throwable;
use ZipArchive;

class GenerateAttendanceExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public function __construct(public int $exportId) {}

    public function handle(AttendanceExports $exports, ExportData $data, AttendanceSummary $summary): void
    {
        $companyId = DB::table('attendance_exports')->where('id', $this->exportId)->value('company_id');
        $company = Company::findOrFail($companyId);
        app(TenantContext::class)->runForCompany($company, function () use ($company, $exports, $data, $summary): void {
            $export = AttendanceExport::query()->findOrFail($this->exportId);
            $started = DB::transaction(function () use ($export, $exports): bool {
                $locked = AttendanceExport::query()->whereKey($export->id)->lockForUpdate()->firstOrFail();
                $state = $locked->events()->latest('sequence')->value('state');
                $retryAfterLostWorker = $state === 'running' && $this->job !== null && $this->attempts() > 1;
                if ($state !== 'queued' && ! $retryAfterLostWorker) {
                    return false;
                }
                $exports->event($locked, 'running');

                return true;
            });
            if (! $started) {
                return;
            }
            $locale = App::getLocale();
            $relative = 'attendance-exports/'.$export->id.'.'.($export->kind === 'statement_pdf' ? 'pdf' : 'xlsx');
            $temporary = $relative.'.tmp';
            try {
                App::setLocale($export->locale);
                $filters = ReportFilters::fromArray($company, $export->filters);
                Storage::disk('local')->makeDirectory('attendance-exports');
                $path = Storage::disk('local')->path($temporary);
                $rowCount = $export->kind === 'statement_pdf'
                    ? $this->pdf($export, $company, $filters, $data, $path)
                    : $this->xlsx($export, $company, $filters, $data, $summary, $path);
                if (! Storage::disk('local')->move($temporary, $relative)) {
                    throw new RuntimeException('Could not store the generated export.');
                }
                DB::transaction(function () use ($export, $exports, $relative, $rowCount): void {
                    $locked = AttendanceExport::query()->whereKey($export->id)->lockForUpdate()->firstOrFail();
                    $locked->update(['file_path' => $relative, 'row_count' => $rowCount]);
                    $exports->event($locked, 'completed');
                    app(AuditLogger::class)->log('attendance.export.completed', $locked,
                        ['kind' => $locked->kind, 'row_count' => $rowCount]);
                });
            } catch (Throwable $exception) {
                Storage::disk('local')->delete($temporary);
                DB::transaction(function () use ($export, $exports, $exception): void {
                    $locked = AttendanceExport::query()->whereKey($export->id)->lockForUpdate()->firstOrFail();
                    $code = $exception->getMessage() === 'chrome_missing' ? 'chrome_missing' : 'generation_failed';
                    $exports->event($locked, 'failed', $code);
                    app(AuditLogger::class)->log('attendance.export.failed', $locked, ['kind' => $locked->kind, 'error_code' => $code]);
                });
                throw $exception;
            } finally {
                App::setLocale($locale);
            }
        });
    }

    private function xlsx(AttendanceExport $export, Company $company, ReportFilters $filters, ExportData $data, AttendanceSummary $summary, string $path): int
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $columns = $data->columns($export->kind);
        $numeric = ['absence', 'late', 'early', 'exit', 'gross', 'leave', 'credit', 'remaining', 'request_count',
            'minutes', 'created', 'used', 'available', 'applied_minutes', 'meals', 'repetitions', 'issues'];
        $sums = array_fill_keys(array_intersect($columns, $numeric), 0);
        $count = 0;
        try {
            foreach ($data->header($export, $company, $filters) as $header) {
                $writer->addRow(Row::fromValues($header));
            }
            $writer->addRow(Row::fromValues(['']));
            $writer->addRow(Row::fromValues(array_map(fn (string $column): string => __('messages.export_ui.columns.'.$column), $columns)));
            foreach ($data->rows($export, $filters) as $row) {
                $values = [];
                foreach ($columns as $column) {
                    $value = $row[$column] ?? '';
                    $values[] = in_array($column, $numeric, true) ? (int) $value : (string) $value;
                    if (isset($sums[$column])) {
                        $sums[$column] += (int) $value;
                    }
                }
                $writer->addRow(Row::fromValues($values));
                $count++;
            }
            $writer->addRow(Row::fromValues(array_map(fn (string $column): string|int => $column === $columns[0]
                ? __('messages.export_ui.total') : ($sums[$column] ?? ''), $columns)));
            if ($export->kind === 'employee_statement_xlsx') {
                $totals = $summary->totals($filters);
                $writer->addRow(Row::fromValues([__('messages.export_ui.assessed_days'), $totals['assessed_working_days']]));
                $writer->addRow(Row::fromValues([__('messages.export_ui.present_days'), $totals['days']['present']]));
                $writer->addRow(Row::fromValues([__('messages.export_ui.pending_days'), $totals['days']['pending']]));
            }
        } finally {
            $writer->close();
        }
        if ($export->locale === 'ar') {
            $this->rightToLeftSheet($path);
        }

        return $count;
    }

    private function rightToLeftSheet(string $path): void
    {
        $archive = new ZipArchive;
        if ($archive->open($path) !== true) {
            throw new RuntimeException('Could not open the generated spreadsheet.');
        }
        try {
            $xml = $archive->getFromName('xl/worksheets/sheet1.xml');
            if ($xml === false) {
                throw new RuntimeException('Could not find the generated worksheet.');
            }
            if (str_contains($xml, 'rightToLeft="1"')) {
                return;
            }
            if (preg_match('/<sheetViews\b/', $xml) === 1) {
                $xml = preg_replace('/\srightToLeft="[^"]*"/', '', $xml);
                $xml = preg_replace('/(<sheetView\b)([^>]*?)(\/?>)/', '$1 rightToLeft="1"$2$3', $xml, 1);
            } else {
                $xml = preg_replace('/(<worksheet\b[^>]*>)/', '$1<sheetViews><sheetView workbookViewId="0" rightToLeft="1"/></sheetViews>', $xml, 1);
            }
            if ($xml === null || ! str_contains($xml, 'rightToLeft="1"')
                || ! $archive->addFromString('xl/worksheets/sheet1.xml', $xml)) {
                throw new RuntimeException('Could not set the worksheet direction.');
            }
        } finally {
            $archive->close();
        }
    }

    private function pdf(AttendanceExport $export, Company $company, ReportFilters $filters, ExportData $data, string $path): int
    {
        $chrome = config('attendance.pdf.chrome_path', '/usr/local/bin/chrome-headless-shell');
        if (! is_file($chrome)) {
            throw new RuntimeException('chrome_missing');
        }
        $statement = $data->statement($export, $company, $filters);
        $html = view('attendance.statement', $statement)->render();
        Browsershot::html($html)->setChromePath($chrome)->noSandbox()->format('A4')->savePdf($path);

        return count($statement['days']);
    }
}

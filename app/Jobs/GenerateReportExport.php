<?php

namespace App\Jobs;

use App\Models\ReportExport;
use App\Services\Statistics\ReportBuilder;
use App\Statistics\ExcelReport;
use App\Statistics\PdfReport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class GenerateReportExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    public array $backoff = [5, 30, 120];

    public function __construct(public int $exportId, public ?int $companyId)
    {
        $this->afterCommit();
    }

    public function handle(ReportBuilder $builder, PdfReport $pdf, ExcelReport $excel): void
    {
        $context = app(TenantContext::class);
        $work = function () use ($builder, $pdf, $excel): void {
            $export = ReportExport::findOrFail($this->exportId);
            if ($export->status === 'ready') {
                return;
            }
            if ($export->status !== 'queued') {
                throw new RuntimeException('Export is not queued.');
            }
            $owner = $export->owner;
            $report = $builder->build($owner, $export->report_type, $export->parameters,
                $export->format === 'pdf' ? 1000 : null);
            $relative = "report-exports/{$export->id}.{$export->format}";
            $final = Storage::disk('local')->path($relative);
            $directory = dirname($final);
            if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new RuntimeException('Cannot create report export directory.');
            }
            $temp = tempnam($directory, '.export-');
            if ($temp === false) {
                throw new RuntimeException('Cannot create temporary report export.');
            }
            try {
                if ($export->format === 'pdf') {
                    $bytes = $pdf->render($report);
                    if (file_put_contents($temp, $bytes) !== strlen($bytes)) {
                        throw new RuntimeException('Incomplete PDF output.');
                    }
                } else {
                    $excel->render($report, $temp);
                }
                $size = filesize($temp);
                $signature = file_get_contents($temp, false, null, 0, 4);
                if ($size < 100 || ($export->format === 'pdf' && $signature !== '%PDF') ||
                    ($export->format === 'xlsx' && $signature !== "PK\x03\x04")) {
                    throw new RuntimeException('Report output is empty or invalid.');
                }
                $sha = hash_file('sha256', $temp);
                if (is_file($final)) {
                    if (hash_file('sha256', $final) !== $sha) {
                        throw new RuntimeException('Existing export has a different hash.');
                    }
                } else {
                    $handle = @fopen($final, 'x');
                    if ($handle === false) {
                        if (! is_file($final) || hash_file('sha256', $final) !== $sha) {
                            throw new RuntimeException('Concurrent report export has a different hash.');
                        }
                    } else {
                        try {
                            $source = fopen($temp, 'rb');
                            try {
                                if (stream_copy_to_stream($source, $handle) !== $size) {
                                    throw new RuntimeException('Report export promotion was incomplete.');
                                }
                                fflush($handle);
                                if (function_exists('fsync')) {
                                    fsync($handle);
                                }
                            } finally {
                                fclose($source);
                            }
                        } finally {
                            fclose($handle);
                        }
                    }
                }
                if (hash_file('sha256', $final) !== $sha) {
                    throw new RuntimeException('Final report export hash mismatch.');
                }
                $ready = now();
                $export->forceFill(['file_path' => $relative, 'file_sha256' => $sha, 'status' => 'ready',
                    'error' => null, 'ready_at' => $ready, 'expires_at' => $ready->copy()->addDays(7)])->save();
            } finally {
                @unlink($temp);
            }
        };
        $this->companyId === null ? $context->runAcrossAllCompanies($work)
            : $context->runForCompany($this->companyId, $work);
    }

    public function failed(?Throwable $exception): void
    {
        $context = app(TenantContext::class);
        $work = function () use ($exception): void {
            $export = ReportExport::find($this->exportId);
            if ($export && $export->status === 'queued') {
                $export->forceFill(['status' => 'failed',
                    'error' => mb_substr($exception?->getMessage() ?? 'Report generation failed.', 0, 1900)])->save();
            }
        };
        $this->companyId === null ? $context->runAcrossAllCompanies($work)
            : $context->runForCompany($this->companyId, $work);
    }
}

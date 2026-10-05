<?php

namespace App\Jobs;

use App\Documents\PdfGenerator;
use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use App\Notifications\DocumentPdfFailed;
use App\Services\CaseEventLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class GenerateDocumentPdf implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [5, 30, 120];

    public function __construct(public int $documentId, public int $companyId)
    {
        $this->afterCommit();
    }

    public function handle(PdfGenerator $generator): void
    {
        app(TenantContext::class)->runForCompany(Company::findOrFail($this->companyId), function () use ($generator): void {
            $document = Document::findOrFail($this->documentId);
            if ($document->pdf_status === 'ready') {
                return;
            }
            if ($document->pdf_status !== 'pending' || blank($document->rendered_html)) {
                throw new RuntimeException('Document is not ready for PDF generation.');
            }
            $bytes = $generator->generate($document->rendered_html);
            if (strlen($bytes) < 100 || ! str_starts_with($bytes, '%PDF')) {
                throw new RuntimeException('PDF output is empty or invalid.');
            }
            $sha = hash('sha256', $bytes);
            $relative = "documents/{$document->company_id}/{$document->case_id}/{$document->document_type->value}-v{$document->version}.pdf";
            $final = Storage::disk('local')->path($relative);
            $directory = dirname($final);
            if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new RuntimeException('Cannot create private PDF directory.');
            }
            $temp = tempnam($directory, '.pdf-');
            if ($temp === false) {
                throw new RuntimeException('Cannot create temporary PDF file.');
            }
            try {
                if (file_put_contents($temp, $bytes) !== strlen($bytes) || hash_file('sha256', $temp) !== $sha) {
                    throw new RuntimeException('Temporary PDF verification failed.');
                }
                if (is_file($final)) {
                    if (hash_file('sha256', $final) !== $sha) {
                        throw new RuntimeException('Existing PDF has a different hash.');
                    }
                } else {
                    $handle = @fopen($final, 'x');
                    if ($handle === false) {
                        if (! is_file($final) || hash_file('sha256', $final) !== $sha) {
                            throw new RuntimeException('PDF was created concurrently with a different hash.');
                        }
                    } else {
                        try {
                            $source = fopen($temp, 'rb');
                            try {
                                if (stream_copy_to_stream($source, $handle) !== strlen($bytes)) {
                                    throw new RuntimeException('PDF promotion was incomplete.');
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
                    throw new RuntimeException('Final PDF hash mismatch.');
                }
                $document->forceFill(['pdf_file_path' => $relative, 'pdf_sha256' => $sha,
                    'pdf_status' => 'ready', 'pdf_error' => null])->save();
            } finally {
                @unlink($temp);
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        app(TenantContext::class)->runForCompany(Company::findOrFail($this->companyId), function () use ($exception): void {
            $document = Document::findOrFail($this->documentId);
            if ($document->pdf_status === 'ready') {
                return;
            }
            $message = mb_substr($exception?->getMessage() ?? 'PDF generation failed.', 0, 1900);
            $document->forceFill(['pdf_status' => 'failed', 'pdf_error' => $message])->save();
            app(CaseEventLogger::class)->log($document->disciplinaryCase, 'document_pdf_failed',
                ['document_id' => $document->id, 'error' => $message]);
            $recipients = User::query()->where('company_id', $document->company_id)->where('is_active', true)
                ->whereIn('primary_role', ['company_admin', 'hr_staff'])->get();
            Notification::send($recipients, new DocumentPdfFailed($document->id, $message));
        });
    }
}

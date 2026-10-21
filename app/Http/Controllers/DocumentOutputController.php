<?php

namespace App\Http\Controllers;

use App\Documents\DocumentRenderer;
use App\Documents\PdfGenerator;
use App\Exceptions\IssuanceBlocked;
use App\Models\Document;
use App\Services\Documents\DraftPreview;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentOutputController extends Controller
{
    public function preview(Request $request, string $document, DocumentRenderer $renderer, PdfGenerator $pdf, DraftPreview $previews)
    {
        $document = Document::withoutGlobalScopes()->findOrFail($document);
        abort_unless($request->user()->can('view', $document),
            (int) $request->user()->company_id === (int) $document->company_id ? 403 : 404);
        abort_if($document->is_locked, 409, 'Issued documents use the stored PDF.');
        app(TenantContext::class)->setCompany($document->company);

        try {
            $token = $request->query('token');
            $contents = $previews->contents(is_string($token) ? $token : null, $document, $request->user());
            $html = $pdf->htmlWithFonts($renderer->render($document, true, $contents));
            $status = 200;
        } catch (IssuanceBlocked $error) {
            $html = view('documents.missing', ['reason' => $error->getMessage()])->render();
            $status = 422;
        }

        return response($html, $status)->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Content-Security-Policy', "default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:; sandbox; frame-ancestors 'self'")
            ->header('X-Frame-Options', 'SAMEORIGIN')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Cache-Control', 'private, no-store');
    }

    public function pdf(Request $request, string $document)
    {
        return $this->storedPdf($request, $document, false);
    }

    public function view(Request $request, string $document)
    {
        return $this->storedPdf($request, $document, true);
    }

    private function storedPdf(Request $request, string $document, bool $inline)
    {
        $document = Document::withoutGlobalScopes()->findOrFail($document);
        abort_unless($request->user()->can('view', $document),
            (int) $request->user()->company_id === (int) $document->company_id ? 403 : 404);
        abort_unless($document->pdf_status === 'ready', 409, __('messages.document.pdf_pending_or_failed'));
        $path = $document->pdf_file_path;
        $disk = Storage::disk('local');
        abort_unless($path && str_starts_with($path, "documents/{$document->company_id}/{$document->case_id}/")
            && $disk->exists($path)
            && hash_file('sha256', $disk->path($path)) === $document->pdf_sha256, 409,
            __('messages.document.pdf_integrity_failed'));

        if ($inline) {
            return response()->file($disk->path($path), [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.basename($path).'"',
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'SAMEORIGIN',
                'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'self'",
            ])->setCache(['no_store' => true, 'private' => true]);
        }

        return $disk->download($path, basename($path), ['Content-Type' => 'application/pdf']);
    }
}

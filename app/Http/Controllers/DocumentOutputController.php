<?php

namespace App\Http\Controllers;

use App\Documents\DocumentRenderer;
use App\Documents\PdfGenerator;
use App\Exceptions\IssuanceBlocked;
use App\Models\Document;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentOutputController extends Controller
{
    public function preview(Request $request, string $document, DocumentRenderer $renderer, PdfGenerator $pdf)
    {
        $document = Document::withoutGlobalScopes()->findOrFail($document);
        abort_unless($request->user()->can('view', $document),
            (int) $request->user()->company_id === (int) $document->company_id ? 403 : 404);
        abort_if($document->is_locked, 409, 'Issued documents use the stored PDF.');
        app(TenantContext::class)->setCompany($document->company);

        try {
            $html = $pdf->htmlWithFonts($renderer->render($document, true));
            $status = 200;
        } catch (IssuanceBlocked $error) {
            $html = view('documents.missing', ['reason' => $error->getMessage()])->render();
            $status = 422;
        }

        return response($html, $status)->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Content-Security-Policy', "default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:; sandbox");
    }

    public function pdf(Request $request, string $document)
    {
        $document = Document::withoutGlobalScopes()->findOrFail($document);
        abort_unless($request->user()->can('view', $document),
            (int) $request->user()->company_id === (int) $document->company_id ? 403 : 404);
        abort_unless($document->pdf_status === 'ready', 409, 'ملف PDF قيد الإنشاء أو تعذر إنشاؤه.');
        $path = $document->pdf_file_path;
        $disk = Storage::disk('local');
        abort_unless($path && str_starts_with($path, "documents/{$document->company_id}/{$document->case_id}/")
            && $disk->exists($path)
            && hash_file('sha256', $disk->path($path)) === $document->pdf_sha256, 409,
            'تعذر التحقق من سلامة ملف PDF.');

        return $disk->download($path, basename($path), ['Content-Type' => 'application/pdf']);
    }
}

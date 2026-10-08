<?php

namespace App\Statistics;

use App\Documents\PdfGenerator;
use App\Models\CompanyLetterhead;
use Illuminate\Support\Facades\Storage;

class PdfReport
{
    public function __construct(private PdfGenerator $pdf) {}

    public function render(array $report): string
    {
        $letterhead = $report['company'] ? CompanyLetterhead::query()
            ->where('company_id', $report['company']->id)->where('language_code', 'ar')
            ->orderByDesc('version')->first() : null;
        $logo = $this->image($letterhead?->logo_path, $letterhead?->logo_sha256);
        $headerImage = $letterhead?->header_mode === 'image'
            ? $this->image($letterhead->header_image_path, $letterhead->header_image_sha256) : null;
        $html = view('reports.pdf', compact('report', 'letterhead', 'logo', 'headerImage'))->render();

        return $this->pdf->generate($html, 'landscape');
    }

    private function image(?string $path, ?string $sha): ?string
    {
        if (! $path || ! $sha || ! Storage::disk('local')->exists($path)) {
            return null;
        }
        $bytes = Storage::disk('local')->get($path);
        if (hash('sha256', $bytes) !== $sha) {
            return null;
        }

        return 'data:image/'.(str_ends_with($path, '.png') ? 'png' : 'jpeg').';base64,'.base64_encode($bytes);
    }
}

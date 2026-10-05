<?php

namespace App\Documents;

use RuntimeException;
use Spatie\Browsershot\Browsershot;

class PdfGenerator
{
    public function generate(string $frozenHtml): string
    {
        $chrome = config('displanery.pdf.chrome_path');
        if (! is_file($chrome)) {
            throw new RuntimeException("Chrome is unavailable at {$chrome}.");
        }
        $html = $this->htmlWithFonts($frozenHtml);

        return Browsershot::html($html)->setChromePath($chrome)->noSandbox()->disableJavascript()
            ->blockUrls(['http://', 'https://'])->format('A4')->showBackground()->pdf();
    }

    public function htmlWithFonts(string $frozenHtml): string
    {
        $faces = '';
        foreach (['Amiri-Regular.ttf' => ['Displanery Amiri', 400], 'Amiri-Bold.ttf' => ['Displanery Amiri', 700],
            'NotoNaskhArabic-Variable.ttf' => ['Displanery Naskh', 400]] as $filename => [$family, $weight]) {
            $path = resource_path('fonts/'.$filename);
            if (! is_file($path)) {
                throw new RuntimeException("Missing PDF font {$filename}.");
            }
            $faces .= "@font-face{font-family:'{$family}';font-weight:{$weight};src:url(data:font/ttf;base64,".base64_encode(file_get_contents($path)).') format("truetype");}';
        }

        return str_replace('</head>', "<style>{$faces}</style></head>", $frozenHtml);
    }
}

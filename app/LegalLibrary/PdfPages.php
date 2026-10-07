<?php

namespace App\LegalLibrary;

use RuntimeException;
use Symfony\Component\Process\Process;

class PdfPages
{
    public function count(string $pdf): int
    {
        $output = $this->run([config('displanery.legal_library.binaries.pdfinfo', '/usr/bin/pdfinfo'), $pdf]);
        if (! preg_match('/^Pages:\s*(\d+)\s*$/m', $output, $match)) {
            throw new RuntimeException('PDF page count is unavailable.');
        }

        return (int) $match[1];
    }

    public function text(string $pdf, int $page): string
    {
        return $this->run([config('displanery.legal_library.binaries.pdftotext', '/usr/bin/pdftotext'), '-f', (string) $page, '-l', (string) $page, '-layout', '-enc', 'UTF-8', $pdf, '-']);
    }

    public function render(string $pdf, int $page, string $prefix): string
    {
        $this->run([config('displanery.legal_library.binaries.pdftoppm', '/usr/bin/pdftoppm'), '-r', (string) config('displanery.legal_library.dpi', 250), '-png', '-f', (string) $page, '-l', (string) $page, '-singlefile', $pdf, $prefix]);
        $path = $prefix.'.png';
        $size = getimagesize($path);
        if ($size === false) {
            throw new RuntimeException('PDF page image is invalid.');
        }
        $max = (int) config('displanery.legal_library.max_long_edge', 4000);
        if (max($size[0], $size[1]) > $max) {
            $source = imagecreatefrompng($path);
            $scale = $max / max($size[0], $size[1]);
            $target = imagescale($source, (int) round($size[0] * $scale), (int) round($size[1] * $scale));
            imagepng($target, $path);
            imagedestroy($source);
            imagedestroy($target);
        }

        return $path;
    }

    public function ocr(string $png): string
    {
        return $this->run([config('displanery.legal_library.binaries.tesseract', '/usr/bin/tesseract'), $png, '-', '-l', 'ara', '--psm', '6']);
    }

    private function run(array $arguments): string
    {
        $process = new Process($arguments);
        $process->setTimeout((int) config('displanery.legal_library.process_timeout', 120));
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException(mb_substr($process->getErrorOutput(), 0, 500));
        }

        return $process->getOutput();
    }
}

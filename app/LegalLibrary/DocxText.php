<?php

namespace App\LegalLibrary;

use DOMDocument;
use DOMXPath;
use RuntimeException;
use ZipArchive;

class DocxText
{
    public function read(string $path): string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Word file is invalid.');
        }
        try {
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $total += $zip->statIndex($i)['size'];
                if ($total > (int) config('displanery.legal_library.docx_max_uncompressed_mb', 20) * 1024 * 1024) {
                    throw new RuntimeException('Word file exceeds the uncompressed size limit.');
                }
            }
            $xml = $zip->getFromName('word/document.xml');
        } finally {
            $zip->close();
        }
        if ($xml === false) {
            throw new RuntimeException('Word document text is missing.');
        }
        $document = new DOMDocument;
        if (! @$document->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('Word document XML is invalid.');
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $paragraphs = [];
        foreach ($xpath->query('//w:body//w:p') as $paragraph) {
            $text = '';
            foreach ($xpath->query('.//w:t[not(ancestor::w:del)]', $paragraph) as $node) {
                $text .= $node->textContent;
            }
            $paragraphs[] = $text;
        }

        return implode("\n", $paragraphs);
    }
}

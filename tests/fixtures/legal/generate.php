<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';

use Spatie\Browsershot\Browsershot;

$root = dirname(__DIR__, 3);
$folder = __DIR__;
$chrome = getenv('DISPLANERY_CHROME_PATH') ?: '/usr/local/bin/chrome-headless-shell';
// Amiri gives Chrome a clean text layer; Noto Naskh produces spaces inside words ("الث الث ة"), which is
// kept as spaced-law.pdf to exercise the text-layer probe's fallback to OCR.
$fontCss = fn (string $file): string => '@font-face{font-family:doc;src:url(data:font/ttf;base64,'
    .base64_encode(file_get_contents($root.'/resources/fonts/'.$file)).')}';
$font = $fontCss('Amiri-Regular.ttf');
$css = "{$font}body{font-family:doc;direction:rtl;font-size:21px;margin:35mm 22mm}.header,.footer{font-size:14px;text-align:center}.footer{position:absolute;bottom:15mm;width:80%}h2{font-size:23px;margin-top:12mm}p{margin:5mm 0}";
$pages = [
    '<div class="header">الوقائع العراقية — العدد 0000</div><h2>المادة 1</h2><p>تسري أحكام هذا القانون على المؤسسة.</p><h2>المادة (2)</h2><p>أولاً- تحفظ السجلات في موضع آمن.</p><p>ثانياً- تتبع الإدارة الخطوات الآتية:</p><p>أ- تدقق الطلبات.</p><p>ب- تحفظ النسخ.</p><h2>المادة الثالثة</h2><p>تبدأ هذه المادة في الصفحة الأولى. '.str_repeat('وتحفظ نسخة من السجل في موضع آمن. ', 5).'</p><div class="footer">1</div>',
    '<div class="header">الوقائع العراقية — العدد 0000</div><p>وتستمر في الصفحة الثانية مع بيان موجز. '.str_repeat('وتراجع الإدارة النسخة عند الحاجة. ', 6).'</p><h2>المادة -4-</h2><p>ينشر هذا القانون ويعمل به من تاريخ نشره.</p><div class="footer">2</div>',
];
$html = fn (string $body): string => '<!doctype html><html lang="ar"><head><meta charset="utf-8"><style>'.$css.'</style></head><body>'.$body.'</body></html>';
$document = $html(implode('<div style="break-after:page"></div>', $pages));
Browsershot::html($document)->setChromePath($chrome)->noSandbox()->format('A4')->showBackground()->savePdf($folder.'/text-law.pdf');
Browsershot::html(str_replace($font, $fontCss('NotoNaskhArabic-Variable.ttf'), $document))->setChromePath($chrome)->noSandbox()
    ->format('A4')->showBackground()->savePdf($folder.'/spaced-law.pdf');
$images = [];
foreach ($pages as $number => $page) {
    $path = $folder.'/page-'.($number + 1).'.png';
    // Image pages use Noto Naskh: Tesseract reads it far more reliably than Amiri's calligraphic forms.
    Browsershot::html(str_replace($font, $fontCss('NotoNaskhArabic-Variable.ttf'), $html($page)))->setChromePath($chrome)->noSandbox()->windowSize(794, 1123)->deviceScaleFactor(3)->save($path);
    $images[] = '<div style="width:210mm;height:296mm;overflow:hidden;'.($number === 0 ? 'break-after:page;' : '').'"><img style="display:block;width:210mm;height:296mm;object-fit:contain" src="data:image/png;base64,'.base64_encode(file_get_contents($path)).'"></div>';
    unlink($path);
}
Browsershot::html('<!doctype html><html><head><style>@page{size:A4;margin:0}body{margin:0}</style></head><body>'.implode('', $images).'</body></html>')
    ->setChromePath($chrome)->noSandbox()->format('A4')->showBackground()->savePdf($folder.'/image-law.pdf');

$zip = new ZipArchive;
$zip->open($folder.'/regulation.docx', ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
$zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
$zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>المادة 1 أحكام عامة</w:t></w:r></w:p><w:p><w:r><w:t>المادة 2 </w:t></w:r><w:del><w:r><w:delText>نص محذوف</w:delText></w:r></w:del><w:ins><w:r><w:t>نص مضاف</w:t></w:r></w:ins></w:p><w:p><w:r><w:t>المادة الثالثة أحكام ختامية</w:t></w:r></w:p></w:body></w:document>');
$zip->close();

<!doctype html>
<html lang="ar">
<head>
<meta charset="utf-8">
<style>
    @page { size: A4; margin: 18mm 16mm 20mm; }
    * { box-sizing: border-box; }
    /* A legal document is always ink on white paper, whatever the viewer's colour scheme. */
    :root { color-scheme: light; }
    html, body { background: #ffffff; }
    body { margin: 0; color: #171717; font-family: 'Displanery Naskh', serif; font-size: 15pt; line-height: 1.9; }
    .page[lang="en"] { font-family: 'Displanery Amiri', serif; }
    .page { width: 100%; page-break-after: always; }
    .page:last-child { page-break-after: auto; }
    .page-table { width: 100%; border-collapse: collapse; }
    .page-table thead { display: table-header-group; }
    .page-table tfoot { display: table-footer-group; }
    .page-table td { vertical-align: top; }
    .letterhead { width: 100%; display: flex; align-items: flex-start; justify-content: space-between; min-height: 27mm; color: #a02c2c; font-size: 9pt; line-height: 1.25; }
    .letterhead-logo { max-width: 34mm; max-height: 24mm; object-fit: contain; }
    .letterhead-text { max-width: 60%; text-align: right; white-space: pre-line; }
    .header-image { max-width: 100%; max-height: 27mm; object-fit: contain; }
    .footer { color: #b72525; text-align: center; font: bold 9pt sans-serif; padding-top: 9mm; }
    h1 { text-align: center; font-size: 18pt; margin: 5mm 0 7mm; font-weight: bold; }
    .recommendations h1 { text-decoration: underline; }
    .grid { margin: 0 0 7mm; }
    .grid-row { display: flex; gap: 3mm; margin: 1.5mm 0; }
    .grid-row strong { min-width: 24mm; }
    .grid-row span { font-weight: bold; }
    .body p { margin: 2mm 0; }
    .body ul, .body ol { margin: 2mm 0; padding-inline-start: 8mm; }
    .block { margin: 3mm 0; white-space: pre-line; }
    .signatures { display: flex; justify-content: space-between; gap: 8mm; margin-top: 12mm; }
    .signatures > div { width: 50%; }
    .acknowledgment { margin-top: 12mm; }
    .draft-mark { position: fixed; top: 43%; left: 15%; transform: rotate(-30deg); opacity: .14; color: #a33; font: bold 48pt sans-serif; z-index: 10; pointer-events: none; }
</style>
</head>
<body>
@if($draft)<div class="draft-mark">مسودة / DRAFT</div>@endif
@foreach($pages as $page)
@php($b = $page['blocks'])
@php($f = $page['facts'])
@php($extra = $page['extra'])
<section class="page {{ $page['type'] }}" lang="{{ $page['language'] }}" dir="{{ $page['language'] === 'en' ? 'ltr' : 'rtl' }}">
<table class="page-table">
<thead><tr><td>
<div class="letterhead" dir="ltr">
@if(($page['options']['header_mode'] ?? 'logo_text') === 'image' && $page['headerImage'])
    <img class="header-image" src="{{ $page['headerImage'] }}" alt="">
@else
    <div>@if($page['logo'])<img class="letterhead-logo" src="{{ $page['logo'] }}" alt="">@endif</div>
    <div class="letterhead-text" dir="{{ $page['language'] === 'en' ? 'ltr' : 'rtl' }}"><strong>{{ $page['letterhead']->name }}</strong><br>{{ $page['letterhead']->address_lines }}<br>{{ $page['letterhead']->phone }}<br>{{ $page['letterhead']->email }}</div>
@endif
</div>
</td></tr></thead>
<tfoot><tr><td>@if($page['options']['show_footer'] ?? true)<div class="footer">{{ $page['letterhead']->footer_text }}</div>@endif</td></tr></tfoot>
<tbody><tr><td>
@if($page['type'] === 'defense_letter')
    @include('documents.defense_letter')
@elseif($page['type'] === 'recommendations')
    @include('documents.recommendations')
@else
    @include('documents.decision_letter')
@endif
</td></tr></tbody>
</table>
</section>
@endforeach
</body>
</html>

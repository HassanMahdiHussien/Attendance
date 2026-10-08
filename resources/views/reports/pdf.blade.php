<!doctype html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8"><style>
@page { size: A4 landscape; margin: 14mm; }
body { font-family: 'Displanery Amiri', 'Displanery Naskh', serif; color: #172133; font-size: 11pt; }
header { display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #9c2727; padding-bottom: 7mm; }
header img { max-height: 18mm; max-width: 30mm; }
h1 { text-align: center; font-size: 18pt; } h2 { margin-top: 9mm; font-size: 14pt; }
table { width: 100%; border-collapse: collapse; margin-top: 3mm; table-layout: fixed; }
th, td { border: 1px solid #d8dce2; padding: 2mm; overflow-wrap: anywhere; text-align: right; }
th { background: #f1f3f6; } footer { position: fixed; bottom: -8mm; width: 100%; color: #9c2727; text-align: center; font: bold 9pt sans-serif; }
.note { background: #fff3dc; padding: 3mm; }
</style></head><body>
<header>@if($headerImage)<img src="{{ $headerImage }}" alt=""><div></div>@else<div>@if($logo)<img src="{{ $logo }}" alt="">@endif</div><div>
@if($letterhead)<strong>{{ $letterhead->name }}</strong><br>{{ $letterhead->address_lines }}<br>{{ $letterhead->phone }}@else<strong>منصة Displanery — كل الشركات</strong>@endif
</div>@endif</header>
<h1>{{ $report['title'] }}</h1>
<p>حتى نهاية الفترة · {{ \App\Support\Format\ArabicDigits::convert(\App\Documents\IraqiDate::format(\Carbon\CarbonImmutable::parse($report['parameters']['from']), 'ar')) }} — {{ \App\Support\Format\ArabicDigits::convert(\App\Documents\IraqiDate::format(\Carbon\CarbonImmutable::parse($report['parameters']['to']), 'ar')) }}</p>
@if($report['partial'])<p class="note">قد توجد حالات أخرى لا تظهر لك</p>@endif
@if($report['truncated'])<p class="note">يُعرض أول ١٬٠٠٠ صف — استخدم Excel للقائمة الكاملة</p>@endif
@if($report['summary'])<table><tbody>@foreach($report['summary'] as $label => $value)<tr><th>{{ $label }}</th><td>{{ is_numeric($value) ? \App\Support\Format\ArabicDigits::convert((string) $value) : ($value ?? '—') }}</td></tr>@endforeach</tbody></table>@endif
@foreach($report['sections'] as $section)<h2>{{ $section['title'] }}</h2><table><thead><tr>@foreach($section['headers'] as $header)<th>{{ $header }}</th>@endforeach</tr></thead><tbody>@forelse($section['rows'] as $row)<tr>@foreach($row as $cell)<td>{{ is_numeric($cell) ? \App\Support\Format\ArabicDigits::convert((string) $cell) : ($cell ?? '—') }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($section['headers']) }}">لا توجد بيانات لهذه الفترة.</td></tr>@endforelse</tbody></table>@endforeach
<footer>CONFIDENTIAL</footer>
</body></html>

<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}"><head><meta charset="utf-8"><style>
@page { size: A4 landscape; margin: 14mm; }
body { font-family: 'Displanery Amiri', 'Displanery Naskh', serif; color: #172133; font-size: 11pt; }
header { display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #9c2727; padding-bottom: 7mm; }
header img { max-height: 18mm; max-width: 30mm; }
h1 { text-align: center; font-size: 18pt; } h2 { margin-top: 9mm; font-size: 14pt; }
table { width: 100%; border-collapse: collapse; margin-top: 3mm; table-layout: fixed; }
th, td { border: 1px solid #d8dce2; padding: 2mm; overflow-wrap: anywhere; text-align: start; }
th { background: #f1f3f6; } footer { position: fixed; bottom: -8mm; width: 100%; color: #9c2727; text-align: center; font: bold 9pt sans-serif; }
.note { background: #fff3dc; padding: 3mm; }
</style></head><body>
<header>@if($headerImage)<img src="{{ $headerImage }}" alt=""><div></div>@else<div>@if($logo)<img src="{{ $logo }}" alt="">@endif</div><div>
@if($letterhead)<strong>{{ $letterhead->name }}</strong><br>{{ $letterhead->address_lines }}<br>{{ $letterhead->phone }}@else<strong>{{ $report['company']?->name ?? __('Displanery — all companies') }}</strong>@endif
</div>@endif</header>
<h1>{{ $report['title'] }}</h1>
<p>{{ __('Through period end') }} · {{ \App\Support\Format\Ui::date($report['parameters']['from']) }} — {{ \App\Support\Format\Ui::date($report['parameters']['to']) }}</p>
@if($report['partial'])<p class="note">{{ __('Other cases may not be visible to you') }}</p>@endif
@if($report['truncated'])<p class="note">{{ __('Showing the first 1,000 rows — use Excel for the complete list') }}</p>@endif
@if($report['summary'])<table><tbody>@foreach($report['summary'] as $label => $value)<tr><th>{{ $label }}</th><td>{{ is_numeric($value) ? \App\Support\Format\Ui::number($value) : ($value ?? '—') }}</td></tr>@endforeach</tbody></table>@endif
@foreach($report['sections'] as $section)<h2>{{ $section['title'] }}</h2><table><thead><tr>@foreach($section['headers'] as $header)<th>{{ $header }}</th>@endforeach</tr></thead><tbody>@forelse($section['rows'] as $row)<tr>@foreach($row as $cell)<td>{{ is_numeric($cell) ? \App\Support\Format\Ui::number($cell) : ($cell ?? '—') }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($section['headers']) }}">{{ __('No data for this period.') }}</td></tr>@endforelse</tbody></table>@endforeach
<footer>{{ __('CONFIDENTIAL') }}</footer>
</body></html>

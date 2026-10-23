<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        @@font-face {
            font-family: 'Noto Naskh Arabic';
            src: url('data:font/truetype;base64,{{ base64_encode(file_get_contents(resource_path('fonts/NotoNaskhArabic-Variable.ttf'))) }}') format('truetype');
        }
        body { font-family: 'Readex Pro', sans-serif; color: #142139; font-size: 11px; line-height: 1.6; }
        html[dir="rtl"] body { font-family: 'Noto Naskh Arabic', serif; }
        h1 { font-size: 22px; margin-bottom: 4px; }
        h2 { font-size: 14px; margin-top: 22px; }
        .muted { color: #607086; }
        .mono { font-family: 'JetBrains Mono', monospace; direction: ltr; unicode-bidi: isolate; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        th, td { border-bottom: 1px solid #d9e0e8; padding: 6px; text-align: start; vertical-align: top; }
        th { background: #eef2f7; }
        .note { background: #f7f9fc; padding: 8px; margin-top: 5px; }
    </style>
</head>
<body>
    <h1>{{ __('messages.export_ui.kinds.statement_pdf') }}</h1>
    <p>{{ $company->name }} · {{ $employee->name }} <bdi class="mono">{{ $employee->employee_code }}</bdi></p>
    <p class="muted"><bdi class="mono">{{ $filters->from }} — {{ $filters->to }}</bdi></p>
    @foreach ($header as $line)
        <p class="muted">{{ $line[0] }}: <bdi dir="auto">{{ $line[1] }}</bdi></p>
    @endforeach
    <h2>{{ __('messages.export_ui.summary') }}</h2>
    <table><tbody>
        <tr><th>{{ __('messages.export_ui.assessed_days') }}</th><td class="mono">{{ $totals['assessed_working_days'] }}</td></tr>
        <tr><th>{{ __('messages.export_ui.present_days') }}</th><td class="mono">{{ $totals['days']['present'] }}</td></tr>
        <tr><th>{{ __('messages.export_ui.pending_days') }}</th><td class="mono">{{ $totals['days']['pending'] }}</td></tr>
        @foreach (['absence', 'late', 'early', 'exit'] as $kind)
            <tr><th>{{ __('messages.leave_ui.target_kinds.'.$kind) }}</th><td class="mono">{{ $totals['minutes'][$kind]['gross'] }} / {{ $totals['minutes'][$kind]['reconciled_by_leave'] }} / {{ $totals['minutes'][$kind]['reconciled_by_credit'] }} / {{ $totals['minutes'][$kind]['remaining'] }}</td></tr>
        @endforeach
        @foreach (['created', 'used_in_period', 'available_at_period_end'] as $kind)
            <tr><th>{{ __('messages.export_ui.credit_totals.'.$kind) }}</th><td class="mono">{{ $totals['credits'][$kind] }}</td></tr>
        @endforeach
    </tbody></table>
    <p class="muted">{{ __('messages.export_ui.minute_legend') }}</p>
    @if ($totals['leave'] !== [])
        <h2>{{ __('messages.export_ui.leave_summary') }}</h2>
        @foreach ($totals['leave'] as $group => $minutes)
            <p>{{ $group }}: <bdi class="mono">{{ $minutes }}</bdi> {{ __('messages.reporting_ui.minutes') }}</p>
        @endforeach
    @endif
    <h2>{{ __('messages.export_ui.daily_detail') }}</h2>
    <table>
        <thead><tr>
            @foreach (['date', 'status', 'first_in', 'last_out', 'gross', 'leave', 'credit', 'remaining'] as $column)
                <th>{{ __('messages.export_ui.columns.'.$column) }}</th>
            @endforeach
        </tr></thead>
        <tbody>
            @foreach ($days as $day)
                <tr><td class="mono">{{ $day['date'] }}</td><td>{{ $day['status'] }}</td>
                    <td class="mono">{{ $day['first_in'] }}</td><td class="mono">{{ $day['last_out'] }}</td>
                    <td class="mono">{{ $day['gross'] }}</td><td class="mono">{{ $day['leave'] }}</td>
                    <td class="mono">{{ $day['credit'] }}</td><td class="mono">{{ $day['remaining'] }}</td></tr>
                @if ($day['explanations'] !== '')
                    <tr><td colspan="8"><div class="note"><bdi dir="ltr">{{ $day['request_numbers'] }}</bdi> — {{ $day['explanations'] }} {{ __('messages.export_ui.linked_automatic') }}</div></td></tr>
                @endif
                @if ($day['absence'] > 0 && $day['remaining'] === 0 && $day['request_numbers'] !== '')
                    <tr><td colspan="8">{{ __('messages.export_ui.absence_closed', ['requests' => $day['request_numbers']]) }}</td></tr>
                @endif
                @if ($day['reviewed'])
                    <tr><td colspan="8">{{ __('messages.export_ui.linked_hr') }}</td></tr>
                @endif
            @endforeach
        </tbody>
    </table>
</body>
</html>

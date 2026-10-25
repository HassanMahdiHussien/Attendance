<!doctype html>
<html lang="{{ $language }}" dir="{{ $language === 'ar' ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><title>{{ __('messages.follow_up_mail.subject') }}</title></head>
<body style="font-family: Arial, sans-serif; line-height: 1.5; color: #17212b">
    <h1>{{ __('messages.follow_up_mail.heading') }}</h1>
    <p>{{ __('messages.follow_up_mail.intro') }}</p>
    <table style="border-collapse: collapse; width: 100%" cellpadding="8" border="1">
        <thead><tr>
            <th>{{ __('messages.follow_up_mail.employee') }}</th>
            <th>{{ __('messages.follow_up_mail.code') }}</th>
            <th>{{ __('messages.follow_up_mail.date') }}</th>
            <th>{{ __('messages.follow_up_mail.issue') }}</th>
            <th>{{ __('messages.follow_up_mail.minutes') }}</th>
            <th>{{ __('messages.follow_up_mail.action') }}</th>
        </tr></thead>
        <tbody>
        @foreach ($entries as $entry)
            @foreach ($entry['items'] as $item)
                <tr>
                    <td>{{ $entry['employee_name'] }}</td>
                    <td><bdi dir="ltr">{{ $entry['employee_code'] }}</bdi></td>
                    <td><bdi dir="ltr">{{ $entry['work_date'] }}</bdi></td>
                    <td>{{ __('messages.follow_up_mail.issue_types.'.$item['type']) }}</td>
                    <td><bdi dir="ltr">{{ $item['minutes'] === null ? '—' : sprintf('%d:%02d', intdiv($item['minutes'], 60), $item['minutes'] % 60) }}</bdi></td>
                    <td>{{ __('messages.follow_up_mail.actions.'.$entry['route']['kind']) }}</td>
                </tr>
            @endforeach
            @if ($entry['route']['kind'] === 'approver')
                <tr><td colspan="6">
                    {{ __('messages.follow_up_mail.form') }} <bdi dir="ltr">{{ $entry['form_number'] }}</bdi>
                    · <bdi dir="ltr">{{ $entry['request_from'] }} – {{ $entry['request_to'] }}</bdi>
                </td></tr>
            @endif
        @endforeach
        </tbody>
    </table>
    <p>{{ __('messages.follow_up_mail.footer') }}</p>
</body>
</html>

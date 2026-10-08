<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Displanery') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;900&family=Amiri:wght@400;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-bg font-tajawal text-ink">
    <header class="flex items-center justify-between border-b border-[#EEF0F4] bg-white px-4 py-3 sm:px-8">
        <div class="flex items-center gap-2"><span class="grid h-9 w-9 place-items-center rounded-xl bg-primary font-extrabold text-white" aria-hidden="true">D</span><strong>{{ __('Displanery') }}</strong></div>
        <form method="POST" action="{{ route('locale.update') }}">@csrf<input type="hidden" name="locale" value="{{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}"><button type="submit" class="ui-link inline-flex min-h-10 items-center gap-1 text-sm" lang="{{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}"><x-ui.icon name="globe" class="h-4 w-4" />{{ app()->getLocale() === 'ar' ? __('English') : trans('messages.locale.arabic', [], 'ar') }}</button></form>
    </header>
    <main class="mx-auto max-w-7xl px-4 py-8">{{ $slot }}</main>
</body>
</html>

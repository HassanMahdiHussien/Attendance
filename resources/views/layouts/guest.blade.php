<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Displanery') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@300;400;500;600;700&family=Amiri:wght@400;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-bg font-sans text-ink antialiased">
    <header class="bg-night text-white">
        <div class="mx-auto flex h-16 w-full max-w-7xl items-center justify-between px-4 sm:px-8">
            <a href="/" class="rounded-md focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-signal"><x-ui.brand /></a>
            <form method="POST" action="{{ route('locale.update') }}">@csrf<input type="hidden" name="locale" value="{{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}"><button type="submit" class="inline-flex min-h-10 items-center gap-1 text-sm text-night-soft hover:text-white" lang="{{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}"><x-ui.icon name="globe" class="h-4 w-4" />{{ app()->getLocale() === 'ar' ? __('English') : trans('messages.locale.arabic', [], 'ar') }}</button></form>
        </div>
    </header>
    <main class="mx-auto flex min-h-[calc(100vh-4rem)] max-w-7xl items-center justify-center px-4 py-8">{{ $slot }}</main>
</body>
</html>

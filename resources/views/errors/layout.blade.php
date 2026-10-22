<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('code') · @yield('heading') · {{ __('Attendance') }}</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    {{-- External font only: no app asset, database or session, so the page still renders when those fail. --}}
    <link href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@400;600;700&family=JetBrains+Mono:wght@700&display=swap" rel="stylesheet">
    <style>
        :root { --color-night: #0E1A2B; --color-signal: #F2B33D; --color-signal-dark: #DE9A1A; --color-night-soft: #A9B6C9; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #F2F4F7; color: #14181F; font-family: 'Readex Pro', sans-serif; }
        header { background: var(--color-night); color: white; }
        header > div { height: 64px; max-width: 1280px; margin: auto; padding: 0 32px; display: flex; align-items: center; }
        header a { color: inherit; text-decoration: none; }
        [data-brand-mark] { width: 32px; height: 32px; display: inline-grid; place-items: center; border-radius: 6px; color: var(--color-night); font: 700 16px ui-monospace, monospace; }
        header strong { font-size: 15px; }
        main { min-height: calc(100vh - 64px); display: grid; place-items: center; padding: 32px 16px; }
        article { width: min(100%, 480px); padding: 32px; background: white; border: 1px solid #DDE1E7; border-radius: 12px; text-align: center; }
        .code { color: #1D3A63; font: 700 32px 'JetBrains Mono', ui-monospace, monospace; }
        h1 { font-size: 24px; line-height: 1.3; }
        p { color: #14181F; line-height: 1.6; }
        .action { display: inline-block; margin-top: 12px; padding: 10px 16px; border-radius: 8px; background: var(--color-signal); color: var(--color-night); font-weight: 600; text-decoration: none; }
        button.action { border: 1px solid #DDE1E7; cursor: pointer; font: inherit; }
        .secondary { background: #FFFFFF; color: #14181F; }
    </style>
</head>
<body>
    <header><div><a href="/login"><x-ui.brand /></a><span style="margin-inline-start: auto;">@yield('localeSwitch')</span></div></header>
    <main><article>
        <div class="code">@yield('code')</div>
        <h1>@yield('heading')</h1>
        <p>@yield('message')</p>
        @yield('action')
    </article></main>
</body>
</html>

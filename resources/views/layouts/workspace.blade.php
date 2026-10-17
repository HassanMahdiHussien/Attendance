<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Displanery') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@300;400;500;600;700&family=Amiri:wght@400;700&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-bg font-sans text-ink antialiased">
    @php
        $nav = \App\Support\Access\Nav::items(auth()->user());
        $adminNav = \App\Support\Access\AdminNav::items(auth()->user());
        $settingsActive = collect($adminNav)->contains(fn ($item) => $item['active']);
        $home = \App\Support\Access\Landing::for(auth()->user());
        $otherLocale = app()->getLocale() === 'ar' ? 'en' : 'ar';
        $otherLocaleLabel = app()->getLocale() === 'ar' ? __('English') : trans('messages.locale.arabic', [], 'ar');
    @endphp
    <header class="sticky top-0 z-50 bg-night text-white">
        <div class="mx-auto flex h-16 w-full max-w-7xl items-center gap-4 px-4 sm:px-8">
            <a href="{{ $home }}" class="flex shrink-0 items-center gap-2.5 rounded-md focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-signal">
                <x-ui.brand :subtitle="auth()->user()->company?->name ?? auth()->user()->name" />
            </a>
            <nav class="ms-2 hidden shrink-0 items-center xl:flex" aria-label="{{ __('Main navigation') }}">
                @foreach($nav as $item)
                    @php $active = request()->is($item['pattern']); @endphp
                    <a href="{{ $item['url'] }}" @if($active) aria-current="page" @endif class="relative flex h-16 items-center gap-2 whitespace-nowrap px-2.5 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-[-6px] focus-visible:outline-signal {{ $active ? 'text-signal' : 'text-night-soft hover:text-white' }}">
                        {{ __($item['label']) }}
                        @if($item['count'])<span class="rounded bg-night-line px-1.5 py-px font-mono text-[0.7rem] text-white">{{ \App\Support\Format\Ui::number($item['count']) }}</span>@endif
                        @if($item['dot'])<span class="h-1.5 w-1.5 rounded-full bg-signal" aria-label="{{ __('Pending') }}"></span>@endif
                        @if($active)<span class="absolute inset-x-3 bottom-0 h-[3px] rounded-t bg-signal" aria-hidden="true"></span>@endif
                    </a>
                @endforeach
                @if($adminNav)
                    <div class="relative" x-data="{ settingsOpen: false }" @keydown.escape.window="settingsOpen = false" @click.outside="settingsOpen = false">
                        <button type="button" @click="settingsOpen = !settingsOpen" :aria-expanded="settingsOpen.toString()" aria-controls="workspace-settings" class="relative flex h-16 items-center gap-1.5 whitespace-nowrap px-2.5 text-sm font-medium focus-visible:outline-2 focus-visible:outline-offset-[-6px] focus-visible:outline-signal {{ $settingsActive ? 'text-signal' : 'text-night-soft hover:text-white' }}">
                            {{ __('Settings') }}<x-ui.icon name="chevron" class="h-4 w-4 rotate-90" />
                            @if($settingsActive)<span class="absolute inset-x-3 bottom-0 h-[3px] rounded-t bg-signal" aria-hidden="true"></span>@endif
                        </button>
                        <div id="workspace-settings" x-show="settingsOpen" x-cloak x-transition.opacity.duration.150ms class="absolute top-full mt-1 w-64 rounded-xl border border-line bg-white p-1.5 text-ink shadow-[0_12px_32px_-8px_rgb(14_26_43/0.28)] start-0">
                            @foreach($adminNav as $item)
                                <a href="{{ $item['url'] }}" @if($item['active']) aria-current="page" @endif class="flex min-h-10 items-center gap-3 rounded-lg px-3 text-sm focus-visible:outline-2 focus-visible:outline-primary {{ $item['active'] ? 'bg-primary-light font-semibold text-primary' : 'hover:bg-gray-50' }}"><x-ui.icon :name="$item['icon']" class="h-4 w-4 text-gray-500" />{{ $item['label'] }}</a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </nav>
            <div class="ms-auto flex min-w-0 items-center gap-3">
                @if(app(\App\Services\Search\GlobalSearch::class)->allowed(auth()->user()))<livewire:global-search />@endif
                <div id="workspace-account" class="relative" x-data="{ accountOpen: false }" @keydown.escape.window="accountOpen = false" @click.outside="accountOpen = false">
                    <button type="button" @click="accountOpen = !accountOpen" :aria-expanded="accountOpen.toString()" aria-controls="workspace-account-menu" aria-label="{{ __('Account') }}" class="grid h-9 w-9 place-items-center rounded-full bg-night-line text-xs font-semibold text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-signal">{{ \App\Support\Format\Initials::for(auth()->user()->name) }}</button>
                    <div id="workspace-account-menu" x-show="accountOpen" x-cloak x-transition.opacity.duration.150ms class="absolute top-full mt-2 w-64 rounded-xl border border-line bg-white p-2 text-ink shadow-[0_12px_32px_-8px_rgb(14_26_43/0.28)] end-0">
                        <div class="px-3 py-2"><strong class="block truncate text-sm">{{ auth()->user()->name }}</strong><span class="block truncate text-xs text-gray-500">{{ auth()->user()->company?->name ?? auth()->user()->email }}</span></div>
                        <div class="my-1 h-px bg-line"></div>
                        <a href="{{ route('help.index') }}" class="flex min-h-10 items-center gap-2 rounded-lg px-3 text-sm hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-primary"><x-ui.icon name="help" class="h-4 w-4 text-gray-500" />{{ __('Help') }}</a>
                        <form method="POST" action="{{ route('locale.update') }}">@csrf<input type="hidden" name="locale" value="{{ $otherLocale }}"><button type="submit" class="flex min-h-10 w-full items-center gap-2 rounded-lg px-3 text-start text-sm hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-primary" lang="{{ $otherLocale }}"><x-ui.icon name="globe" class="h-4 w-4 text-gray-500" />{{ $otherLocaleLabel }}</button></form>
                        <form method="POST" action="/logout">@csrf<button type="submit" class="flex min-h-10 w-full items-center gap-2 rounded-lg px-3 text-start text-sm hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-primary"><x-ui.icon name="logout" class="h-4 w-4 text-gray-500" />{{ __('Log out') }}</button></form>
                    </div>
                </div>
            </div>
        </div>
    </header>
    <main class="mx-auto w-full max-w-7xl min-w-0 px-4 pb-28 pt-6 sm:px-8 xl:pb-10">{{ $slot }}</main>
    <div class="xl:hidden" x-data="{ open: false, returnFocus() { this.open = false; this.$nextTick(() => this.$refs.moreTrigger.focus()) }, trap(event) { if (!this.open || event.key !== 'Tab') return; let nodes = [...this.$refs.sheet.querySelectorAll('a, button')]; let first = nodes[0], last = nodes[nodes.length - 1]; if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus() } else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus() } } }" @keydown.escape.window="if (open) returnFocus()" @keydown.window="trap($event)">
        <nav class="fixed inset-x-0 bottom-0 z-40 grid grid-cols-4 border-t border-line bg-white pb-[env(safe-area-inset-bottom)] text-center shadow-[0_-4px_16px_-8px_rgb(14_26_43/0.18)]" aria-label="{{ __('Main navigation') }}">
            @foreach(array_slice($nav, 0, 3) as $item)
                @php $active = request()->is($item['pattern']); @endphp
                <a href="{{ $item['url'] }}" @if($active) aria-current="page" @endif class="relative flex min-h-16 flex-col items-center justify-center gap-1 px-1 text-xs font-medium focus-visible:outline-2 focus-visible:outline-primary {{ $active ? 'text-night' : 'text-gray-500' }}">@if($active)<span class="absolute inset-x-6 top-0 h-[3px] rounded-b bg-signal" aria-hidden="true"></span>@endif<x-ui.icon :name="$item['icon']" class="h-5 w-5" /><span class="block max-w-full truncate">{{ __($item['label']) }}</span>@if($item['count'] || $item['dot'])<span class="absolute end-4 top-2 h-2 w-2 rounded-full bg-signal"></span>@endif</a>
            @endforeach
            <button x-ref="moreTrigger" type="button" @click="open = true; $nextTick(() => $refs.sheet.querySelector('a, button').focus())" :aria-expanded="open.toString()" aria-controls="workspace-more" class="col-start-4 flex min-h-16 flex-col items-center justify-center gap-1 text-xs font-medium text-gray-500 focus-visible:outline-2 focus-visible:outline-primary"><x-ui.icon name="menu" class="h-5 w-5" />{{ __('More') }}</button>
        </nav>
        <div x-show="open" x-cloak class="fixed inset-0 z-50" role="presentation">
            <div class="absolute inset-0 bg-night/50" @click="returnFocus()"></div>
            <section id="workspace-more" x-ref="sheet" role="dialog" aria-modal="true" aria-label="{{ __('More') }}" class="absolute inset-x-0 bottom-0 max-h-[80vh] overflow-y-auto rounded-t-2xl bg-white p-5 pb-[calc(1.25rem+env(safe-area-inset-bottom))] shadow-2xl">
                <div class="mb-4 flex items-center justify-between"><strong>{{ __('More') }}</strong><button type="button" @click="returnFocus()" aria-label="{{ __('Close') }}" class="grid h-10 w-10 place-items-center rounded-lg focus-visible:outline-2 focus-visible:outline-primary"><x-ui.icon name="x" /></button></div>
                @foreach(array_slice($nav, 3) as $item)
                    <a href="{{ $item['url'] }}" @if(request()->is($item['pattern'])) aria-current="page" @endif class="flex min-h-12 items-center gap-3 rounded-lg px-3 text-sm font-medium focus-visible:outline-2 focus-visible:outline-primary {{ request()->is($item['pattern']) ? 'bg-primary-light text-primary' : 'text-gray-700' }}"><x-ui.icon :name="$item['icon']" />{{ __($item['label']) }}</a>
                @endforeach
                @if($adminNav)
                    <div class="mt-3 border-t border-line pt-3">
                        <strong class="block px-3 py-2 text-sm">{{ __('Settings') }}</strong>
                        @foreach($adminNav as $item)
                            <a href="{{ $item['url'] }}" @if($item['active']) aria-current="page" @endif class="flex min-h-12 items-center gap-3 rounded-lg px-3 text-sm font-medium focus-visible:outline-2 focus-visible:outline-primary {{ $item['active'] ? 'bg-primary-light text-primary' : 'text-gray-700' }}"><x-ui.icon :name="$item['icon']" />{{ $item['label'] }}</a>
                        @endforeach
                    </div>
                @endif
                <div class="mt-4 border-t border-line pt-3 text-sm font-semibold">{{ auth()->user()->name }}</div>
                <a href="{{ route('help.index') }}" class="mt-2 flex min-h-12 items-center gap-3 rounded-lg px-3 text-sm font-medium text-gray-700 hover:bg-gray-50"><x-ui.icon name="help" />{{ __('Help') }}</a>
                <form method="POST" action="{{ route('locale.update') }}">@csrf<input type="hidden" name="locale" value="{{ $otherLocale }}"><button type="submit" class="mt-2 inline-flex min-h-10 items-center gap-2 text-sm text-gray-700" lang="{{ $otherLocale }}"><x-ui.icon name="globe" class="h-4 w-4" />{{ $otherLocaleLabel }}</button></form>
                <form method="POST" action="/logout">@csrf<button type="submit" class="inline-flex min-h-10 items-center gap-2 text-sm text-gray-700"><x-ui.icon name="logout" class="h-4 w-4" />{{ __('Log out') }}</button></form>
            </section>
        </div>
    </div>
    <x-ui.toast />
    <x-ui.file-viewer />
</body>
</html>

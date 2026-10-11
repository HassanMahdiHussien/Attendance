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
    @php($nav = \App\Support\Access\Nav::items(auth()->user()))
    @php($adminNav = \App\Support\Access\AdminNav::items(auth()->user()))
    @php($settingsActive = collect($adminNav)->contains(fn ($item) => $item['active']))
    <div class="flex min-h-screen w-full max-w-full overflow-x-clip">
        <aside class="sticky top-0 hidden h-screen w-64 shrink-0 flex-col border-e border-[#EEF0F4] bg-white p-4 lg:flex" aria-label="{{ __('Main navigation') }}">
            <a href="{{ \App\Support\Access\Landing::for(auth()->user()) }}" class="mb-6 flex items-center gap-2 rounded-xl px-2 pt-1 focus-visible:outline-2 focus-visible:outline-primary">
                <span class="grid h-9 w-9 place-items-center rounded-xl bg-primary font-extrabold text-white" aria-hidden="true">D</span>
                <span class="min-w-0"><strong class="block leading-tight">{{ __('Displanery') }}</strong><span class="block truncate text-xs text-gray-500">{{ auth()->user()->company?->name ?? auth()->user()->name }}</span></span>
            </a>
            <nav class="space-y-1 overflow-y-auto" aria-label="{{ __('Main navigation') }}" x-data="{ settingsOpen: {{ $settingsActive ? 'true' : 'false' }}, init() { if (!this.settingsOpen) { try { this.settingsOpen = localStorage.getItem('workspace-settings-open') === 'true' } catch (e) {} } }, toggleSettings() { this.settingsOpen = !this.settingsOpen; try { localStorage.setItem('workspace-settings-open', this.settingsOpen ? 'true' : 'false') } catch (e) {} } }">
                @foreach($nav as $item)
                    @php($active = request()->is($item['pattern']))
                    <a href="{{ $item['url'] }}" @if($active) aria-current="page" @endif class="flex min-h-10 items-center gap-3 rounded-xl px-3 py-2 text-sm font-bold focus-visible:outline-2 focus-visible:outline-primary {{ $active ? 'bg-primary-light text-primary' : 'text-gray-600 hover:bg-gray-100' }}">
                        <x-ui.icon :name="$item['icon']" class="h-5 w-5" />
                        <span class="min-w-0 flex-1">{{ __($item['label']) }}</span>
                        @if($item['count'])<x-ui.badge tone="indigo">{{ \App\Support\Format\Ui::number($item['count']) }}</x-ui.badge>@endif
                        @if($item['dot'])<span class="h-2 w-2 rounded-full bg-primary" aria-label="{{ __('Pending') }}"></span>@endif
                    </a>
                @endforeach
                @if($adminNav)
                    <div class="pt-2">
                        <button type="button" @click="toggleSettings()" :aria-expanded="settingsOpen.toString()" aria-controls="workspace-settings" class="flex min-h-10 w-full items-center gap-3 rounded-xl px-3 py-2 text-start text-sm font-bold focus-visible:outline-2 focus-visible:outline-primary {{ $settingsActive ? 'bg-primary-light text-primary' : 'text-gray-600 hover:bg-gray-100' }}">
                            <x-ui.icon name="settings" class="h-5 w-5" /><span class="flex-1">{{ __('Settings') }}</span><x-ui.icon name="chevron" class="h-4 w-4 transition-transform rtl:rotate-180" x-bind:class="settingsOpen ? 'rotate-90' : ''" />
                        </button>
                        <div id="workspace-settings" x-show="settingsOpen" x-cloak class="space-y-1 ps-5">
                            @foreach($adminNav as $item)
                                <a href="{{ $item['url'] }}" @if($item['active']) aria-current="page" @endif class="flex min-h-10 items-center gap-3 rounded-xl px-3 py-2 text-sm font-bold focus-visible:outline-2 focus-visible:outline-primary {{ $item['active'] ? 'bg-primary-light text-primary' : 'text-gray-600 hover:bg-gray-100' }}"><x-ui.icon :name="$item['icon']" class="h-5 w-5" />{{ $item['label'] }}</a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </nav>
            <div class="mt-auto space-y-3">
                <div class="ui-card flex items-center gap-3 p-3">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-primary-light text-sm font-bold text-primary">{{ \App\Support\Format\Initials::for(auth()->user()->name) }}</span>
                    <span class="min-w-0"><strong class="block truncate text-sm">{{ auth()->user()->name }}</strong><span class="block truncate text-xs text-gray-500">{{ auth()->user()->company?->name }}</span></span>
                </div>
                <div class="flex items-center justify-between gap-2">
                    <form method="POST" action="{{ route('locale.update') }}">@csrf<input type="hidden" name="locale" value="{{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}"><button type="submit" class="ui-link inline-flex min-h-10 items-center gap-1 text-sm" lang="{{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}"><x-ui.icon name="globe" class="h-4 w-4" />{{ app()->getLocale() === 'ar' ? __('English') : trans('messages.locale.arabic', [], 'ar') }}</button></form>
                    <form method="POST" action="/logout">@csrf<button type="submit" class="ui-link inline-flex min-h-10 items-center gap-1 text-sm"><x-ui.icon name="logout" class="h-4 w-4" />{{ __('Log out') }}</button></form>
                </div>
            </div>
        </aside>
        <div class="min-w-0 max-w-full flex-1">
            <header class="sticky top-0 z-50 flex min-h-14 items-center justify-between gap-3 border-b border-[#EEF0F4] bg-bg/95 px-4 sm:px-8">
                <a href="{{ \App\Support\Access\Landing::for(auth()->user()) }}" class="flex items-center gap-2 font-extrabold lg:hidden"><span class="grid h-7 w-7 place-items-center rounded-lg bg-primary text-sm text-white" aria-hidden="true">D</span>{{ __('Displanery') }}</a>
                <span class="hidden text-sm font-bold text-gray-600 lg:block">{{ auth()->user()->company?->name ?? auth()->user()->name }}</span>
                @if(app(\App\Services\Search\GlobalSearch::class)->allowed(auth()->user()))<livewire:global-search />@endif
                <form method="POST" action="{{ route('locale.update') }}" class="ms-auto lg:hidden">@csrf<input type="hidden" name="locale" value="{{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}"><button type="submit" class="ui-link inline-flex min-h-10 items-center gap-1 text-sm" lang="{{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}"><x-ui.icon name="globe" class="h-4 w-4" />{{ app()->getLocale() === 'ar' ? __('English') : trans('messages.locale.arabic', [], 'ar') }}</button></form>
            </header>
            <main class="mx-auto w-full max-w-7xl min-w-0 px-4 pb-28 pt-6 sm:px-8 lg:pb-8">{{ $slot }}</main>
        </div>
    </div>
    <div class="lg:hidden" x-data="{ open: false, returnFocus() { this.open = false; this.$nextTick(() => this.$refs.moreTrigger.focus()) }, trap(event) { if (!this.open || event.key !== 'Tab') return; let nodes = [...this.$refs.sheet.querySelectorAll('a, button')]; let first = nodes[0], last = nodes[nodes.length - 1]; if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus() } else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus() } } }" @keydown.escape.window="if (open) returnFocus()" @keydown.window="trap($event)">
        <nav class="fixed inset-x-0 bottom-0 z-40 grid grid-cols-4 border-t border-[#EEF0F4] bg-white pb-[env(safe-area-inset-bottom)] text-center shadow-lg" aria-label="{{ __('Main navigation') }}">
            @foreach(array_slice($nav, 0, 3) as $item)
                @php($active = request()->is($item['pattern']))
                <a href="{{ $item['url'] }}" @if($active) aria-current="page" @endif class="relative flex min-h-16 flex-col items-center justify-center gap-1 px-1 text-xs font-bold focus-visible:outline-2 focus-visible:outline-primary {{ $active ? 'text-primary' : 'text-gray-600' }}"><x-ui.icon :name="$item['icon']" class="h-5 w-5" /><span class="block max-w-full truncate">{{ __($item['label']) }}</span>@if($item['count'] || $item['dot'])<span class="absolute end-4 top-2 h-2 w-2 rounded-full bg-primary"></span>@endif</a>
            @endforeach
            <button x-ref="moreTrigger" type="button" @click="open = true; $nextTick(() => $refs.sheet.querySelector('a, button').focus())" :aria-expanded="open.toString()" aria-controls="workspace-more" class="col-start-4 flex min-h-16 flex-col items-center justify-center gap-1 text-xs font-bold text-gray-600 focus-visible:outline-2 focus-visible:outline-primary"><x-ui.icon name="menu" class="h-5 w-5" />{{ __('More') }}</button>
        </nav>
        <div x-show="open" x-cloak class="fixed inset-0 z-50" role="presentation">
            <div class="absolute inset-0 bg-ink/40" @click="returnFocus()"></div>
            <section id="workspace-more" x-ref="sheet" role="dialog" aria-modal="true" aria-label="{{ __('More') }}" class="absolute inset-x-0 bottom-0 max-h-[80vh] overflow-y-auto rounded-t-3xl bg-white p-5 pb-[calc(1.25rem+env(safe-area-inset-bottom))] shadow-2xl">
                <div class="mb-4 flex items-center justify-between"><strong>{{ __('More') }}</strong><button type="button" @click="returnFocus()" aria-label="{{ __('Close') }}" class="grid h-10 w-10 place-items-center rounded-xl focus-visible:outline-2 focus-visible:outline-primary"><x-ui.icon name="x" /></button></div>
                @foreach(array_slice($nav, 3) as $item)
                    <a href="{{ $item['url'] }}" @if(request()->is($item['pattern'])) aria-current="page" @endif class="flex min-h-12 items-center gap-3 rounded-xl px-3 text-sm font-bold focus-visible:outline-2 focus-visible:outline-primary {{ request()->is($item['pattern']) ? 'bg-primary-light text-primary' : 'text-gray-600' }}"><x-ui.icon :name="$item['icon']" />{{ __($item['label']) }}</a>
                @endforeach
                @if($adminNav)
                    <div class="mt-3 border-t border-gray-200 pt-3">
                        <strong class="block px-3 py-2 text-sm">{{ __('Settings') }}</strong>
                        @foreach($adminNav as $item)
                            <a href="{{ $item['url'] }}" @if($item['active']) aria-current="page" @endif class="flex min-h-12 items-center gap-3 rounded-xl px-3 text-sm font-bold focus-visible:outline-2 focus-visible:outline-primary {{ $item['active'] ? 'bg-primary-light text-primary' : 'text-gray-600' }}"><x-ui.icon :name="$item['icon']" />{{ $item['label'] }}</a>
                        @endforeach
                    </div>
                @endif
                <div class="mt-4 border-t border-[#EEF0F4] pt-3 text-sm font-bold">{{ auth()->user()->name }}</div>
                <form method="POST" action="/logout">@csrf<button type="submit" class="mt-2 inline-flex min-h-10 items-center gap-2 text-sm text-gray-600"><x-ui.icon name="logout" class="h-4 w-4" />{{ __('Log out') }}</button></form>
            </section>
        </div>
    </div>
    <x-ui.toast />
    <x-ui.file-viewer />
</body>
</html>

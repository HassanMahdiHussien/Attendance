<div class="relative ms-auto" x-data="{
    open: false, mobile: false, active: -1,
    links() { return [...this.$refs.results.querySelectorAll('[role=option]')] },
    queryLength() { return $wire.query.trim().length >= 2 },
    focusSearch(onPhone = false) { this.mobile = onPhone; this.open = true; this.active = -1; this.$nextTick(() => this.$refs.input.focus()) },
    close() { let wasMobile = this.mobile; this.open = false; this.mobile = false; this.active = -1; if (wasMobile) this.$nextTick(() => this.$refs.trigger.focus()) },
    move(delta) { let rows = this.links(); if (!rows.length) return; this.active = (this.active + delta + rows.length) % rows.length; rows[this.active].scrollIntoView({ block: 'nearest' }) },
    choose() { let row = this.links()[this.active]; if (row) row.click() },
    trap(event) { if (!this.mobile || event.key !== 'Tab') return; let nodes = [...this.$refs.sheet.querySelectorAll('input, button, a:not([hidden])')].filter(node => node.offsetParent !== null); let first = nodes[0], last = nodes[nodes.length - 1]; if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus() } else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus() } }
}" @keydown.window="if (($event.ctrlKey || $event.metaKey) && $event.key.toLowerCase() === 'k') { $event.preventDefault(); focusSearch(window.innerWidth < 640) } else if ($event.key === 'Escape' && open) { close() } else { trap($event) }" @click.outside="if (!mobile) close()">
    <button x-ref="trigger" type="button" class="grid h-10 w-10 place-items-center rounded-xl text-gray-600 sm:hidden" aria-label="{{ __('messages.search.open_search') }}" @click="focusSearch(true)"><x-ui.icon name="search" class="h-5 w-5" /></button>
    <div x-ref="sheet" :class="mobile ? 'fixed inset-0 z-50 bg-white p-4' : 'hidden sm:block sm:w-56 xl:w-72'" :role="mobile ? 'dialog' : null" :aria-modal="mobile ? 'true' : null" aria-label="{{ __('messages.search.open_search') }}" x-show="!mobile || open" x-cloak>
        <div class="flex items-center gap-2">
            <input x-ref="input" type="search" wire:model.live.debounce.250ms="query" role="combobox" aria-autocomplete="list" aria-controls="global-search-results" :aria-expanded="open.toString()" :aria-activedescendant="active >= 0 ? 'search-result-' + active : null" aria-label="{{ __('messages.search.open_search') }}" placeholder="{{ __('messages.search.placeholder') }}" class="ui-control w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm" @focus="open = true" @input="open = true; active = -1" @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.enter.prevent="choose()" @keydown.escape.stop="close()">
            <span class="hidden whitespace-nowrap text-xs text-gray-500 lg:block">{{ __('messages.search.shortcut') }}</span>
            <button x-show="mobile" type="button" @click="close()" aria-label="{{ __('Close') }}" class="grid h-10 w-10 shrink-0 place-items-center"><x-ui.icon name="x" /></button>
        </div>
        <div id="global-search-results" x-ref="results" role="listbox" x-show="open && queryLength()" class="mt-2 max-h-[70vh] overflow-y-auto rounded-xl border border-gray-200 bg-white shadow-xl sm:absolute sm:inset-x-0 sm:z-50">
            @php($resultIndex = 0)
            @foreach(['cases' => __('messages.search.cases'), 'employees' => __('messages.search.employees'), 'reports' => __('messages.search.reports')] as $group => $heading)
                @if(count($groups[$group]))
                    <div role="group" aria-label="{{ $heading }}">
                        <p class="px-3 pb-1 pt-3 text-xs font-black text-gray-500">{{ $heading }}</p>
                        @foreach($groups[$group] as $result)
                            <a id="search-result-{{ $resultIndex++ }}" role="option" :aria-selected="active === {{ $resultIndex - 1 }}" href="{{ $result['url'] }}" class="block px-3 py-2 text-sm hover:bg-primary-light focus:bg-primary-light" @mouseenter="active = {{ $resultIndex - 1 }}"><strong class="block">{{ $result['label'] }}</strong><span class="text-xs text-gray-500">{{ $result['detail'] }}</span></a>
                        @endforeach
                    </div>
                @endif
            @endforeach
            @if($resultIndex === 0)<p class="p-4 text-sm text-gray-500">{{ __('messages.search.no_results') }}</p>@endif
        </div>
    </div>
</div>

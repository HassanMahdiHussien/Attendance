<div x-data="{
    open: false, items: [], index: 0, previousFocus: null,
    get current() { return this.items[this.index] || null },
    show(detail) {
        let items = detail.items.filter(item => item.view_url);
        let chosen = detail.items[detail.index];
        let index = items.indexOf(chosen);
        if (index < 0) return;
        if (chosen.kind !== 'image' && window.matchMedia('(max-width: 767px)').matches) {
            window.open(chosen.view_url, '_blank', 'noopener');
            return;
        }
        this.previousFocus = document.activeElement;
        this.items = items;
        this.index = index;
        this.open = true;
        document.body.style.overflow = 'hidden';
        this.$nextTick(() => this.$refs.close.focus());
    },
    close() {
        this.open = false;
        document.body.style.overflow = '';
        this.$nextTick(() => this.previousFocus?.focus());
    },
    move(delta) { if (this.items.length > 1) this.index = (this.index + delta + this.items.length) % this.items.length },
    keydown(event) {
        if (!this.open) return;
        if (event.key === 'Escape') { event.preventDefault(); this.close(); return }
        if (event.key === 'ArrowLeft') { event.preventDefault(); this.move(document.documentElement.dir === 'rtl' ? 1 : -1); return }
        if (event.key === 'ArrowRight') { event.preventDefault(); this.move(document.documentElement.dir === 'rtl' ? -1 : 1); return }
        if (event.key !== 'Tab') return;
        let controls = [...this.$refs.dialog.querySelectorAll('button, a[href]')].filter(node => !node.disabled && node.getClientRects().length);
        let first = controls[0], last = controls[controls.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus() }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus() }
    }
}" x-on:open-file.window="show($event.detail)" x-on:keydown.window="keydown($event)" x-on:livewire:navigating.window="if (open) close()">
    <div x-show="open" x-cloak class="fixed inset-0 z-[100] flex items-center justify-center bg-ink/80 p-2 sm:p-6" x-on:click.self="close()">
        <section x-ref="dialog" role="dialog" aria-modal="true" aria-label="{{ __('File viewer') }}" class="flex h-full max-h-[95vh] w-full max-w-6xl flex-col rounded-2xl bg-white p-3 shadow-2xl sm:p-5">
            <header class="mb-3 flex flex-wrap items-center gap-2">
                <strong class="min-w-0 flex-1 truncate" x-text="current?.name"></strong>
                <template x-if="current?.download_url"><x-ui.button variant="secondary" size="small" href="#" x-bind:href="current.download_url">{{ __('Download') }}</x-ui.button></template>
                <x-ui.button x-ref="close" variant="ghost" size="small" x-on:click="close()">{{ __('Close') }}</x-ui.button>
            </header>
            <div class="min-h-0 flex-1 overflow-auto rounded-xl bg-gray-100">
                <template x-if="current?.kind === 'image'"><img x-bind:src="current.view_url" x-bind:alt="current.name" class="mx-auto max-h-full max-w-full object-contain"></template>
                <template x-if="current?.kind === 'pdf'"><iframe x-bind:src="current.view_url" x-bind:title="current.name" class="h-full min-h-[60vh] w-full bg-white"></iframe></template>
                <template x-if="current?.kind === 'html'"><iframe x-bind:src="current.view_url" x-bind:title="current.name" sandbox="" class="h-full min-h-[60vh] w-full bg-white"></iframe></template>
            </div>
            <footer class="mt-3 flex items-center justify-between gap-3" x-show="items.length > 1">
                <x-ui.button variant="secondary" size="small" x-on:click="move(-1)">{{ __('Previous') }}</x-ui.button>
                <span class="text-sm text-gray-500" x-text="(index + 1) + ' / ' + items.length"></span>
                <x-ui.button variant="secondary" size="small" x-on:click="move(1)">{{ __('Next') }}</x-ui.button>
            </footer>
        </section>
    </div>
</div>

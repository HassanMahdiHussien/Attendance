<div class="space-y-5">
    <div class="rounded-xl border p-4 dark:border-gray-700">
        <h2 class="font-bold">{{ __('Units needing review') }}</h2>
        @forelse ($this->mismatches() as $unit)
            <button type="button" wire:click="selectUnit({{ $unit->id }})" class="block w-full border-b p-3 text-start hover:bg-gray-50 dark:hover:bg-gray-800">
                {{ $unit->law->name }} — {{ __('Article') }} {{ $unit->article_number }} {{ $unit->clause_path }}
                <span class="text-sm text-amber-700">{{ \App\Support\Format\LegalLibraryLabels::mismatchReason($unit->tags['mismatch_reason'] ?? '') }}</span>
            </button>
        @empty
            <p class="p-3">{{ __('No mismatches.') }}</p>
        @endforelse
    </div>

    @if ($unit = $this->selectedUnit())
        @php
            $page = $unit->importRun?->pages?->firstWhere('page_number', $unit->source_page);
        @endphp
        <div class="grid gap-4 lg:grid-cols-3">
            <section class="rounded-xl border p-4 dark:border-gray-700">
                <h3 class="font-bold">{{ __('Reading A') }} — {{ \App\Support\Format\LegalLibraryLabels::textMethod($page?->text_method ?? 'text') }}</h3>
                <p dir="rtl" class="mt-3 whitespace-pre-wrap text-xl leading-9" style="font-family:Amiri,serif">{{ $unit->extraction_a }}</p>
            </section>
            <section class="rounded-xl border p-4 dark:border-gray-700">
                <h3 class="font-bold">{{ __('Reading B') }} — AI</h3>
                <p dir="rtl" class="mt-3 whitespace-pre-wrap text-xl leading-9" style="font-family:Amiri,serif">{{ $unit->extraction_b }}</p>
                <div class="mt-4" style="font-family:Amiri,serif">
                    @foreach ($this->diff() as [$kind, $word])
                        <span class="{{ $kind === 'removed' ? 'bg-red-200 line-through' : ($kind === 'added' ? 'bg-green-200' : '') }}">{{ $word }}</span>
                    @endforeach
                </div>
            </section>
            <section class="rounded-xl border p-4 dark:border-gray-700">
                <h3 class="font-bold">{{ __('Source') }}</h3>
                @if ($page)
                    <a target="_blank" href="{{ route('legal-library.pages.show', ['run' => $page->run_id, 'page' => $page->page_number]) }}">
                        <img class="mt-3 max-h-[36rem] object-contain" src="{{ route('legal-library.pages.show', ['run' => $page->run_id, 'page' => $page->page_number]) }}" alt="{{ __('Page image') }} {{ $page->page_number }}">
                    </a>
                @else
                    <p dir="rtl" class="mt-3 whitespace-pre-wrap" style="font-family:Amiri,serif">{{ $unit->extraction_a ?: $unit->official_text }}</p>
                @endif
            </section>
        </div>
        <div class="flex flex-wrap gap-3">
            <x-filament::button wire:click="resolveUnit('a')">{{ __('Approve reading A') }}</x-filament::button>
            <x-filament::button wire:click="resolveUnit('b')">{{ __('Approve reading B') }}</x-filament::button>
        </div>
        <div class="rounded-xl border p-4 dark:border-gray-700">
            <label class="block font-bold" for="edited-text">{{ __('Correct text') }}</label>
            <textarea id="edited-text" wire:model="editedText" rows="6" class="mt-2 w-full rounded border p-3 text-xl" style="font-family:Amiri,serif" dir="rtl"></textarea>
            <x-filament::button wire:click="resolveUnit('edit')">{{ __('Approve correction') }}</x-filament::button>
        </div>
        <div class="rounded-xl border p-4 dark:border-gray-700">
            <label class="block font-bold" for="exclude-reason">{{ __('Exclusion reason') }}</label>
            <textarea id="exclude-reason" wire:model="excludeReason" rows="2" class="mt-2 w-full rounded border p-3"></textarea>
            <x-filament::button color="danger" wire:click="excludeUnit">{{ __('Exclude') }}</x-filament::button>
        </div>
    @endif
</div>

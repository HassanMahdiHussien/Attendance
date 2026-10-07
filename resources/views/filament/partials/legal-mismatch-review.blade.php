<div dir="rtl" class="space-y-5">
    <div class="rounded-xl border p-4 dark:border-gray-700">
        <h2 class="font-bold">الوحدات التي تحتاج مراجعة</h2>
        @forelse ($this->mismatches() as $unit)
            <button type="button" wire:click="selectUnit({{ $unit->id }})" class="block w-full border-b p-3 text-right hover:bg-gray-50 dark:hover:bg-gray-800">
                {{ $unit->law->name }} — المادة {{ $unit->article_number }} {{ $unit->clause_path }}
                <span class="text-sm text-amber-700">{{ $unit->tags['mismatch_reason'] ?? 'اختلاف القراءتين' }}</span>
            </button>
        @empty
            <p class="p-3">لا توجد اختلافات.</p>
        @endforelse
    </div>

    @if ($unit = $this->selectedUnit())
        @php
            $page = $unit->importRun?->pages?->firstWhere('page_number', $unit->source_page);
        @endphp
        <div class="grid gap-4 lg:grid-cols-3">
            <section class="rounded-xl border p-4 dark:border-gray-700">
                <h3 class="font-bold">القراءة أ — {{ $page?->text_method === 'ocr' ? 'OCR' : 'طبقة النص' }}</h3>
                <p class="mt-3 whitespace-pre-wrap text-xl leading-9" style="font-family:Amiri,serif">{{ $unit->extraction_a }}</p>
            </section>
            <section class="rounded-xl border p-4 dark:border-gray-700">
                <h3 class="font-bold">القراءة ب — AI</h3>
                <p class="mt-3 whitespace-pre-wrap text-xl leading-9" style="font-family:Amiri,serif">{{ $unit->extraction_b }}</p>
                <div class="mt-4" style="font-family:Amiri,serif">
                    @foreach ($this->diff() as [$kind, $word])
                        <span class="{{ $kind === 'removed' ? 'bg-red-200 line-through' : ($kind === 'added' ? 'bg-green-200' : '') }}">{{ $word }}</span>
                    @endforeach
                </div>
            </section>
            <section class="rounded-xl border p-4 dark:border-gray-700">
                <h3 class="font-bold">المصدر</h3>
                @if ($page)
                    <a target="_blank" href="{{ route('legal-library.pages.show', ['run' => $page->run_id, 'page' => $page->page_number]) }}">
                        <img class="mt-3 max-h-[36rem] object-contain" src="{{ route('legal-library.pages.show', ['run' => $page->run_id, 'page' => $page->page_number]) }}" alt="صورة الصفحة {{ $page->page_number }}">
                    </a>
                @else
                    <p class="mt-3 whitespace-pre-wrap" style="font-family:Amiri,serif">{{ $unit->extraction_a ?: $unit->official_text }}</p>
                @endif
            </section>
        </div>
        <div class="flex flex-wrap gap-3">
            <x-filament::button wire:click="resolveUnit('a')">اعتماد القراءة أ</x-filament::button>
            <x-filament::button wire:click="resolveUnit('b')">اعتماد القراءة ب</x-filament::button>
        </div>
        <div class="rounded-xl border p-4 dark:border-gray-700">
            <label class="block font-bold" for="edited-text">تصحيح النص</label>
            <textarea id="edited-text" wire:model="editedText" rows="6" class="mt-2 w-full rounded border p-3 text-xl" style="font-family:Amiri,serif" dir="rtl"></textarea>
            <x-filament::button wire:click="resolveUnit('edit')">اعتماد التصحيح</x-filament::button>
        </div>
        <div class="rounded-xl border p-4 dark:border-gray-700">
            <label class="block font-bold" for="exclude-reason">سبب الاستبعاد</label>
            <textarea id="exclude-reason" wire:model="excludeReason" rows="2" class="mt-2 w-full rounded border p-3" dir="rtl"></textarea>
            <x-filament::button color="danger" wire:click="excludeUnit">استبعاد</x-filament::button>
        </div>
    @endif
</div>

<x-filament-panels::page>
    <div dir="rtl" class="space-y-6">
        @if (auth()->user()->primary_role === \App\Enums\UserRole::CompanyAdmin)
            <form wire:submit="upload" class="rounded-xl border p-4 space-y-3 dark:border-gray-700">
                <h2 class="font-bold">رفع نظام داخلي</h2>
                <input type="file" wire:model="sourceFile" accept=".txt,.docx,.pdf" class="block w-full">
                @error('sourceFile') <p class="text-red-600">{{ $message }}</p> @enderror
                <input type="text" wire:model="regulationName" placeholder="اسم النظام" class="block w-full rounded border p-2" required>
                @error('regulationName') <p class="text-red-600">{{ $message }}</p> @enderror
                <div class="flex gap-2">
                    <input type="text" wire:model="regulationNumber" placeholder="الرقم" class="rounded border p-2">
                    <input type="number" wire:model="regulationYear" placeholder="السنة" class="rounded border p-2">
                </div>
                <x-filament::button type="submit">بدء الاستيراد</x-filament::button>
            </form>
        @endif

        <section class="rounded-xl border p-4 dark:border-gray-700">
            <h2 class="font-bold">الأنظمة الداخلية</h2>
            @foreach ($this->laws() as $law)
                <button type="button" wire:click="selectLaw({{ $law->id }})" class="block w-full border-b p-3 text-right">
                    {{ $law->name }} — {{ $law->importRuns()->latest('id')->value('state') ?? 'لم يبدأ' }}
                    @if ($counts = $law->importRuns()->latest('id')->value('counts'))
                        <span class="text-sm">{{ is_string($counts) ? $counts : json_encode($counts, JSON_UNESCAPED_UNICODE) }}</span>
                    @endif
                </button>
            @endforeach
        </section>

        @if ($selectedLawId)
            <section class="rounded-xl border p-4 dark:border-gray-700">
                <div class="flex justify-between">
                    <h2 class="font-bold">المواد والفقرات</h2>
                    @if ($law = $this->laws()->firstWhere('id', $selectedLawId))
                        @can('review', $law)
                            <x-filament::button wire:click="approveAll">اعتماد كل الوحدات الموثقة</x-filament::button>
                        @endcan
                    @endif
                </div>
                @foreach ($this->units() as $unit)
                    <div class="border-b p-3">
                        <div class="flex flex-wrap gap-3">
                            <strong>المادة {{ $unit->article_number }} {{ $unit->clause_path }}</strong>
                            <span>{{ $unit->status->value }}</span>
                            <span>{{ \App\Models\LegalUnitApproval::query()->where('legal_unit_id', $unit->id)->exists() ? 'معتمدة' : 'بانتظار الموافقة' }}</span>
                            @if ($unit->source_page !== null && $unit->import_run_id !== null)
                                <a target="_blank" class="underline" href="{{ route('legal-library.pages.show', ['run' => $unit->import_run_id, 'page' => $unit->source_page]) }}">صورة الصفحة</a>
                            @endif
                            @can('review', $unit->law)
                                @if (in_array($unit->status->value, ['verified_auto', 'verified_human', 'verified_text'], true) && $unit->superseded_by_id === null)
                                    <x-filament::button size="sm" wire:click="approveUnit({{ $unit->id }})">اعتماد</x-filament::button>
                                @endif
                            @endcan
                        </div>
                        <p class="mt-2 whitespace-pre-wrap text-xl" style="font-family:Amiri,serif">{{ $unit->official_text }}</p>
                    </div>
                @endforeach
            </section>
        @endif

        @if (auth()->user()->can('review_internal_regulation'))
            @include('filament.partials.legal-mismatch-review')
        @endif
    </div>
</x-filament-panels::page>

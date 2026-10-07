<div class="space-y-4" x-data="{ active: null, insert(marker) { if (!this.active) return; let el = this.active; let start = el.selectionStart; let value = el.value.slice(0, start) + marker + el.value.slice(el.selectionEnd); $wire.set(el.getAttribute('wire:model'), value); this.$nextTick(() => { el.focus(); el.setSelectionRange(start + marker.length, start + marker.length); }); } }" @focusin.window="if ($event.target.tagName === 'TEXTAREA' && ['bodies', 'findings', 'recommendations', 'reasons'].some(key => ($event.target.getAttribute('wire:model') || '').startsWith(key + '.'))) active = $event.target" @citation-selected.window="insert($event.detail.marker)">
    @if($aiFailed)<p role="alert" class="rounded-2xl bg-amber-light text-amber p-4">تعذرت الصياغة بالذكاء الاصطناعي — يمكنك الكتابة يدوياً أو المحاولة مرة أخرى</p>@endif
    @if($aiBusy)
        <p role="status" wire:poll.3s="pollAiDraft" class="rounded-2xl bg-primary-light text-primary p-4">جارٍ الصياغة…</p>
    @elseif($aiAvailable)
        @if($confirmAiReplacement)
            <div role="alert" class="rounded-2xl bg-amber-light text-amber p-4 space-x-2">
                <span>سيستبدل النص الحالي</span>
                <button type="button" wire:click="requestAiDraft" class="underline font-bold">تأكيد الاستبدال</button>
                <button type="button" wire:click="$set('confirmAiReplacement', false)" class="underline">إلغاء</button>
            </div>
        @else
            <button type="button" wire:click="requestAiDraft" class="rounded-2xl border border-primary text-primary px-5 py-3 font-bold">صياغة بالذكاء الاصطناعي</button>
        @endif
    @endif
    <button type="button" wire:click="openCitationPicker" class="rounded-2xl border border-gray-300 px-5 py-3">إدراج مادة</button>
    @if($citationPickerOpen)
        <div role="dialog" aria-modal="true" aria-label="إدراج مادة" class="rounded-2xl border bg-white p-5 space-y-3">
            <div class="flex justify-between"><h2 class="font-bold">إدراج مادة</h2><button type="button" wire:click="closeCitationPicker" aria-label="إغلاق">✕</button></div>
            <input wire:model.live.debounce.300ms="citationSearch" type="search" placeholder="القانون أو المادة أو الفقرة أو كلمات من النص" class="w-full rounded-xl border p-3">
            <div class="max-h-72 overflow-y-auto space-y-2">
                @forelse($units as $unit)
                    <button type="button" wire:key="picker-unit-{{ $unit->id }}" wire:click="selectCitation({{ $unit->id }})" class="block w-full text-right rounded-xl bg-gray-50 p-3 hover:bg-primary-light">
                        <strong>{{ $unit->law->name }} — المادة {{ $unit->article_number }} {{ $unit->clause_path }}</strong>
                        <span class="block text-sm text-gray-500">{{ \Illuminate\Support\Str::limit($unit->official_text, 120) }}</span>
                    </button>
                @empty<p class="text-gray-500">لا توجد مواد قابلة للاستشهاد.</p>@endforelse
            </div>
        </div>
    @endif
    @if($document && ($notes = $document->aiDraftNotes()->latest('id')->first()))
        <aside class="rounded-2xl bg-gray-50 p-4"><h2 class="font-bold">ملاحظات الصياغة</h2><ul class="list-disc pr-5">@foreach($notes->notes ?? [] as $note)<li>{{ $note }}</li>@endforeach</ul></aside>
    @endif
</div>

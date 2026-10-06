<div class="max-w-5xl mx-auto space-y-5">
    <a href="/cases/{{ $case->id }}" class="text-primary text-sm">← الحالة</a>
    <section class="bg-white rounded-3xl p-6 md:p-8 shadow-sm space-y-5">
        <h1 class="text-2xl font-black">توصيات اللجنة</h1>
        <p class="text-gray-500">اكتب النتائج والتوصيات بكل لغة مفعلة. العربية هي النص المعتمد.</p>
        @if($notice)<p role="status" class="bg-amber-light text-amber rounded-2xl p-4">{{ $notice }}</p>@endif
        @error('notice')<p role="alert" class="bg-red-light text-red rounded-2xl p-4">{{ $message }}</p>@enderror
        @foreach($languages as $language)
            <div class="border-t pt-5 space-y-3" wire:key="language-{{ $language }}">
                <h2 class="font-black">{{ ['ar'=>'العربية','en'=>'English','ku'=>'کوردی'][$language] ?? $language }}</h2>
                <label class="block font-bold">النتائج <textarea wire:model="findings.{{ $language }}" rows="7" dir="{{ $language === 'en' ? 'ltr' : 'rtl' }}" class="block w-full rounded-xl border p-3 mt-2"></textarea></label>
                <label class="block font-bold">التوصيات <textarea wire:model="recommendations.{{ $language }}" rows="7" dir="{{ $language === 'en' ? 'ltr' : 'rtl' }}" class="block w-full rounded-xl border p-3 mt-2"></textarea></label>
            </div>
        @endforeach
        <div class="flex flex-wrap gap-3"><button wire:click="save" class="border border-primary text-primary rounded-2xl px-6 py-3">حفظ</button><button wire:click="submit" class="bg-primary text-white rounded-2xl px-6 py-3 font-bold">إرسال للموافقات</button></div>
        @if($document)<a href="{{ route('documents.preview', $document) }}" target="_blank" rel="noopener" class="text-primary underline">معاينة المستند</a>@endif
    </section>
</div>

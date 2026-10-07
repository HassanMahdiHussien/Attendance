<div class="max-w-5xl mx-auto space-y-5">
    <a href="/cases/{{ $case->id }}" class="text-primary text-sm">← الحالة</a>
    <section class="bg-white rounded-3xl p-6 md:p-8 shadow-sm space-y-5">
        <h1 class="text-2xl font-black">كتابة كتاب القرار</h1>
        <p class="text-gray-500">اكتب أسباب القرار الذي اتخذته اللجنة. تُطبع النتيجة ونوع العقوبة من سجل الحالة.</p>
        @if($notice)<p role="status" class="bg-amber-light text-amber rounded-2xl p-4">{{ $notice }}</p>@endif
        @error('notice')<p role="alert" class="text-red">{{ $message }}</p>@enderror
        @foreach($languages as $language)
            <label class="block font-bold" dir="{{ $language === 'en' ? 'ltr' : 'rtl' }}">{{ ['ar'=>'العربية','en'=>'English','ku'=>'کوردی'][$language] ?? $language }}
                <textarea wire:model="reasons.{{ $language }}" rows="8" class="block w-full rounded-xl border p-3 mt-2"></textarea>
            </label>
            @error('reasons.'.$language)<p class="text-red">{{ $message }}</p>@enderror
        @endforeach
        @if($case->outcome?->value === 'termination')
            @unless($document?->leave_confirmed_at)<label class="flex gap-2 items-center"><input type="checkbox" wire:model="leaveChecked">الموظف ليس في إجازة قانونية أو غياب مرضي موثق</label><button wire:click="confirmLeave" class="border border-primary text-primary rounded-2xl px-5 py-3">تسجيل التأكيد</button>@error('leaveChecked')<p class="text-red">{{ $message }}</p>@enderror@endunless
            @if($document?->leave_confirmed_at)<p role="status" class="text-green">سُجل التأكيد {{ $document->leave_confirmed_at }}</p>@endif
        @endif
        <div class="flex gap-3"><button wire:click="save" class="border border-primary text-primary rounded-2xl px-5 py-3">حفظ المسودة</button><button wire:click="submit" class="bg-primary text-white rounded-2xl px-5 py-3">إرسال للمراجعة</button></div>
    </section>
</div>

<div class="max-w-3xl mx-auto space-y-5">
    <a href="/cases/{{ $case->id }}" class="text-primary text-sm">← الحالة</a>
    <section class="bg-white rounded-3xl p-6 space-y-5">
        <h1 class="text-2xl font-black">الطعن — الحالة {{ \App\Support\Format\ArabicDigits::convert($case->case_number) }}</h1>
        @if($tracking)<p>فترة الطعن: {{ \App\Support\Format\ArabicDigits::convert(\App\Documents\IraqiDate::format(\Carbon\CarbonImmutable::parse($tracking->window_start), 'ar')) }} — {{ \App\Support\Format\ArabicDigits::convert(\App\Documents\IraqiDate::format(\Carbon\CarbonImmutable::parse($tracking->window_end), 'ar')) }}</p>@endif
        @error('notice')<p role="alert" class="text-red">{{ $message }}</p>@enderror
        @if($case->status === \App\Enums\CaseStatus::AwaitingAppealWindow)
            <label class="block font-bold">تاريخ تقديم الطعن <input type="date" wire:model="filedAt" class="block rounded-xl border p-3 mt-2"></label>
            @error('filedAt')<p class="text-red">{{ $message }}</p>@enderror
            <button wire:click="file" class="bg-primary text-white rounded-2xl px-5 py-3">تسجيل الطعن</button>
        @elseif($case->status === \App\Enums\CaseStatus::AppealPending)
            <label class="block font-bold">نتيجة الطعن <textarea wire:model="outcome" rows="7" class="block w-full rounded-xl border p-3 mt-2"></textarea></label>
            @error('outcome')<p class="text-red">{{ $message }}</p>@enderror
            <button wire:click="resolve" class="bg-primary text-white rounded-2xl px-5 py-3">تسجيل النتيجة والأرشفة</button>
        @endif
    </section>
</div>

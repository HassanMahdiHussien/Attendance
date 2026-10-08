<div class="max-w-3xl mx-auto space-y-5">
    <a href="/cases/{{ $case->id }}" class="text-primary text-sm">{{ app()->getLocale() === 'ar' ? '→' : '←' }} {{ __('Case') }}</a>
    <section class="bg-white rounded-3xl p-6 space-y-5">
        <h1 class="text-2xl font-black">{{ __('Appeal') }} — {{ __('Case') }} {{ \App\Support\Format\Ui::number($case->case_number) }}</h1>
        @if($tracking)<p>{{ __('Appeal window') }}: {{ \App\Support\Format\Ui::date($tracking->window_start) }} — {{ \App\Support\Format\Ui::date($tracking->window_end) }}</p>@endif
        @error('notice')<p role="alert" class="text-red">{{ $message }}</p>@enderror
        @if($case->status === \App\Enums\CaseStatus::AwaitingAppealWindow)
            <label class="block font-bold">{{ __('Appeal filing date') }} <input type="date" wire:model="filedAt" class="block rounded-xl border p-3 mt-2"></label>
            @error('filedAt')<p class="text-red">{{ $message }}</p>@enderror
            <button wire:click="file" class="bg-primary text-white rounded-2xl px-5 py-3">{{ __('Record appeal') }}</button>
        @elseif($case->status === \App\Enums\CaseStatus::AppealPending)
            <label class="block font-bold">{{ __('Appeal outcome') }} <textarea wire:model="outcome" rows="7" class="block w-full rounded-xl border p-3 mt-2"></textarea></label>
            @error('outcome')<p class="text-red">{{ $message }}</p>@enderror
            <button wire:click="resolve" class="bg-primary text-white rounded-2xl px-5 py-3">{{ __('Record outcome and archive') }}</button>
        @endif
    </section>
</div>

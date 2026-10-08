<div class="mx-auto max-w-3xl space-y-5">
    <x-ui.page-header :title="__('Appeal').' — '.__('Case').' '.\App\Support\Format\Ui::number($case->case_number)" back="/cases/{{ $case->id }}" />
    <x-ui.card class="space-y-5">
        @if($tracking)<p>{{ __('Appeal window') }}: {{ \App\Support\Format\Ui::date($tracking->window_start) }} — {{ \App\Support\Format\Ui::date($tracking->window_end) }}</p>@endif
        @error('notice')<x-ui.alert tone="red">{{ $message }}</x-ui.alert>@enderror
        @if($case->status === \App\Enums\CaseStatus::AwaitingAppealWindow)
            <label class="block font-bold">{{ __('Appeal filing date') }}<x-ui.input type="date" wire:model="filedAt" class="mt-2" /></label>
            @error('filedAt')<p class="text-red-700">{{ $message }}</p>@enderror
            <x-ui.button wire:click="file">{{ __('Record appeal') }}</x-ui.button>
        @elseif($case->status === \App\Enums\CaseStatus::AppealPending)
            <label class="block font-bold">{{ __('Appeal outcome') }}<x-ui.textarea wire:model="outcome" rows="7" class="mt-2" /></label>
            @error('outcome')<p class="text-red-700">{{ $message }}</p>@enderror
            <x-ui.button wire:click="resolve">{{ __('Record outcome and archive') }}</x-ui.button>
        @endif
    </x-ui.card>
</div>

<x-filament-panels::page>
    <div class="space-y-5">
        <x-ui.card>
            <form wire:submit="upload" class="space-y-3">
                <label class="block">{{ __('messages.holiday_ui.announcement') }} <input type="file" wire:model="sourceFile" accept=".pdf,.png,.jpg,.jpeg,.eml,.msg" class="block w-full rounded-xl border border-gray-200 p-2"></label>
                @error('sourceFile') <p class="text-red-600">{{ $message }}</p> @enderror
                @error('file') <p class="text-red-600">{{ $message }}</p> @enderror
                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="sourceFile">{{ __('messages.holiday_ui.upload') }}</x-filament::button>
            </form>
        </x-ui.card>
        @if ($source = $this->source())
            <div @if ($source->ai_status === 'pending') wire:poll.3s @endif>
            <x-ui.card>
                <p><bdi dir="ltr">{{ $source->file_name }}</bdi> — {{ __('messages.holiday_ui.ai_statuses.'.$source->ai_status) }}</p>
                @if ($source->error_code)<p class="text-amber-800">{{ __('messages.holidays.ai_errors.'.$source->error_code) }}</p>@endif
                @if ($source->ai_status === 'done')<x-filament::button wire:click="createDraft">{{ __('messages.holiday_ui.create_from_proposal') }}</x-filament::button>@endif
                <a class="ui-link" href="{{ \App\Filament\Admin\Pages\Attendance\HolidayReview::getUrl(panel: 'admin', tenant: $this->company()).'?'.http_build_query(['source' => $source->id]) }}">{{ __('messages.holiday_ui.enter_by_hand') }}</a>
            </x-ui.card>
            </div>
        @endif
    </div>
</x-filament-panels::page>

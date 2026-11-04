<x-filament-panels::page>
    <div class="space-y-5">
        <p class="text-sm text-gray-600">{{ __('messages.clear_screens.holiday_upload_context') }}</p>
        <x-ui.card>
            <x-ui.upload-form wire:submit="uploadAnnouncement" class="space-y-3">
                <label class="block">{{ __('messages.holiday_ui.announcement') }} <input type="file" wire:model="sourceFile" accept=".pdf,.png,.jpg,.jpeg,.eml,.msg" class="block w-full rounded-xl border border-gray-200 p-2"></label>
                @error('sourceFile') <p class="text-red-600" x-show="! stale">{{ $message }}</p> @enderror
                @error('file') <p class="text-red-600">{{ $message }}</p> @enderror
                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="sourceFile" x-bind:disabled="uploading">{{ __('messages.holiday_ui.upload') }}</x-filament::button>
            </x-ui.upload-form>
        </x-ui.card>
        @if ($source = $this->source())
            <livewire:attendance.announcement-review :source-id="$source->id" :key="'announcement-'.$source->id" />
        @endif
    </div>
</x-filament-panels::page>

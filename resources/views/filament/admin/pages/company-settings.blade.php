<x-filament-panels::page>
    <p class="mb-4 text-sm text-gray-600">{{ __('messages.clear_screens.company_settings_context') }}</p>
    <form wire:submit="save" class="space-y-6 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
        <h2 class="font-semibold">{{ __('messages.clear_screens.company_details') }}</h2>
        {{ $this->form }}
        <x-filament::button type="submit">{{ __('Save settings') }}</x-filament::button>
    </form>
    <form wire:submit="saveChatSettings" class="space-y-4 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
        <h2 class="text-base font-bold">{{ __('messages.chat_ui.settings') }}</h2>
        <label class="flex items-center gap-3 text-sm"><input type="checkbox" wire:model="chatChangesEnabled" class="rounded border-gray-300">{{ __('messages.chat_ui.changes_switch') }}</label>
        <p class="text-sm text-gray-500">{{ __('messages.chat_ui.changes_setting_note') }}</p>
        <label class="block text-sm">{{ __('messages.chat_ui.reason') }}
            <input type="text" wire:model="chatReason" maxlength="500" class="mt-1 block w-full rounded-lg border border-gray-300 p-2">
        </label>
        @error('chatReason')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
        <button type="submit" class="ui-button ui-button-secondary">{{ __('messages.chat_ui.save_settings') }}</button>
    </form>
</x-filament-panels::page>

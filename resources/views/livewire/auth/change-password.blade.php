<div class="mx-auto max-w-xl">
    <x-ui.card class="space-y-5 p-6 sm:p-8">
        <h1 class="text-2xl font-semibold">{{ __('messages.password.title') }}</h1>
        @if(auth()->user()->must_change_password)
            <p class="text-sm text-gray-700">{{ __('messages.password.forced_explanation') }}</p>
        @endif
        <form wire:submit="save" class="space-y-5">
            <x-ui.field :label="auth()->user()->must_change_password ? __('messages.password.temporary') : __('messages.password.current')" error="current_password" for="current_password">
                <x-ui.input id="current_password" type="password" wire:model="current_password" autocomplete="current-password" required />
            </x-ui.field>
            <x-ui.field :label="__('messages.password.new')" error="new_password" for="new_password">
                <x-ui.input id="new_password" type="password" wire:model="new_password" autocomplete="new-password" required />
            </x-ui.field>
            <x-ui.field :label="__('messages.password.confirm')" error="new_password_confirmation" for="new_password_confirmation">
                <x-ui.input id="new_password_confirmation" type="password" wire:model="new_password_confirmation" autocomplete="new-password" required />
            </x-ui.field>
            <x-ui.button type="submit">{{ __('messages.password.save') }}</x-ui.button>
        </form>
    </x-ui.card>
</div>

<div class="flex w-full items-center justify-center">
    <form wire:submit="authenticate" class="w-full max-w-md">
        <x-ui.card class="space-y-5 p-6 sm:p-8">
            <header>
                <h1><x-ui.brand size="lg" :name="false" /><span class="sr-only">{{ __('Attendance') }}</span></h1>
                <p class="mt-1 text-sm text-gray-500">{{ __('Log in to your workspace') }}</p>
            </header>
            <x-ui.field :label="__('Email address')" error="email" for="email">
                <x-ui.input id="email" type="email" wire:model="email" autocomplete="username" required />
                @error('email')<span class="sr-only">{{ $message }}</span>@enderror
            </x-ui.field>
            <x-ui.field :label="__('Password')" error="password" for="password">
                <x-ui.input id="password" type="password" wire:model="password" autocomplete="current-password" required />
                @error('password')<span class="sr-only">{{ $message }}</span>@enderror
            </x-ui.field>
            <x-ui.button type="submit" class="w-full">{{ __('Log in') }}</x-ui.button>
        </x-ui.card>
    </form>
</div>

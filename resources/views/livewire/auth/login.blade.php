<main class="min-h-screen bg-bg font-tajawal flex items-center justify-center p-4">
    <form wire:submit="authenticate" class="w-full max-w-md bg-white rounded-3xl p-8 shadow-lg">
        <h1 class="text-2xl font-black text-primary mb-2">{{ __('Displanery') }}</h1>
        <p class="text-sm text-gray-500 mb-7">{{ __('Log in to your workspace') }}</p>
        <label for="email" class="block text-sm font-bold mb-2">{{ __('Email address') }}</label>
        <input id="email" type="email" wire:model="email" autocomplete="username" class="w-full rounded-xl border border-gray-200 p-3 mb-2" required>
        @error('email') <p class="text-red text-sm mb-3">{{ $message }}</p> @enderror
        <label for="password" class="block text-sm font-bold mb-2 mt-3">{{ __('Password') }}</label>
        <input id="password" type="password" wire:model="password" autocomplete="current-password" class="w-full rounded-xl border border-gray-200 p-3 mb-2" required>
        @error('password') <p class="text-red text-sm mb-3">{{ $message }}</p> @enderror
        <button class="w-full bg-primary text-white font-bold py-4 rounded-2xl shadow-lg shadow-indigo-200 mt-5">{{ __('Log in') }}</button>
    </form>
</main>

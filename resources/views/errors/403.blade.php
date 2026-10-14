@component('layouts.guest')
    <x-ui.card class="w-full max-w-md space-y-4 text-center">
        <span class="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-amber-light text-amber" aria-hidden="true"><x-ui.icon name="lock" class="h-6 w-6" /></span>
        <h1 class="text-xl font-extrabold text-ink">{{ __('You do not have access to this page') }}</h1>
        <p class="text-sm text-gray-600">{{ __('Your account cannot open this page. If you think it should, ask your company admin to change your access.') }}</p>
        @auth
            <p class="text-sm text-gray-500">{{ __('Signed in as') }} <bdi class="font-semibold">{{ auth()->user()->email }}</bdi></p>
            <div class="flex flex-wrap justify-center gap-2">
                <x-ui.button :href="\App\Support\Access\Landing::for(auth()->user())">{{ __('Go to my work') }}</x-ui.button>
                <form method="POST" action="{{ route('logout') }}">@csrf<x-ui.button type="submit" variant="secondary">{{ __('Sign in with another account') }}</x-ui.button></form>
            </div>
        @else
            <x-ui.button href="/login">{{ __('Log in') }}</x-ui.button>
        @endauth
    </x-ui.card>
@endcomponent

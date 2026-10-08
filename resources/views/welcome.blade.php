@extends('layouts.app')

@section('content')
    <main class="mx-auto flex min-h-screen max-w-3xl items-center px-6">
        <div class="rounded-3xl bg-white p-10 shadow-sm">
            <h1 class="text-3xl font-bold text-primary">{{ __('Displanery') }}</h1>
            <p class="mt-4 text-ink">{{ __('Manage and document employee disciplinary procedures for Iraqi companies.') }}</p>
            <a href="{{ route('login') }}" class="mt-8 inline-block rounded-2xl bg-primary px-6 py-3 font-bold text-white">{{ __('Log in') }}</a>
        </div>
    </main>
@endsection

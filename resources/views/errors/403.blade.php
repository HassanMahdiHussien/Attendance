@extends('errors.layout')

@section('code', '403')
@section('heading', __('You do not have access to this page'))
@section('message', __('Your account cannot open this page. If you think it should, ask your company admin to change your access.'))
@section('localeSwitch')
    @include('errors.locale-switch')
@endsection
@section('action')
    @auth
        <p>{{ __('Signed in as') }} <bdi>{{ auth()->user()->email }}</bdi></p>
        <a href="{{ \App\Support\Access\Landing::for(auth()->user()) }}" class="action">{{ __('Go to my work') }}</a>
        <form method="POST" action="{{ route('logout') }}" style="display: inline-block;">
            @csrf
            <button type="submit" class="action secondary">{{ __('Sign in with another account') }}</button>
        </form>
    @else
        <a href="/login" class="action">{{ __('Log in') }}</a>
    @endauth
@endsection

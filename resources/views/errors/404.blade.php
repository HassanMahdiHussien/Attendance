@extends('errors.layout')
@section('code', '404')
@section('heading', __('Page not found'))
@section('message', __('The page you requested could not be found.'))
@section('action')
    @auth
        <a href="{{ \App\Support\Access\Landing::for(auth()->user()) }}" class="action">{{ __('Go to my work') }}</a>
    @else
        <a href="/login" class="action">{{ __('Log in') }}</a>
    @endauth
@endsection

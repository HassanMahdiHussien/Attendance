@extends('errors.layout')
@section('code', '419')
@section('heading', __('Page expired'))
@section('message', __('Your session expired. Sign in again to continue.'))
@section('action')
    @auth
        <a href="{{ \App\Support\Access\Landing::for(auth()->user()) }}" class="action">{{ __('Go to my work') }}</a>
    @else
        <a href="/login" class="action">{{ __('Log in') }}</a>
    @endauth
@endsection

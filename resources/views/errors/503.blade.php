@extends('errors.layout')
@section('code', '503')
@section('heading', __('Service temporarily unavailable'))
@section('message', __('The service is temporarily unavailable. Please try again shortly.'))
@section('action')
    <a href="/login" class="action">{{ __('Log in') }}</a>
@endsection

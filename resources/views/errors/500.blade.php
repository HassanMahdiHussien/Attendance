@extends('errors.layout')
@section('code', '500')
@section('heading', __('Something went wrong'))
@section('message', __('We could not load this page. Please try again shortly.'))
@section('action')
    <a href="/login" class="action">{{ __('Log in') }}</a>
@endsection

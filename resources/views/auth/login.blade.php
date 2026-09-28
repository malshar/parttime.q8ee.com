@extends('layouts.app')
@section('title', __('app.auth.login_title'))
@section('content')
<div class="row justify-content-center"><div class="col-md-6">
    <h1 class="h4 mb-3">{{ __('app.auth.login_title') }}</h1>
    <form method="post" action="{{ route('login.attempt') }}">
        @csrf
        <div class="mb-3"><label class="form-label">{{ __('app.auth.email') }}</label>
            <input name="email" type="email" value="{{ old('email') }}" class="form-control" required dir="ltr" autofocus></div>
        <div class="mb-3"><label class="form-label">{{ __('app.auth.password') }}</label>
            <input name="password" type="password" class="form-control" required dir="ltr"></div>
        <div class="mb-3 form-check">
            <input type="checkbox" name="remember" id="remember" class="form-check-input" value="1">
            <label class="form-check-label" for="remember">{{ __('app.auth.remember') }}</label>
        </div>
        <x-turnstile />
        <button class="btn btn-eet">{{ __('app.auth.login_button') }}</button>
        <a class="btn btn-link" href="{{ route('register') }}">{{ __('app.auth.no_account') }}</a>
        <a class="btn btn-link" href="{{ route('password.request') }}">{{ __('app.auth.forgot') }}</a>
    </form>
</div></div>
@endsection

@extends('layouts.app')
@section('title', __('app.auth.register_title'))
@section('content')
<div class="row justify-content-center"><div class="col-md-6">
    <h1 class="h4 mb-3">{{ __('app.auth.register_title') }}</h1>
    <form method="post" action="{{ route('register.store') }}">
        @csrf
        <div class="mb-3"><label class="form-label">{{ __('app.auth.name') }}</label>
            <input name="name" value="{{ old('name') }}" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">{{ __('app.auth.email') }}</label>
            <input name="email" type="email" value="{{ old('email') }}" class="form-control" required dir="ltr"></div>
        <div class="mb-3"><label class="form-label">{{ __('app.auth.password') }}</label>
            <input name="password" type="password" class="form-control" required dir="ltr">
            <div class="form-text">{{ __('app.auth.password_hint') }}</div></div>
        <div class="mb-3"><label class="form-label">{{ __('app.auth.password_confirmation') }}</label>
            <input name="password_confirmation" type="password" class="form-control" required dir="ltr"></div>
        <x-turnstile />
        <button class="btn btn-eet">{{ __('app.auth.register_button') }}</button>
        <a class="btn btn-link" href="{{ route('login') }}">{{ __('app.auth.have_account') }}</a>
    </form>
</div></div>
@endsection

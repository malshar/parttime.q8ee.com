@extends('layouts.app')
@section('title', __('app.auth.reset_title'))
@section('content')
<div class="row justify-content-center"><div class="col-md-6">
    <h1 class="h4 mb-3">{{ __('app.auth.reset_title') }}</h1>
    <form method="post" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div class="mb-3"><label class="form-label">{{ __('app.auth.email') }}</label>
            <input name="email" type="email" value="{{ old('email', $email) }}" class="form-control" required dir="ltr"></div>
        <div class="mb-3"><label class="form-label">{{ __('app.auth.new_password') }}</label>
            <input name="password" type="password" class="form-control" required dir="ltr">
            <div class="form-text">{{ __('app.auth.password_hint') }}</div></div>
        <div class="mb-3"><label class="form-label">{{ __('app.auth.password_confirmation') }}</label>
            <input name="password_confirmation" type="password" class="form-control" required dir="ltr"></div>
        <button class="btn btn-eet">{{ __('app.auth.reset_button') }}</button>
    </form>
</div></div>
@endsection

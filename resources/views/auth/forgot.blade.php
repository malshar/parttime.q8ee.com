@extends('layouts.app')
@section('title', __('app.auth.reset_title'))
@section('content')
<div class="row justify-content-center"><div class="col-md-6">
    <h1 class="h4 mb-3">{{ __('app.auth.reset_title') }}</h1>
    <form method="post" action="{{ route('password.email') }}">
        @csrf
        <div class="mb-3"><label class="form-label">{{ __('app.auth.email') }}</label>
            <input name="email" type="email" value="{{ old('email') }}" class="form-control" required dir="ltr" autofocus></div>
        <button class="btn btn-eet">{{ __('app.auth.send_reset_link') }}</button>
    </form>
</div></div>
@endsection

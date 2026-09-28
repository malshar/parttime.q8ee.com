@extends('layouts.app')
@section('title', __('app.auth.verify_notice'))
@section('content')
<div class="row justify-content-center"><div class="col-md-6 text-center">
    <p>{{ __('app.auth.verify_notice') }}</p>
    <form method="post" action="{{ route('verification.send') }}" class="d-inline">
        @csrf
        <button class="btn btn-eet">{{ __('app.auth.resend') }}</button>
    </form>
    <form method="post" action="{{ route('logout') }}" class="d-inline">
        @csrf
        <button class="btn btn-link">{{ __('app.auth.logout') }}</button>
    </form>
</div></div>
@endsection

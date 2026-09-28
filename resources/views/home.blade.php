@extends('layouts.app')
@section('content')
<div class="row justify-content-center"><div class="col-lg-8 text-center">
    <h1 class="h3 mb-3">{{ __('app.home.title') }}</h1>
    <p class="lead">{{ __('app.home.lead') }}</p>
    <a href="{{ route('register') }}" class="btn btn-eet btn-lg m-1">{{ __('app.home.register') }}</a>
    <a href="{{ route('login') }}" class="btn btn-outline-secondary btn-lg m-1">{{ __('app.home.login') }}</a>
</div></div>
@endsection

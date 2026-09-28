@extends('layouts.app')

@section('nav')
    <a class="btn btn-outline-light btn-sm" href="{{ route('admin.dashboard') }}">{{ __('app.site_name') }}</a>
    <a class="btn btn-outline-light btn-sm" href="{{ route('admin.applications.index') }}">{{ __('app.review.applications') }}</a>
    <a class="btn btn-outline-light btn-sm" href="{{ route('admin.terms.index') }}">{{ __('app.terms.title') }}</a>
    <form method="post" action="{{ route('logout') }}" class="d-inline">
        @csrf
        <button type="submit" class="btn btn-outline-light btn-sm">{{ __('app.auth.logout') }}</button>
    </form>
@endsection

@section('content')
    @yield('content')
@endsection

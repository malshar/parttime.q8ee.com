@extends('layouts.app')

@section('nav')
    <a class="btn btn-outline-light btn-sm" href="{{ route('instructor.home') }}">{{ __('app.site_name') }}</a>
    <a class="btn btn-outline-light btn-sm" href="{{ route('instructor.profile.edit') }}">{{ __('app.profile.title') }}</a>
    <form method="post" action="{{ route('logout') }}" class="d-inline">
        @csrf
        <button type="submit" class="btn btn-outline-light btn-sm">{{ __('app.auth.logout') }}</button>
    </form>
@endsection

@section('content')
    @yield('content')
@endsection

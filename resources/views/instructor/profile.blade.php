@extends('instructor.layout')
@section('title', __('app.profile.title'))
@section('content')
<div class="row justify-content-center"><div class="col-lg-10">
    <h1 class="h4 mb-3">{{ __('app.profile.title') }}</h1>
    @if ($locked)
        <div class="alert alert-info">{{ __('app.profile.locked') }}</div>
    @endif
    <form method="post" action="{{ route('instructor.profile.update') }}">
        @csrf
        @method('put')
        <fieldset @disabled($locked)>
            @include('instructor._profile_fields', ['instructor' => $instructor])
        </fieldset>

        @unless ($locked)
            <button class="btn btn-eet">{{ __('app.common.save') }}</button>
        @endunless
        <a class="btn btn-link" href="{{ route('instructor.home') }}">{{ __('app.common.cancel') }}</a>
    </form>
</div></div>
@endsection

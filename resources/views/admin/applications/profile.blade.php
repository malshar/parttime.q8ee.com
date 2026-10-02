@extends('admin.layout')
@section('title', __('app.review.edit_profile'))
@section('content')
<div class="row justify-content-center"><div class="col-lg-10">
    <h1 class="h4 mb-3">{{ __('app.review.edit_profile') }} — {{ $instructor->full_name }}</h1>
    <div class="alert alert-warning">{{ __('app.review.profile_edit_warning') }}</div>
    <form method="post" action="{{ route('admin.applications.profile.update', $application) }}">
        @csrf
        @method('put')
        @include('instructor._profile_fields', ['instructor' => $instructor, 'sensitiveAttrs' => 'autocomplete="off"'])

        <button class="btn btn-eet">{{ __('app.common.save') }}</button>
        <a class="btn btn-link" href="{{ route('admin.applications.show', $application) }}">{{ __('app.common.cancel') }}</a>
    </form>
</div></div>
@endsection

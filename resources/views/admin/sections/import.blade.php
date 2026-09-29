@extends('admin.layout')
@section('title', __('app.sections.import'))
@section('content')
<h1 class="h4 mb-3">{{ __('app.sections.import') }}</h1>

@if (! $term)
    <div class="alert alert-warning">{{ __('app.terms.none_open') }}</div>
@else
    <form method="post" action="{{ route('admin.sections.import.preview') }}" enctype="multipart/form-data">
        @csrf
        <div class="mb-3">
            <label class="form-label">{{ __('app.sections.title') }}</label>
            <input type="file" name="file" class="form-control" accept=".csv,.xlsx" required>
            <div class="form-text">{{ __('app.sections.import_help') }}</div>
        </div>
        <button type="submit" class="btn btn-eet">{{ __('app.sections.preview') }}</button>
    </form>
@endif
@endsection

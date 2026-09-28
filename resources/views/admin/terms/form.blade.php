@extends('admin.layout')
@section('title', $term->exists ? __('app.terms.edit') : __('app.terms.add'))
@section('content')
<div class="row justify-content-center"><div class="col-md-8">
    <h1 class="h4 mb-3">{{ $term->exists ? __('app.terms.edit') : __('app.terms.add') }}</h1>
    <form method="post" action="{{ $term->exists ? route('admin.terms.update', $term) : route('admin.terms.store') }}">
        @csrf
        @if ($term->exists)
            @method('put')
        @endif
        <div class="mb-3"><label class="form-label">{{ __('app.terms.academic_year') }}</label>
            <input name="academic_year" value="{{ old('academic_year', $term->academic_year) }}" class="form-control" dir="ltr" placeholder="2026-2027" required></div>
        <div class="mb-3"><label class="form-label">{{ __('app.terms.type') }}</label>
            <select name="type" class="form-select" required>
                @foreach (\App\Models\Term::TYPES as $type)
                    <option value="{{ $type }}" @selected(old('type', $term->type) === $type)>{{ __('app.terms.types.'.$type) }}</option>
                @endforeach
            </select></div>
        <div class="row">
            <div class="col-md-6 mb-3"><label class="form-label">{{ __('app.terms.teaching_starts_on') }}</label>
                <input type="date" name="teaching_starts_on" value="{{ old('teaching_starts_on', optional($term->teaching_starts_on)->format('Y-m-d')) }}" class="form-control" required></div>
            <div class="col-md-6 mb-3"><label class="form-label">{{ __('app.terms.teaching_ends_on') }}</label>
                <input type="date" name="teaching_ends_on" value="{{ old('teaching_ends_on', optional($term->teaching_ends_on)->format('Y-m-d')) }}" class="form-control" required></div>
        </div>
        <div class="mb-3"><label class="form-label">{{ __('app.terms.holidays') }}</label>
            <textarea name="holidays" rows="6" class="form-control" dir="ltr">{{ old('holidays', $term->holidays->map(fn ($h) => $h->date->format('Y-m-d').'|'.$h->name)->implode("\n")) }}</textarea>
            <div class="form-text">{{ __('app.terms.holidays_help') }}</div></div>
        <button class="btn btn-eet">{{ __('app.common.save') }}</button>
        <a class="btn btn-link" href="{{ route('admin.terms.index') }}">{{ __('app.common.cancel') }}</a>
    </form>
</div></div>
@endsection

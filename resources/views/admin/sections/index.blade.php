@extends('admin.layout')
@section('title', __('app.sections.title'))
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">{{ __('app.sections.title') }}</h1>
    <a href="{{ route('admin.sections.import.form') }}" class="btn btn-eet">{{ __('app.sections.import') }}</a>
</div>

<form method="get" action="{{ route('admin.sections.index') }}" class="row g-2 align-items-center mb-3">
    <div class="col-auto">
        <select name="term" class="form-select" onchange="this.form.submit()">
            @foreach ($terms as $t)
                <option value="{{ $t->id }}" @selected($term && $term->id === $t->id)>{{ $t->label() }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <input type="text" name="course" value="{{ request('course') }}" class="form-control" placeholder="{{ __('app.sections.course') }}">
    </div>
    <div class="col-auto form-check">
        <input type="checkbox" name="unassigned" value="1" id="unassigned" class="form-check-input" @checked(request()->boolean('unassigned')) onchange="this.form.submit()">
        <label for="unassigned" class="form-check-label">{{ __('app.sections.unassigned_only') }}</label>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-outline-secondary">{{ __('app.sections.title') }}</button>
    </div>
</form>

<div class="table-responsive">
    <table class="table table-striped align-middle">
        <thead>
        <tr>
            <th>{{ __('app.sections.course') }}</th>
            <th>{{ __('app.sections.section') }}</th>
            <th>{{ __('app.sections.course_name') }}</th>
            <th>{{ __('app.sections.meetings') }}</th>
            <th>{{ __('app.sections.hours_theory') }}</th>
            <th>{{ __('app.sections.hours_practical') }}</th>
            <th>{{ __('app.sections.hours_field') }}</th>
            <th>{{ __('app.sections.scheduled_instructor') }}</th>
            <th>{{ __('app.sections.assignee') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($sections as $s)
            @php($hours = $s->weeklyMinutesByType())
            <tr>
                <td>
                    {{ $s->course_code }}
                    @if ($s->missing_since_import)
                        <span class="badge bg-danger">{{ __('app.sections.missing_badge') }}</span>
                    @endif
                </td>
                <td>{{ $s->section_number }}</td>
                <td>{{ $s->course_name_ar }}</td>
                <td>{{ $s->meetingSummary() }}</td>
                <td>{{ \App\Models\Section::hoursFromMinutes($hours['theory']) }}</td>
                <td>{{ \App\Models\Section::hoursFromMinutes($hours['practical']) }}</td>
                <td>{{ \App\Models\Section::hoursFromMinutes($hours['field']) }}</td>
                <td>{{ $s->scheduled_instructor }}</td>
                <td>{{ $s->assignment?->application->instructor->full_name }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection

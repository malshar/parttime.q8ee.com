@extends('admin.layout')
@section('title', __('app.sections.preview'))
@section('content')
@php
    $c = $plan->counts();
    $formatTime = function (string $time) {
        [$hour, $minute] = explode(':', substr($time, 0, 5));

        return ((int) $hour).':'.$minute;
    };
@endphp

<h1 class="h4 mb-3">{{ __('app.sections.preview') }} — {{ $term->label() }}</h1>

<div class="d-flex flex-wrap gap-2 mb-3">
    <span class="badge bg-success">{{ __('app.sections.preview_insert', ['n' => $c['insert']]) }}</span>
    <span class="badge bg-info text-dark">{{ __('app.sections.preview_update', ['n' => $c['update']]) }}</span>
    <span class="badge bg-secondary">{{ __('app.sections.preview_unchanged', ['n' => $c['unchanged']]) }}</span>
    <span class="badge bg-danger">{{ __('app.sections.preview_delete', ['n' => $c['delete']]) }}</span>
    <span class="badge bg-warning text-dark">{{ __('app.sections.preview_flag', ['n' => $c['flag']]) }}</span>
</div>

@if ($timetable->warnings)
    <div class="alert alert-warning">
        <strong>{{ __('app.sections.warnings') }}</strong>
        <ul class="mb-0">
            @foreach ($timetable->warnings as $w)
                <li>{{ $w }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if ($timetable->errors)
    <div class="alert alert-danger">
        <strong>{{ __('app.sections.errors') }}</strong>
        <ul class="mb-0">
            @foreach ($timetable->errors as $e)
                <li>{{ $e }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="table-responsive">
    <table class="table table-striped align-middle">
        <thead>
        <tr>
            <th>{{ __('app.sections.course') }}</th>
            <th>{{ __('app.sections.section') }}</th>
            <th>{{ __('app.sections.course_name') }}</th>
            <th>{{ __('app.sections.scheduled_instructor') }}</th>
            <th>{{ __('app.sections.meetings') }}</th>
            <th>{{ __('app.sections.hours_theory') }}</th>
            <th>{{ __('app.sections.hours_practical') }}</th>
            <th>{{ __('app.sections.hours_field') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($timetable->sections as $section)
            @php
                $groups = [];
                foreach ($section->meetings as $m) {
                    $timeRange = $formatTime($m->startsAt).'-'.$formatTime($m->endsAt);
                    $key = $m->activityAr.'|'.$timeRange;
                    $groups[$key]['activity'] = $m->activityAr;
                    $groups[$key]['time'] = $timeRange;
                    $groups[$key]['days'][] = \App\Models\SectionMeeting::DAY_NAMES_AR[$m->dayOfWeek] ?? $m->dayOfWeek;
                }
                $summary = implode('؛ ', array_map(fn ($g) => $g['activity'].': '.implode('/', $g['days']).' '.$g['time'], $groups));
                $minutes = $section->minutesByType();
            @endphp
            <tr>
                <td>{{ $section->courseCode }}</td>
                <td>{{ $section->sectionNumber }}</td>
                <td>{{ $section->courseName }}</td>
                <td>{{ $section->scheduledInstructor }}</td>
                <td>{{ $summary }}</td>
                <td>{{ \App\Models\Section::hoursFromMinutes($minutes['theory']) }}</td>
                <td>{{ \App\Models\Section::hoursFromMinutes($minutes['practical']) }}</td>
                <td>{{ \App\Models\Section::hoursFromMinutes($minutes['field']) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

@if ($timetable->hasErrors())
    <div class="alert alert-danger">{{ __('app.sections.confirm_blocked') }}</div>
@else
    <form method="post" action="{{ route('admin.sections.import.confirm') }}" class="d-inline">
        @csrf
        <button type="submit" class="btn btn-eet">{{ __('app.sections.confirm') }}</button>
    </form>
@endif
<a href="{{ route('admin.sections.import.form') }}" class="btn btn-outline-secondary">{{ __('app.common.cancel') }}</a>
@endsection

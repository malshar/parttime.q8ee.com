@extends('admin.layout')
@section('title', __('app.assignments.title'))
@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">{{ __('app.assignments.title') }}</h1>
</div>

@include('admin.sections._filters', ['routeName' => 'admin.assignments.index', 'showUnassigned' => false])

@if (! $term)
    <div class="alert alert-info">{{ __('app.terms.none_open') }}</div>
@elseif ($approved->isEmpty())
    <div class="alert alert-info">{{ __('app.assignments.no_approved') }}</div>
@elseif ($sections->isEmpty() && array_filter($filters))
    <div class="alert alert-info">{{ __('app.sections.no_matches') }}</div>
@else
    <div class="row">
        <div class="col-lg-9">
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                    <tr>
                        <th>{{ __('app.sections.reference') }}</th>
                        <th>{{ __('app.sections.course') }}</th>
                        <th>{{ __('app.sections.section') }}</th>
                        <th>{{ __('app.sections.course_name') }}</th>
                        <th>{{ __('app.sections.meetings') }}</th>
                        <th>{{ __('app.sections.hours_theory') }}</th>
                        <th>{{ __('app.sections.hours_practical') }}</th>
                        <th>{{ __('app.sections.hours_field') }}</th>
                        <th>{{ __('app.sections.scheduled_instructor') }}</th>
                        <th>{{ __('app.assignments.title') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($sections as $s)
                        @php($hours = $s->weeklyMinutesByType())
                        <tr>
                            <td>{{ $s->reference_number }}</td>
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
                            <td>
                                @if ($s->assignment)
                                    <div>{{ $s->assignment->application->instructor->full_name }}</div>
                                    @if ($term->isOpen())
                                        <form method="post" action="{{ route('admin.assignments.destroy', $s) }}" onsubmit="return confirm(@js(__('app.assignments.unassign_confirm')))">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('app.assignments.unassign') }}</button>
                                        </form>
                                    @endif
                                @elseif ($term->isOpen())
                                    <form method="post" action="{{ route('admin.assignments.store', $s) }}" class="d-flex gap-1 align-items-center flex-wrap">
                                        @csrf
                                        <select name="application_id" class="form-select form-select-sm">
                                            <option value="">{{ __('app.assignments.choose') }}</option>
                                            @foreach ($approved as $a)
                                                <option value="{{ $a->id }}" @selected(($suggestions[$s->id] ?? null) === $a->id)>{{ $a->instructor->full_name }}</option>
                                            @endforeach
                                        </select>
                                        @if (isset($suggestions[$s->id]))
                                            <span class="badge bg-info">{{ __('app.assignments.suggested') }}</span>
                                        @endif
                                        <button type="submit" class="btn btn-sm btn-eet">{{ __('app.assignments.assign') }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <div class="col-lg-3">
            <h2 class="h6">{{ __('app.assignments.totals') }}</h2>
            <ul class="list-group">
                @foreach ($totals as $t)
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span>{{ $t['name'] }}</span>
                        <span class="text-muted small">{{ $t['count'] }} — {{ $t['hours'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endif

@endsection

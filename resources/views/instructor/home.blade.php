@extends('instructor.layout')
@section('title', __('app.home.title'))
@section('content')
<div class="row justify-content-center"><div class="col-md-10">

    <div class="card mb-3">
        <div class="card-body d-flex justify-content-between align-items-center">
            <div>
                <h1 class="h5 mb-1">{{ $instructor->full_name }}</h1>
                <div class="text-muted" dir="ltr">{{ $instructor->maskedCivilId() }}</div>
            </div>
            <a href="{{ route('instructor.profile.edit') }}" class="btn btn-outline-secondary btn-sm">{{ __('app.profile.title') }}</a>
        </div>
    </div>

    <h2 class="h6">{{ __('app.applications.current') }}</h2>
    @if (! $term)
        <div class="alert alert-info">{{ __('app.terms.none_open') }}</div>
    @elseif ($current)
        <div class="card mb-3">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <div>{{ $term->label() }}</div>
                    <span class="badge bg-secondary">{{ __('app.applications.statuses.'.$current->status) }}</span>
                </div>
                <a href="{{ route('instructor.applications.show', $current) }}" class="btn btn-eet btn-sm">{{ __('app.applications.title') }}</a>
            </div>
        </div>
    @else
        <form method="post" action="{{ route('instructor.applications.start') }}" class="mb-3">
            @csrf
            <button type="submit" class="btn btn-eet">{{ __('app.applications.start') }}</button>
        </form>
    @endif

    @if ($current && $current->status === \App\Models\Application::STATUS_APPROVED)
        <h2 class="h6 mt-4">{{ __('app.assignments.my_sections') }}</h2>
        <div class="card mb-3">
            <div class="card-body">
                @if ($assigned->isEmpty())
                    <p class="text-muted mb-0">{{ __('app.assignments.none') }}</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-striped align-middle mb-2">
                            <thead>
                            <tr>
                                <th>{{ __('app.sections.course') }}</th>
                                <th>{{ __('app.sections.meetings') }}</th>
                                <th>{{ __('app.sections.hours_theory') }}</th>
                                <th>{{ __('app.sections.hours_practical') }}</th>
                                <th>{{ __('app.sections.hours_field') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($assigned as $s)
                                @php($hours = $s->weeklyMinutesByType())
                                <tr>
                                    <td>{{ $s->label() }}</td>
                                    <td>{{ $s->meetingSummary() }}</td>
                                    <td>{{ \App\Models\Section::hoursFromMinutes($hours['theory']) }}</td>
                                    <td>{{ \App\Models\Section::hoursFromMinutes($hours['practical']) }}</td>
                                    <td>{{ \App\Models\Section::hoursFromMinutes($hours['field']) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                <div><strong>{{ __('app.assignments.weekly_hours') }}:</strong> {{ $current->weeklyHoursLabel() }}</div>
            </div>
        </div>
    @endif

    @if ($past->isNotEmpty())
        <h2 class="h6 mt-4">{{ __('app.applications.past') }}</h2>
        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead>
                <tr>
                    <th>{{ __('app.terms.title') }}</th>
                    <th>{{ __('app.terms.status') }}</th>
                    <th>{{ __('app.common.actions') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($past as $application)
                    <tr>
                        <td>{{ $application->term->label() }}</td>
                        <td><span class="badge bg-secondary">{{ __('app.applications.statuses.'.$application->status) }}</span></td>
                        <td><a href="{{ route('instructor.applications.show', $application) }}" class="btn btn-outline-secondary btn-sm">{{ __('app.applications.title') }}</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

</div></div>
@endsection

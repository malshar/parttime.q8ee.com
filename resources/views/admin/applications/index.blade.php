@extends('admin.layout')
@section('title', __('app.review.applications'))
@section('content')

<h1 class="h4 mb-3">{{ __('app.review.applications') }}</h1>

<form method="get" action="{{ route('admin.applications.index') }}" class="row g-2 mb-3">
    <div class="col-auto">
        <select name="term" class="form-select form-select-sm" onchange="this.form.submit()">
            @foreach ($terms as $t)
                <option value="{{ $t->id }}" @selected($term && $term->id === $t->id)>{{ $t->label() }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">{{ __('app.review.all_statuses') }}</option>
            @foreach (['draft', 'submitted', 'under_review', 'incomplete', 'approved', 'rejected', 'withdrawn'] as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ __('app.applications.statuses.'.$status) }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-outline-secondary">{{ __('app.review.filter') }}</button>
    </div>
</form>

<div class="table-responsive">
    <table class="table table-striped align-middle">
        <thead>
        <tr>
            <th>{{ __('app.review.applicant') }}</th>
            <th>{{ __('app.review.term') }}</th>
            <th>{{ __('app.applications.title') }}</th>
            <th>{{ __('app.review.submitted_at') }}</th>
            <th>{{ __('app.common.actions') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($applications as $application)
            <tr>
                <td>{{ $application->instructor->full_name }}</td>
                <td>{{ $application->term->label() }}</td>
                <td><span class="badge bg-secondary">{{ __('app.applications.statuses.'.$application->status) }}</span></td>
                <td>{{ format_date($application->submitted_at) }}</td>
                <td><a href="{{ route('admin.applications.show', $application) }}" class="btn btn-sm btn-eet">{{ __('app.review.open') }}</a></td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

{{ $applications->links() }}

@endsection

@extends('admin.layout')
@section('title', __('app.site_name'))
@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">{{ $term ? $term->label() : __('app.terms.none_open') }}</h1>
    <a href="{{ route('admin.applications.index') }}" class="btn btn-outline-secondary btn-sm">{{ __('app.review.applications') }}</a>
</div>

<div class="d-flex flex-wrap gap-2 mb-4">
    @foreach (['submitted', 'under_review', 'incomplete', 'approved', 'rejected', 'withdrawn'] as $status)
        <span class="badge bg-secondary">
            {{ __('app.applications.statuses.'.$status) }}: {{ $counts[$status] ?? 0 }}
        </span>
    @endforeach
</div>

<h2 class="h6">{{ __('app.review.attention') }}</h2>
@if ($attention->isEmpty())
    <div class="alert alert-info">{{ __('app.review.no_attention') }}</div>
@else
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
            @foreach ($attention as $application)
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
@endif

@endsection

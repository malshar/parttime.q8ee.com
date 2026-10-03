@extends('admin.layout')
@section('title', $instructor->full_name)
@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">{{ $instructor->full_name }} — <span dir="ltr">{{ $instructor->maskedCivilId() }}</span></h1>
    <a href="{{ route('admin.instructors.bundle', $instructor) }}" class="btn btn-eet btn-sm">{{ __('app.bundle.download') }}</a>
</div>

<h2 class="h6">{{ __('app.review.approvals') }}</h2>
@if ($approvals->isEmpty())
    <p class="text-muted">{{ __('app.renewals.no_recorded') }}</p>
@else
    <div class="table-responsive mb-4">
        <table class="table table-striped align-middle">
            <thead>
            <tr>
                <th>{{ __('app.renewals.year') }}</th>
                <th>{{ __('app.review.committee_outcome') }}</th>
                <th>{{ __('app.review.committee_met_on') }}</th>
                <th>{{ __('app.review.committee_reference') }}</th>
                <th>{{ __('app.renewals.note') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($approvals as $approval)
                <tr>
                    <td>{{ $approval->academic_year }}</td>
                    <td>
                        <span class="badge bg-info text-dark">{{ __('app.review.approval_kinds.'.$approval->kind) }}</span>
                        @if ($approval->isApproved())
                            <span class="badge bg-success">{{ __('app.review.outcomes.approved') }}</span>
                        @else
                            <span class="badge bg-secondary">{{ __('app.renewals.outcomes.not_renewed') }}</span>
                        @endif
                    </td>
                    <td>{{ format_date($approval->committee_met_on) }}</td>
                    <td>{{ $approval->committee_reference }}</td>
                    <td>{{ $approval->note }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif

<h2 class="h6">{{ __('app.review.applications') }}</h2>
@if ($applications->isEmpty())
    <p class="text-muted">{{ __('app.review.no_applications') }}</p>
@else
    <div class="table-responsive">
        <table class="table table-striped align-middle">
            <thead>
            <tr>
                <th>{{ __('app.review.term') }}</th>
                <th>{{ __('app.applications.title') }}</th>
                <th>{{ __('app.review.view') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($applications as $application)
                <tr>
                    <td>{{ $application->term->label() }}</td>
                    <td>
                        <span class="badge bg-info text-dark">{{ __('app.applications.kinds.'.$application->kind) }}</span>
                        <span class="badge bg-secondary">{{ __('app.applications.statuses.'.$application->status) }}</span>
                    </td>
                    <td><a href="{{ route('admin.applications.show', $application) }}" class="btn btn-sm btn-outline-secondary">{{ __('app.review.view') }}</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
@endsection

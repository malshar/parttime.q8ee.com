@extends('admin.layout')
@section('title', __('app.site_name'))
@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">{{ $term ? $term->label() : __('app.terms.none_open') }}</h1>
    <a href="{{ route('admin.applications.index') }}" class="btn btn-outline-secondary btn-sm">{{ __('app.review.applications') }}</a>
</div>

<div class="d-flex flex-wrap gap-2 mb-4">
    @foreach (\App\Models\Application::STATUSES as $status)
        <span class="badge bg-secondary">
            {{ __('app.applications.statuses.'.$status) }}: {{ $counts[$status] ?? 0 }}
        </span>
    @endforeach
</div>

<h2 class="h6">{{ __('app.review.group_department') }}</h2>
@include('admin._attention_table', ['rows' => $department, 'dateField' => 'submitted_at'])

<h2 class="h6 mt-4">{{ __('app.review.group_committee') }}</h2>
@if ($committee->isEmpty())
    <div class="alert alert-info">{{ __('app.review.no_attention') }}</div>
@else
    <div class="table-responsive">
        <table class="table table-striped align-middle">
            <thead><tr>
                <th>{{ __('app.review.applicant') }}</th>
                <th>{{ __('app.review.term') }}</th>
                <th>{{ __('app.review.complete_at') }}</th>
                <th>{{ __('app.review.waiting') }}</th>
                <th>{{ __('app.common.actions') }}</th>
            </tr></thead>
            <tbody>
            @foreach ($committee as $application)
                <tr>
                    <td>{{ $application->instructor->full_name }}</td>
                    <td>{{ $application->term->label() }}</td>
                    <td>{{ format_date($application->complete_at) }}</td>
                    <td>{{ __('app.review.waiting_days', ['days' => (int) $application->complete_at->diffInDays(now())]) }}</td>
                    <td><a href="{{ route('admin.applications.show', $application) }}" class="btn btn-sm btn-eet">{{ __('app.review.open') }}</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif

<h2 class="h6 mt-4">{{ __('app.review.group_awaiting_documents') }}</h2>
@if ($awaitingDocuments->isEmpty())
    <div class="alert alert-info">{{ __('app.review.no_awaiting_documents') }}</div>
@else
    <ul>
        @foreach ($awaitingDocuments as $a)
            <li><a href="{{ route('admin.applications.show', $a) }}">{{ $a->instructor->full_name }}</a> — {{ implode('، ', $a->missing) }}</li>
        @endforeach
    </ul>
@endif

<h2 class="h6 mt-4">{{ __('app.review.group_alerts') }}</h2>
@if ($alerts->isEmpty())
    <div class="alert alert-info">{{ __('app.review.no_alerts') }}</div>
@else
    <ul class="list-group">
        @foreach ($alerts as $alert)
            <li class="list-group-item d-flex justify-content-between">
                <span>{{ $alert['text'] }}</span>
                <a href="{{ $alert['url'] }}" class="btn btn-sm btn-outline-secondary">{{ __('app.review.open') }}</a>
            </li>
        @endforeach
    </ul>
@endif

@endsection

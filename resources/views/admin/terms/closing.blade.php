@extends('admin.layout')
@section('title', __('app.terms.closing_title'))
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">{{ __('app.terms.closing_title') }} — {{ $term->label() }}</h1>
</div>

<div class="d-flex gap-2 mb-3">
    <span class="badge bg-primary">{{ __('app.terms.approved_count') }}: {{ $counts['approved'] }}</span>
    <span class="badge bg-warning text-dark">{{ __('app.terms.months_unexported') }}: {{ $counts['months_unexported'] }}</span>
    <span class="badge bg-danger">{{ __('app.terms.continuations_missing') }}: {{ $counts['continuations_missing'] }}</span>
</div>

<div class="table-responsive">
    <table class="table table-striped align-middle">
        <thead>
        <tr>
            <th>{{ __('app.attestations.instructor') }}</th>
            <th>{{ __('app.attestations.weekly_hours') }}</th>
            <th>{{ __('app.terms.months') }}</th>
            <th>{{ __('app.terms.next_term') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($rows as $row)
            <tr>
                <td><a href="{{ route('admin.applications.show', $row['application']) }}">{{ $row['application']->instructor->full_name }}</a></td>
                <td>{{ $row['application']->weeklyHoursLabel() }}</td>
                <td>
                    @foreach ($row['months'] as $m)
                        <span class="badge {{ $m['status'] === 'exported' ? 'bg-success' : ($m['status'] === 'generated' ? 'bg-warning text-dark' : 'bg-secondary') }}">{{ $m['label'] }}</span>
                    @endforeach
                </td>
                <td>
                    @if (! $row['nextTerm'])
                        {{ __('app.terms.no_next_term') }}
                    @elseif (! $row['next'])
                        {{ __('app.terms.continuation_none') }}
                    @else
                        <span class="badge bg-secondary">{{ __('app.applications.statuses.'.$row['next']->status) }}</span>
                        <a href="{{ route('admin.applications.show', $row['next']) }}">{{ __('app.attestations.open') }}</a>
                        @if (! empty($row['nextMissing']))
                            <div class="small text-muted">{{ implode('، ', $row['nextMissing']) }}</div>
                        @endif
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

@if ($term->isOpen())
    <form method="post" action="{{ route('admin.terms.close', $term) }}" onsubmit="return confirm(@js(__('app.terms.close_confirm')))">
        @csrf
        <button type="submit" class="btn btn-outline-danger">{{ __('app.terms.close') }}</button>
    </form>
@endif
@endsection

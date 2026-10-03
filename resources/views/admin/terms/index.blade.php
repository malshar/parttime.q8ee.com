@extends('admin.layout')
@section('title', __('app.terms.title'))
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">{{ __('app.terms.title') }}</h1>
    <a href="{{ route('admin.terms.create') }}" class="btn btn-eet">{{ __('app.terms.add') }}</a>
</div>
@if (session('close_blockers'))
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach (session('close_blockers') as $b)
                <li><a href="{{ $b['url'] }}">{{ $b['name'] }}</a> — {{ $b['status'] }}</li>
            @endforeach
        </ul>
    </div>
@endif
<div class="table-responsive">
    <table class="table table-striped align-middle">
        <thead>
        <tr>
            <th>{{ __('app.terms.title') }}</th>
            <th>{{ __('app.terms.teaching_starts_on') }}</th>
            <th>{{ __('app.terms.teaching_ends_on') }}</th>
            <th>{{ __('app.terms.status') }}</th>
            <th>{{ __('app.terms.applications') }}</th>
            <th>{{ __('app.common.actions') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($terms as $term)
            <tr>
                <td>{{ $term->label() }}</td>
                <td>{{ format_date($term->teaching_starts_on) }}</td>
                <td>{{ format_date($term->teaching_ends_on) }}</td>
                <td>
                    <span class="badge {{ $term->isOpen() ? 'bg-success' : 'bg-secondary' }}">
                        {{ __('app.terms.statuses.'.$term->status) }}
                    </span>
                </td>
                <td>{{ $term->applications_count }}</td>
                <td class="d-flex gap-2">
                    <a href="{{ route('admin.terms.closing', $term) }}" class="btn btn-sm btn-outline-secondary">{{ __('app.terms.closing') }}</a>
                    @if ($term->isOpen())
                        <a href="{{ route('admin.terms.edit', $term) }}" class="btn btn-sm btn-outline-secondary">{{ __('app.terms.edit') }}</a>
                        <form method="post" action="{{ route('admin.terms.close', $term) }}" onsubmit="return confirm(@js(__('app.terms.close_confirm')))">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('app.terms.close') }}</button>
                        </form>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection

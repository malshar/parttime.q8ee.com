@extends('admin.layout')
@section('title', __('app.attestations.title'))
@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">{{ __('app.attestations.title') }}</h1>
</div>

@if ($errors->has('attestation') || $errors->has('export'))
    <div class="alert alert-danger">{{ $errors->first('attestation') ?: $errors->first('export') }}</div>
@endif
@if (session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif

<form method="get" action="{{ route('admin.attestations.index') }}" class="row g-2 align-items-center mb-3">
    <div class="col-auto">
        <label class="visually-hidden" for="term">{{ __('app.attestations.term') }}</label>
        <select id="term" name="term" class="form-select" onchange="if (this.form.month) { this.form.month.value=''; } this.form.submit()">
            @foreach ($terms as $t)
                <option value="{{ $t->id }}" @selected($term && $term->id === $t->id)>{{ $t->label() }}</option>
            @endforeach
        </select>
    </div>
    @if ($term)
        <div class="col-auto">
            <label class="visually-hidden" for="month">{{ __('app.attestations.month') }}</label>
            <select id="month" name="month" class="form-select" onchange="this.form.submit()">
                @foreach ($months as $m)
                    <option value="{{ $m['index'] }}" @selected($month && $month['index'] === $m['index'])>{{ $m['label'] }}</option>
                @endforeach
            </select>
        </div>
    @endif
</form>

@if (! $term)
    <div class="alert alert-info">{{ __('app.terms.none_open') }}</div>
@elseif ($rows->isEmpty())
    <div class="alert alert-info">{{ __('app.attestations.no_listed') }}</div>
@else
    <div class="d-flex gap-2 mb-3">
        @if ($term->isOpen())
            <form method="post" action="{{ route('admin.attestations.generate') }}">
                @csrf
                <input type="hidden" name="term" value="{{ $term->id }}">
                <input type="hidden" name="month" value="{{ $month['index'] }}">
                <button type="submit" class="btn btn-eet">{{ __('app.attestations.generate_missing') }}</button>
            </form>
        @endif
        @if (Route::has('admin.attestations.combined'))
            <a class="btn btn-outline-secondary @if (($existingCount ?? 0) === 0) disabled @endif" href="{{ route('admin.attestations.combined', ['term' => $term->id, 'month' => $month['index']]) }}">{{ __('app.attestations.combined_pdf') }}</a>
        @endif
    </div>

    <div class="table-responsive">
        <table class="table table-striped align-middle">
            <thead>
            <tr>
                <th>{{ __('app.attestations.instructor') }}</th>
                <th>{{ __('app.attestations.weekly_hours') }}</th>
                <th>{{ __('app.attestations.status') }}</th>
                <th>{{ __('app.attestations.generated_at') }}</th>
                <th>{{ __('app.attestations.exported_at') }}</th>
                <th>{{ __('app.common.actions') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($rows as $row)
                @php($a = $row['attestation'])
                <tr>
                    <td>{{ $row['application']->instructor->full_name }}</td>
                    <td>{{ $row['application']->weeklyHoursLabel() }}</td>
                    <td>
                        @if (! $a)
                            <span class="badge bg-secondary">{{ __('app.attestations.status_none') }}</span>
                        @elseif ($a->isExported())
                            <span class="badge bg-success">{{ __('app.attestations.status_exported') }}</span>
                        @else
                            <span class="badge bg-warning text-dark">{{ __('app.attestations.status_generated') }}</span>
                        @endif
                    </td>
                    <td>{{ $a?->generated_at?->format('Y-m-d') }}</td>
                    <td>{{ $a?->exported_at?->format('Y-m-d') }}</td>
                    <td>
                        @if ($a && Route::has('admin.attestations.show'))
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.attestations.show', $a) }}">{{ __('app.attestations.open') }}</a>
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.attestations.download', [$a, 'format' => 'docx']) }}">{{ __('app.attestations.word') }}</a>
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.attestations.download', [$a, 'format' => 'pdf']) }}">{{ __('app.attestations.pdf') }}</a>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
@endsection

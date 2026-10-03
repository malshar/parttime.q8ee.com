@extends('admin.layout')
@section('title', __('app.renewals.title'))
@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">{{ __('app.renewals.title') }}</h1>
    <a href="{{ route('admin.renewals.list', ['year' => $year]) }}" class="btn btn-outline-secondary btn-sm">{{ __('app.renewals.list') }}</a>
</div>

<form method="get" action="{{ route('admin.renewals.index') }}" class="row g-2 align-items-end mb-4">
    <div class="col-auto">
        <label class="form-label">{{ __('app.renewals.year') }}</label>
        <input type="text" name="year" value="{{ $year }}" class="form-control form-control-sm" style="width: 10rem" dir="ltr">
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-outline-primary btn-sm">{{ __('app.review.filter') }}</button>
    </div>
</form>

<form method="post" action="{{ route('admin.renewals.store') }}" onsubmit="return confirm(@js(__('app.renewals.record_confirm')))">
    @csrf
    <input type="hidden" name="year" value="{{ $year }}">

    <div class="row g-2 mb-3">
        <div class="col-auto">
            <label class="form-label">{{ __('app.review.committee_met_on') }}</label>
            <input type="date" name="committee_met_on" value="{{ old('committee_met_on') }}" class="form-control form-control-sm" required>
        </div>
        <div class="col-auto">
            <label class="form-label">{{ __('app.review.committee_reference') }}</label>
            <input name="committee_reference" value="{{ old('committee_reference') }}" class="form-control form-control-sm" maxlength="60" required>
        </div>
    </div>

    <h2 class="h6">{{ __('app.renewals.candidates') }}</h2>
    @if ($candidates->isEmpty())
        <p class="text-muted">{{ __('app.renewals.no_candidates') }}</p>
    @else
        <div class="table-responsive mb-3">
            <table class="table table-striped align-middle">
                <thead>
                <tr>
                    <th>{{ __('app.profile.full_name') }}</th>
                    <th>{{ __('app.profile.employer') }}</th>
                    <th>{{ __('app.profile.highest_degree') }}</th>
                    <th>{{ __('app.renewals.last_term') }}</th>
                    <th>{{ __('app.renewals.outcome') }}</th>
                    <th>{{ __('app.renewals.note') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($candidates as $i)
                    <tr>
                        <td>{{ $i->full_name }}</td>
                        <td>{{ $i->employer }}</td>
                        <td>{{ __('app.profile.degrees.'.$i->highest_degree) }}</td>
                        <td>
                            @if ($i->lastTerm)
                                <a href="{{ route('admin.applications.index', ['term' => $i->lastTerm->id]) }}">{{ $i->lastTerm->label() }}</a>
                            @endif
                        </td>
                        <td>
                            <select name="rows[{{ $i->id }}][outcome]" class="form-select form-select-sm">
                                <option value="">—</option>
                                <option value="renewed" @selected(old('rows.'.$i->id.'.outcome') === 'renewed')>{{ __('app.renewals.outcomes.renewed') }}</option>
                                <option value="not_renewed" @selected(old('rows.'.$i->id.'.outcome') === 'not_renewed')>{{ __('app.renewals.outcomes.not_renewed') }}</option>
                            </select>
                        </td>
                        <td>
                            <input name="rows[{{ $i->id }}][note]" value="{{ old('rows.'.$i->id.'.note') }}" class="form-control form-control-sm" maxlength="500">
                            <div class="form-text">{{ __('app.renewals.note_hint') }}</div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <button type="submit" class="btn btn-eet">{{ __('app.renewals.record') }}</button>
    @endif
</form>

<h2 class="h6 mt-4">{{ __('app.renewals.recorded_rows') }}</h2>
@if ($recorded->isEmpty())
    <p class="text-muted">{{ __('app.renewals.no_recorded') }}</p>
@else
    <div class="table-responsive">
        <table class="table table-striped align-middle">
            <thead>
            <tr>
                <th>{{ __('app.profile.full_name') }}</th>
                <th>{{ __('app.renewals.outcome') }}</th>
                <th>{{ __('app.review.committee_met_on') }}</th>
                <th>{{ __('app.review.committee_reference') }}</th>
                <th>{{ __('app.common.actions') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($recorded as $row)
                @php($deletable = $row->kind === \App\Models\CommitteeApproval::KIND_RENEWAL && $row->applications->every(fn ($a) => $a->status === \App\Models\Application::STATUS_DRAFT))
                <tr>
                    <td>{{ $row->instructor->full_name }}</td>
                    <td>
                        <span class="badge bg-info text-dark">{{ __('app.review.approval_kinds.'.$row->kind) }}</span>
                        @if ($row->kind === \App\Models\CommitteeApproval::KIND_RENEWAL)
                            @if ($row->isApproved())
                                <span class="badge bg-success">{{ __('app.renewals.outcomes.renewed') }}</span>
                            @else
                                <span class="badge bg-secondary">{{ __('app.renewals.outcomes.not_renewed') }}</span>
                            @endif
                        @else
                            <span class="badge bg-success">{{ __('app.review.outcomes.approved') }}</span>
                        @endif
                    </td>
                    <td>{{ format_date($row->committee_met_on) }}</td>
                    <td>{{ $row->committee_reference }}</td>
                    <td>
                        @if ($deletable)
                            <form method="post" action="{{ route('admin.renewals.destroy', $row) }}" onsubmit="return confirm(@js(__('app.renewals.delete_confirm')))">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('app.renewals.delete') }}</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
@endsection

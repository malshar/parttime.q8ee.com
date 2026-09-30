@extends('admin.layout')
@section('title', __('app.attestations.title'))
@section('content')
@php($app = $attestation->application)
@php($i = $app->instructor)
@php($editable = $term->isOpen() && ! $attestation->isExported())

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">{{ __('app.attestations.title') }} — {{ $i->full_name }} — {{ $attestation->monthTitle() }}</h1>
    <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.attestations.index', ['term' => $term->id, 'month' => $attestation->monthIndex()]) }}">{{ __('app.attestations.back_to_month') }}</a>
</div>

@if ($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif
@if (session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif
@if ($attestation->isExported())
    <div class="alert alert-warning">{{ __('app.attestations.locked_notice') }}</div>
@endif

<div class="card mb-3"><div class="card-body">
    <div class="row">
        <div class="col-md-4"><strong>{{ __('app.terms.type') }}:</strong> {{ $term->label() }}</div>
        <div class="col-md-4"><strong>{{ __('app.attestations.decision_number') }}:</strong> {{ $app->assignment_decision_number ?: '—' }}</div>
        <div class="col-md-4"><strong>{{ __('app.attestations.decision_date') }}:</strong> {{ $app->assignment_decision_date?->format('Y/m/d') ?: '—' }}</div>
        <div class="col-md-4"><strong>{{ __('app.profile.job_title') }}:</strong> {{ $i->job_title }}</div>
        <div class="col-md-4"><strong>{{ __('app.profile.employer') }}:</strong> {{ $i->employer }}</div>
        <div class="col-md-4"><strong>{{ __('app.attestations.weekly_hours') }}:</strong> {{ $app->weeklyHoursLabel() }}</div>
        <div class="col-md-4"><strong>{{ __('app.attestations.status') }}:</strong>
            {{ $attestation->isExported() ? __('app.attestations.status_exported') : __('app.attestations.status_generated') }}
            @if ($attestation->exported_at) ({{ $attestation->exported_at->format('Y-m-d H:i') }}) @endif
        </div>
    </div>
</div></div>

<form method="post" action="{{ route('admin.attestations.update', $attestation) }}">
    @csrf
    @method('PUT')
    <div class="table-responsive">
        <table class="table table-bordered align-middle">
            <thead>
            <tr>
                <th>{{ __('app.attestations.week') }}</th>
                <th>{{ __('app.attestations.dates') }}</th>
                <th>{{ __('app.attestations.courses') }}</th>
                <th>{{ __('app.attestations.students') }}</th>
                <th>{{ __('app.attestations.theory') }}</th>
                <th>{{ __('app.attestations.practical') }}</th>
                <th>{{ __('app.attestations.field') }}</th>
                <th>{{ __('app.attestations.total') }}</th>
                <th>{{ __('app.attestations.note') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($attestation->weeks as $w)
                @php($n = "weeks[{$w->id}]")
                <tr>
                    <td>{{ $w->week_number }}</td>
                    <td>{{ $w->datesLabel() }}</td>
                    <td>
                        <textarea name="{{ $n }}[courses_text]" class="form-control form-control-sm" rows="2" @disabled(! $editable)>{{ old("weeks.{$w->id}.courses_text", $w->courses_text) }}</textarea>
                        @if ($w->courses_text !== $w->generated_courses_text)<div class="form-text">{{ __('app.attestations.generated_value', ['value' => $w->generated_courses_text]) }}</div>@endif
                    </td>
                    <td>
                        <input type="number" min="0" name="{{ $n }}[student_count]" class="form-control form-control-sm" value="{{ old("weeks.{$w->id}.student_count", $w->student_count) }}" @disabled(! $editable)>
                        @if ((int) $w->student_count !== (int) $w->generated_student_count)<div class="form-text">{{ __('app.attestations.generated_value', ['value' => $w->generated_student_count]) }}</div>@endif
                    </td>
                    @foreach (['theory', 'practical', 'field'] as $type)
                        <td>
                            <input type="text" inputmode="decimal" name="{{ $n }}[{{ $type }}_hours]" class="form-control form-control-sm" value="{{ old("weeks.{$w->id}.{$type}_hours", \App\Models\Section::hoursForForm((int) $w->{$type.'_minutes'})) }}" @disabled(! $editable)>
                            @if ((int) $w->{$type.'_minutes'} !== (int) $w->{'generated_'.$type.'_minutes'})<div class="form-text">{{ __('app.attestations.generated_value', ['value' => \App\Models\Section::hoursForForm((int) $w->{'generated_'.$type.'_minutes'})]) }}</div>@endif
                        </td>
                    @endforeach
                    <td>{{ \App\Models\Section::hoursForForm($w->totalMinutes()) }}</td>
                    <td>
                        <textarea name="{{ $n }}[note_ar]" class="form-control form-control-sm" rows="2" @disabled(! $editable)>{{ old("weeks.{$w->id}.note_ar", $w->note_ar) }}</textarea>
                        @if ($w->note_ar !== $w->generated_note_ar)<div class="form-text">{{ __('app.attestations.generated_value', ['value' => $w->generated_note_ar]) }}</div>@endif
                    </td>
                </tr>
            @endforeach
            <tr class="table-secondary fw-bold">
                <td colspan="3">{{ __('app.attestations.monthly_total') }}</td>
                <td>{{ $totals['student_count'] }}</td>
                <td>{{ \App\Models\Section::hoursForForm($totals['theory_minutes']) }}</td>
                <td>{{ \App\Models\Section::hoursForForm($totals['practical_minutes']) }}</td>
                <td>{{ \App\Models\Section::hoursForForm($totals['field_minutes']) }}</td>
                <td>{{ \App\Models\Section::hoursForForm($totals['total_minutes']) }}</td>
                <td></td>
            </tr>
            </tbody>
        </table>
    </div>
    @if ($editable)
        <button type="submit" class="btn btn-eet">{{ __('app.common.save') }}</button>
    @endif
</form>

<div class="d-flex gap-2 mt-3">
    @if ($editable)
        <form method="post" action="{{ route('admin.attestations.regenerate', $attestation) }}" onsubmit="return confirm(@js(__('app.attestations.regenerate_confirm')))">
            @csrf
            <button type="submit" class="btn btn-outline-danger">{{ __('app.attestations.regenerate') }}</button>
        </form>
    @elseif ($term->isOpen() && $attestation->isExported())
        <form method="post" action="{{ route('admin.attestations.unlock', $attestation) }}">
            @csrf
            <button type="submit" class="btn btn-outline-warning">{{ __('app.attestations.unlock') }}</button>
        </form>
    @endif
    <a class="btn btn-outline-secondary" href="{{ route('admin.attestations.download', [$attestation, 'format' => 'docx']) }}">{{ __('app.attestations.word') }}</a>
    <a class="btn btn-outline-secondary" href="{{ route('admin.attestations.download', [$attestation, 'format' => 'pdf']) }}">{{ __('app.attestations.pdf') }}</a>
</div>
@endsection

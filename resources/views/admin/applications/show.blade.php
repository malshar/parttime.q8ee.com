@extends('admin.layout')
@section('title', $instructor->full_name)
@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">{{ $instructor->full_name }} — {{ $application->term->label() }}</h1>
    <span class="badge bg-secondary">{{ __('app.applications.statuses.'.$application->status) }}</span>
</div>

@php($termOpen = $application->term->isOpen())

@if (! $termOpen)
    <div class="alert alert-warning">{{ __('app.applications.term_closed') }}</div>
@endif

@if ($application->status === \App\Models\Application::STATUS_REJECTED && $application->rejection_reason)
    <div class="alert alert-danger">{{ $application->rejection_reason }}</div>
@endif

{{-- 1. Profile card --}}
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>{{ __('app.review.profile') }}</span>
        @if (! $revealed)
            <form method="post" action="{{ route('admin.applications.reveal', $application) }}">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('app.review.reveal') }}</button>
            </form>
        @else
            <span class="badge bg-warning text-dark">{{ __('app.review.revealed') }}</span>
        @endif
    </div>
    <div class="card-body">
        @if ($instructor->highest_degree === 'bachelor' && (int) $instructor->experience_years < 10)
            <div class="alert alert-warning py-2">{{ __('app.review.experience_below_min', ['years' => $instructor->experience_years]) }}</div>
        @endif
        <div class="row">
            <div class="col-md-4 mb-2"><strong>{{ __('app.profile.full_name') }}:</strong> {{ $instructor->full_name }}</div>
            <div class="col-md-4 mb-2" dir="ltr"><strong>{{ __('app.profile.civil_id') }}:</strong> {{ $revealed ? $instructor->civil_id : $instructor->maskedCivilId() }}</div>
            <div class="col-md-4 mb-2"><strong>{{ __('app.profile.civil_id_expires_on') }}:</strong> {{ format_date($instructor->civil_id_expires_on) }}</div>
        </div>
        <div class="row">
            <div class="col-md-4 mb-2"><strong>{{ __('app.profile.nationality') }}:</strong> {{ $instructor->nationality }}</div>
            <div class="col-md-4 mb-2" dir="ltr"><strong>{{ __('app.profile.mobile') }}:</strong> {{ $instructor->mobile }}</div>
            <div class="col-md-4 mb-2" dir="ltr"><strong>{{ __('app.profile.work_phone') }}:</strong> {{ $instructor->work_phone }}</div>
        </div>
        <div class="row">
            <div class="col-md-4 mb-2"><strong>{{ __('app.profile.employer') }}:</strong> {{ $instructor->employer }}</div>
            <div class="col-md-4 mb-2"><strong>{{ __('app.profile.employer_sector') }}:</strong> {{ __('app.profile.sectors.'.$instructor->employer_sector) }}</div>
            <div class="col-md-4 mb-2"><strong>{{ __('app.profile.job_title') }}:</strong> {{ $instructor->job_title }}</div>
        </div>
        <div class="row">
            <div class="col-md-4 mb-2"><strong>{{ __('app.profile.highest_degree') }}:</strong> {{ __('app.profile.degrees.'.$instructor->highest_degree) }}</div>
            <div class="col-md-4 mb-2"><strong>{{ __('app.profile.degree_title') }}:</strong> {{ $instructor->degree_title }}</div>
            <div class="col-md-4 mb-2"><strong>{{ __('app.profile.degree_country') }}:</strong> {{ __('app.countries.'.$instructor->degree_country) }}</div>
        </div>
        <div class="row">
            <div class="col-md-4 mb-2"><strong>{{ __('app.profile.degree_obtained_on') }}:</strong> {{ format_date($instructor->degree_obtained_on) }}</div>
            <div class="col-md-4 mb-2"><strong>{{ __('app.profile.experience_years') }}:</strong> {{ $instructor->experience_years }}</div>
        </div>
        <div class="row">
            <div class="col-md-4 mb-2"><strong>{{ __('app.profile.bank_name') }}:</strong> {{ $instructor->bank_name }}</div>
            <div class="col-md-4 mb-2"><strong>{{ __('app.profile.bank_branch') }}:</strong> {{ $instructor->bank_branch }}</div>
            <div class="col-md-4 mb-2" dir="ltr"><strong>{{ __('app.profile.iban') }}:</strong> {{ $revealed ? $instructor->iban : $instructor->maskedIban() }}</div>
        </div>
        <div class="row">
            <div class="col-md-4 mb-2" dir="ltr"><strong>{{ __('app.profile.basic_salary') }}:</strong> {{ $revealed ? $instructor->basic_salary : mask_middle((string) $instructor->basic_salary) }}</div>
            <div class="col-md-4 mb-2" dir="ltr"><strong>{{ __('app.profile.total_salary') }}:</strong> {{ $revealed ? $instructor->total_salary : mask_middle((string) $instructor->total_salary) }}</div>
        </div>
    </div>
</div>

{{-- 2. Checklist --}}
<h2 class="h6">{{ __('app.review.checklist') }}</h2>
<div class="table-responsive mb-4">
    <table class="table table-striped align-middle">
        <thead>
        <tr>
            <th>{{ __('app.documents.item') }}</th>
            <th>{{ __('app.documents.status') }}</th>
            <th>{{ __('app.documents.file') }}</th>
            <th>{{ __('app.common.actions') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($checklist as $code => $row)
            @php($item = $row['item'])
            @php($document = $row['document'])
            <tr>
                <td>
                    {{ $item->label_ar }}
                    @if ($item->note_ar)
                        <div class="small text-muted">{{ $item->note_ar }}</div>
                    @endif
                </td>
                <td>
                    <span class="badge bg-secondary">{{ __('app.documents.states.'.$row['state']) }}</span>
                    @if ($row['state'] === 'rejected' && $document?->rejection_reason)
                        <div class="small text-danger">{{ $document->rejection_reason }}</div>
                    @endif
                </td>
                <td>
                    @if ($document)
                        <a href="{{ route('admin.documents.view', $document) }}" target="_blank">{{ __('app.review.view') }}</a>
                        —
                        <a href="{{ route('admin.documents.download', $document) }}">{{ __('app.documents.download') }}</a>
                        ({{ __('app.documents.version') }} {{ $document->version }})
                    @endif
                </td>
                <td>
                    @if ($document && ! $application->isFinal() && $termOpen)
                        <div class="d-flex gap-2 align-items-start flex-wrap">
                            <form method="post" action="{{ route('admin.documents.review', $document) }}">
                                @csrf
                                <input type="hidden" name="status" value="accepted">
                                <button type="submit" class="btn btn-sm btn-outline-success">{{ __('app.documents.accept') }}</button>
                            </form>
                            <form method="post" action="{{ route('admin.documents.review', $document) }}" class="d-flex gap-1">
                                @csrf
                                <input type="hidden" name="status" value="rejected">
                                <input type="text" name="reason" class="form-control form-control-sm" placeholder="{{ __('app.documents.reason') }}" required>
                                <button type="submit" class="btn btn-sm btn-outline-danger text-nowrap">{{ __('app.documents.reject') }}</button>
                            </form>
                        </div>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

{{-- 3. Department & not-applicable items --}}
<h2 class="h6">{{ __('app.applications.department_items') }}</h2>
<p class="text-muted small">{{ __('app.applications.by_department') }}</p>
<ul class="mb-4">
    @foreach ($plan->department as $item)
        <li>{{ $item->label_ar }}</li>
    @endforeach
</ul>

<h2 class="h6">{{ __('app.applications.not_applicable_items') }}</h2>
<ul class="mb-4 text-muted">
    @foreach ($plan->notApplicable as $item)
        <li>{{ $item->label_ar }}</li>
    @endforeach
</ul>

{{-- 4. Document history --}}
<h2 class="h6">{{ __('app.review.history') }}</h2>
<div class="table-responsive mb-4">
    <table class="table table-striped align-middle">
        <thead>
        <tr>
            <th>{{ __('app.documents.item') }}</th>
            <th>{{ __('app.documents.version') }}</th>
            <th>{{ __('app.review.submitted_at') }}</th>
            <th>{{ __('app.documents.status') }}</th>
            <th>{{ __('app.documents.reason') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($history as $document)
            <tr>
                <td>{{ $document->checklistItem->label_ar }}</td>
                <td>{{ $document->version }}</td>
                <td>{{ format_date($document->created_at) }}</td>
                <td><span class="badge bg-secondary">{{ __('app.documents.states.'.$document->status) }}</span></td>
                <td>{{ $document->rejection_reason }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

{{-- 5. Decision --}}
<h2 class="h6">{{ __('app.review.decision') }}</h2>
<div class="card mb-4">
    <div class="card-body">
        @if ($application->isFinal())
            <span class="badge bg-secondary">{{ __('app.applications.statuses.'.$application->status) }}</span>
            @if ($application->status === \App\Models\Application::STATUS_APPROVED)
                <form method="post" action="{{ route('admin.applications.decision', $application) }}" class="row g-2 align-items-end mt-2">
                    @csrf
                    <div class="col-auto">
                        <label class="form-label">{{ __('app.review.decision_number') }}</label>
                        <input name="assignment_decision_number" value="{{ old('assignment_decision_number', $application->assignment_decision_number) }}" class="form-control form-control-sm" maxlength="40" required>
                    </div>
                    <div class="col-auto">
                        <label class="form-label">{{ __('app.review.decision_date') }}</label>
                        <input type="date" name="assignment_decision_date" value="{{ old('assignment_decision_date', optional($application->assignment_decision_date)->format('Y-m-d')) }}" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-eet btn-sm">{{ __('app.common.save') }}</button>
                    </div>
                </form>
            @endif
        @elseif (! $termOpen)
            <div class="alert alert-warning py-2 mb-0">{{ __('app.applications.term_closed') }}</div>
        @else
            @if ($application->status === \App\Models\Application::STATUS_COMPLETE)
                <div class="alert alert-info py-2">{{ __('app.review.awaiting_committee') }}</div>
            @elseif ($canComplete)
                <form method="post" action="{{ route('admin.applications.complete', $application) }}" class="mb-3">
                    @csrf
                    <button type="submit" class="btn btn-eet btn-sm">{{ __('app.review.mark_complete') }}</button>
                </form>
            @else
                <div class="alert alert-warning py-2">{{ __('app.review.complete_blocked') }}</div>
            @endif

            @if ($canApprove)
                <form method="post" action="{{ route('admin.applications.approve', $application) }}" class="row g-2 align-items-end mb-3">
                    @csrf
                    <div class="col-auto">
                        <label class="form-label">{{ __('app.review.decision_number') }}</label>
                        <input name="assignment_decision_number" class="form-control form-control-sm">
                    </div>
                    <div class="col-auto">
                        <label class="form-label">{{ __('app.review.decision_date') }}</label>
                        <input type="date" name="assignment_decision_date" class="form-control form-control-sm">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-eet btn-sm">{{ __('app.review.approve') }}</button>
                    </div>
                </form>
            @else
                <div class="alert alert-warning py-2">{{ __('app.review.approve_blocked') }}</div>
            @endif

            <form method="post" action="{{ route('admin.applications.reject', $application) }}" onsubmit="return confirm('{{ __('app.review.reject_confirm') }}')" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-8">
                    <label class="form-label">{{ __('app.review.reason') }}</label>
                    <textarea name="reason" class="form-control form-control-sm" required></textarea>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-outline-danger btn-sm">{{ __('app.review.reject') }}</button>
                </div>
            </form>
        @endif
    </div>
</div>

{{-- 6. Print check list (Task 12) --}}
@if (Route::has('admin.applications.checklist'))
    <a href="{{ route('admin.applications.checklist', $application) }}" class="btn btn-outline-secondary" target="_blank">{{ __('app.review.print_checklist') }}</a>
@endif

@endsection

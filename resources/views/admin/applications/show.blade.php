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
        <div class="d-flex gap-2 align-items-center">
            <a href="{{ route('admin.applications.profile.edit', $application) }}" class="btn btn-sm btn-outline-secondary">{{ __('app.review.edit_profile') }}</a>
            @if (! $revealed)
                <form method="post" action="{{ route('admin.applications.reveal', $application) }}">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('app.review.reveal') }}</button>
                </form>
            @else
                <span class="badge bg-warning text-dark">{{ __('app.review.revealed') }}</span>
            @endif
        </div>
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
            <div class="col-md-4 mb-2"><strong>{{ __('app.auth.email') }}:</strong> <a href="mailto:{{ $instructor->user->email }}" dir="ltr">{{ $instructor->user->email }}</a></div>
        </div>
        <div class="row">
            <div class="col-md-4 mb-2"><strong>{{ __('app.profile.nationality') }}:</strong> {{ $instructor->nationalityLabel() }}</div>
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
@if ($pendingNotices !== [] && $termOpen)
    <form method="post" action="{{ route('admin.applications.notify_rejections', $application) }}" class="mb-2">
        @csrf
        <button type="submit" class="btn btn-warning btn-sm">{{ __('app.review.notify_rejections') }} ({{ count($pendingNotices) }})</button>
        <span class="form-text d-inline">{{ __('app.review.notify_hint') }}</span>
    </form>
@endif
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
                    @if ($row['optional'])
                        <span class="badge bg-light text-dark border">{{ __('app.documents.optional') }}</span>
                    @endif
                    @if ($item->note_ar)
                        <div class="small text-muted">{{ $item->note_ar }}</div>
                    @endif
                </td>
                <td>
                    <span class="badge {{ $row['state'] === 'on_file' ? 'bg-info text-dark' : 'bg-secondary' }}">{{ __('app.documents.states.'.$row['state']) }}</span>
                    @if ($row['state'] === 'on_file')
                        <div class="small text-muted">{{ __('app.documents.on_file_from', ['term' => $row['source']->application->term->label()]) }}</div>
                    @endif
                    @if ($row['state'] === 'rejected' && $document?->rejection_reason)
                        <div class="small text-danger">{{ $document->rejection_reason }}</div>
                    @endif
                    @if ($row['renewal'] && ! $document)
                        <div class="small text-danger">{{ __('app.documents.renewal_requested', ['reason' => $row['renewal']->reason]) }} ({{ $row['renewal']->requester?->name }})</div>
                    @endif
                </td>
                <td>
                    @php($file = $document ?? $row['source'])
                    @if ($file)
                        @if ($file->mime === 'application/pdf' || $file->isImage())
                            <a href="{{ route('admin.documents.view', $file) }}" data-doc-url="{{ route('admin.documents.view', $file) }}" data-bs-toggle="modal" data-bs-target="#docModal">{{ __('app.review.view') }}</a>
                            —
                        @endif
                        <a href="{{ route('admin.documents.download', $file) }}">{{ __('app.documents.download') }}</a>
                        ({{ __('app.documents.version') }} {{ $file->version }})
                    @endif
                </td>
                <td>
                    @if ($document && $termOpen && (! $application->isFinal() || ($application->status === \App\Models\Application::STATUS_APPROVED && ($item->isStageTwo() || $item->optional))))
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
                    @elseif ($row['state'] === 'on_file' && $termOpen && (in_array($application->status, \App\Models\Application::UNFINISHED_STATUSES, true) || ($application->status === \App\Models\Application::STATUS_APPROVED && $item->isStageTwo())))
                        <form method="post" action="{{ route('admin.applications.renewals.store', [$application, $item->code]) }}" class="d-flex gap-1">
                            @csrf
                            <input type="text" name="reason" class="form-control form-control-sm" placeholder="{{ __('app.review.fresh_copy_reason') }}" maxlength="500" required>
                            <button type="submit" class="btn btn-sm btn-outline-warning text-nowrap">{{ __('app.review.request_fresh_copy') }}</button>
                        </form>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

{{-- 2.5 Assigned sections (Task 12) --}}
@php($canUnassign = $termOpen && $application->status === \App\Models\Application::STATUS_APPROVED)
<div class="d-flex justify-content-between align-items-center">
    <h2 class="h6">{{ __('app.assignments.my_sections') }}</h2>
    @if ($application->status === \App\Models\Application::STATUS_APPROVED && ($sections->isNotEmpty() || $application->attestations()->exists()))
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.attestations.index', ['term' => $application->term_id]) }}">{{ __('app.attestations.title') }}</a>
    @endif
</div>
<div class="card mb-4">
    <div class="card-body">
        @if ($sections->isEmpty())
            <p class="text-muted mb-0">{{ __('app.assignments.none') }}</p>
        @else
            <div class="table-responsive">
                <table class="table table-striped align-middle mb-2">
                    <thead>
                    <tr>
                        <th>{{ __('app.sections.course') }}</th>
                        <th>{{ __('app.sections.meetings') }}</th>
                        <th>{{ __('app.sections.hours_theory') }}</th>
                        <th>{{ __('app.sections.hours_practical') }}</th>
                        <th>{{ __('app.sections.hours_field') }}</th>
                        @if ($canUnassign)
                            <th>{{ __('app.common.actions') }}</th>
                        @endif
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($sections as $s)
                        @php($hours = $s->weeklyMinutesByType())
                        <tr>
                            <td>{{ $s->label() }}</td>
                            <td>{{ $s->meetingSummary() }}</td>
                            <td>{{ \App\Models\Section::hoursFromMinutes($hours['theory']) }}</td>
                            <td>{{ \App\Models\Section::hoursFromMinutes($hours['practical']) }}</td>
                            <td>{{ \App\Models\Section::hoursFromMinutes($hours['field']) }}</td>
                            @if ($canUnassign)
                                <td>
                                    <form method="post" action="{{ route('admin.assignments.destroy', $s) }}" onsubmit="return confirm(@js(__('app.assignments.unassign_confirm')))">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('app.assignments.unassign') }}</button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <div class="d-flex justify-content-between align-items-center">
            <span><strong>{{ __('app.assignments.weekly_hours') }}:</strong> {{ $application->weeklyHoursLabel() }}</span>
            @if ($application->status === \App\Models\Application::STATUS_APPROVED)
                <a href="{{ route('admin.assignments.index', ['term' => $application->term_id]) }}" class="btn btn-sm btn-outline-secondary">{{ __('app.assignments.title') }}</a>
            @endif
        </div>
    </div>
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
            @if ($application->committee_outcome)
                <div class="small text-muted mt-2">
                    {{ __('app.review.committee_record', ['outcome' => __('app.review.outcomes.'.$application->committee_outcome), 'date' => format_date($application->committee_met_on), 'ref' => $application->committee_reference]) }}
                    @if ($application->committee_note)<div>{{ $application->committee_note }}</div>@endif
                </div>
            @endif
            @if ($application->status === \App\Models\Application::STATUS_WITHDRAWN && $termOpen)
                <form method="post" action="{{ route('admin.applications.reopen', $application) }}" class="mt-2" onsubmit="return confirm(@js(__('app.review.reopen_confirm')))">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary btn-sm">{{ __('app.review.reopen') }}</button>
                </form>
            @endif
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
                <form method="post" action="{{ route('admin.applications.committee', $application) }}" class="row g-2 align-items-end">
                    @csrf
                    <div class="col-md-3">
                        <label class="form-label">{{ __('app.review.committee_outcome') }}</label>
                        <select name="outcome" class="form-select form-select-sm" required>
                            <option value="">{{ __('app.review.choose_outcome') }}</option>
                            <option value="approved" @selected(old('outcome') === 'approved')>{{ __('app.review.outcomes.approved') }}</option>
                            <option value="rejected" @selected(old('outcome') === 'rejected')>{{ __('app.review.outcomes.rejected') }}</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">{{ __('app.review.committee_met_on') }}</label>
                        <input type="date" name="committee_met_on" value="{{ old('committee_met_on') }}" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">{{ __('app.review.committee_reference') }}</label>
                        <input name="committee_reference" value="{{ old('committee_reference') }}" class="form-control form-control-sm" maxlength="60" required>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">{{ __('app.review.committee_note') }}</label>
                        <textarea name="committee_note" class="form-control form-control-sm">{{ old('committee_note') }}</textarea>
                        <div class="form-text">{{ __('app.review.committee_note_hint') }}</div>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-eet btn-sm" onclick="return confirm(@js(__('app.review.committee_confirm')))">{{ __('app.review.committee_save') }}</button>
                    </div>
                </form>
            @elseif ($canComplete)
                <form method="post" action="{{ route('admin.applications.complete', $application) }}" class="mb-3">
                    @csrf
                    <button type="submit" class="btn btn-eet btn-sm">{{ __('app.review.mark_complete') }}</button>
                </form>
            @else
                <div class="alert alert-warning py-2 mb-0">{{ __('app.review.complete_blocked') }}</div>
            @endif
        @endif
    </div>
</div>

{{-- 6. Print check list (Task 12) --}}
@if (Route::has('admin.applications.checklist'))
    <a href="{{ route('admin.applications.checklist', $application) }}" class="btn btn-outline-secondary" target="_blank">{{ __('app.review.print_checklist') }}</a>
@endif

<div class="modal fade" id="docModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">{{ __('app.review.view') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <iframe id="docFrame" class="w-100" style="height:80vh" title="{{ __('app.review.view') }}"></iframe>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    document.getElementById('docModal')?.addEventListener('show.bs.modal', function (event) {
        document.getElementById('docFrame').src = event.relatedTarget?.getAttribute('data-doc-url') ?? '';
    });
    document.getElementById('docModal')?.addEventListener('hide.bs.modal', function () {
        document.getElementById('docFrame').src = '';
    });
</script>
@endpush

@endsection

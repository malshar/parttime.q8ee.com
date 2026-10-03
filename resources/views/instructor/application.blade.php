@extends('instructor.layout')
@section('title', __('app.applications.title'))
@section('content')
<div class="row justify-content-center"><div class="col-md-10">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h5 mb-0">{{ $isContinuation ? __('app.applications.continuation_title').' — '.$application->term->label() : $application->term->label() }}</h1>
        <span class="badge bg-secondary">{{ __('app.applications.statuses.'.$application->status) }}</span>
    </div>

    @if (! $application->isEditable() && ! $application->term->isOpen())
        <div class="alert alert-warning">{{ __('app.applications.term_closed') }}</div>
    @endif

    @if ($application->status === \App\Models\Application::STATUS_INCOMPLETE)
        <div class="alert alert-warning">{{ __('app.applications.fix_rejected') }}</div>
    @endif

    @if ($application->status === \App\Models\Application::STATUS_REJECTED)
        <div class="alert alert-danger">{{ $application->rejection_reason }}</div>
    @endif

    <h2 class="h6">{{ $isContinuation ? __('app.applications.academic_on_file') : __('app.applications.stage1_title') }}</h2>
    @include('instructor._checklist_table', ['rows' => $stage1, 'application' => $application, 'uploads' => $application->isEditable(), 'hideSatisfied' => $isContinuation])

    <h2 class="h6">{{ $isContinuation ? __('app.applications.term_papers') : __('app.applications.stage2_title') }}</h2>
    <p class="text-muted small">{{ $isContinuation ? __('app.applications.term_papers_hint') : __('app.documents.stage2_hint') }}</p>
    @if ($application->status === \App\Models\Application::STATUS_APPROVED)
        @if ($stageTwoComplete)
            <div class="alert alert-success py-2">{{ __('app.applications.stage2_complete') }}</div>
        @else
            <div class="alert alert-warning py-2">{{ __('app.applications.stage2_pending') }}: {{ implode('، ', $stageTwoMissing) }}</div>
        @endif
        @if ($showSalaryForm)
            @include('instructor._salary_form', ['instructor' => $application->instructor])
        @endif
    @endif
    @include('instructor._checklist_table', ['rows' => $stage2, 'application' => $application, 'uploads' => $isContinuation ? ($application->isEditable() || $application->acceptsStageTwoUploads()) : $application->acceptsStageTwoUploads()])

    @if ($application->isEditable() && $requiredMissing !== [])
        <div class="alert alert-warning py-2">{{ __('app.applications.still_required') }}: {{ implode('، ', $requiredMissing) }}</div>
    @endif

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

    <div class="d-flex gap-2">
        @if ($canSubmit && Route::has('instructor.applications.submit'))
            <form method="post" action="{{ route('instructor.applications.submit', $application) }}">
                @csrf
                <button type="submit" class="btn btn-eet">{{ __('app.applications.submit') }}</button>
            </form>
        @endif

        @if (! $application->isFinal() && Route::has('instructor.applications.withdraw'))
            <form method="post" action="{{ route('instructor.applications.withdraw', $application) }}" onsubmit="return confirm('{{ __('app.applications.withdraw_confirm') }}')">
                @csrf
                <button type="submit" class="btn btn-outline-danger">{{ __('app.applications.withdraw') }}</button>
            </form>
        @endif
    </div>

</div></div>
@endsection

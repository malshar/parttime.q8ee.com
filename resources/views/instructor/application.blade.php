@extends('instructor.layout')
@section('title', __('app.applications.title'))
@section('content')
<div class="row justify-content-center"><div class="col-md-10">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h5 mb-0">{{ $application->term->label() }}</h1>
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

    <h2 class="h6">{{ __('app.applications.required_items') }}</h2>
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
                        <span class="badge {{ $row['state'] === 'on_file' ? 'bg-info text-dark' : 'bg-secondary' }}">{{ __('app.documents.states.'.$row['state']) }}</span>
                        @if ($row['state'] === 'on_file')
                            <div class="small text-muted">{{ __('app.documents.on_file_from', ['term' => $row['source']->application->term->label()]) }}</div>
                        @endif
                        @if ($row['state'] === 'rejected' && $document?->rejection_reason)
                            <div class="small text-danger">{{ $document->rejection_reason }}</div>
                        @endif
                        @if ($row['renewal'] && ! $document)
                            <div class="small text-danger">{{ __('app.documents.renewal_requested', ['reason' => $row['renewal']->reason]) }}</div>
                        @endif
                    </td>
                    <td>
                        @if ($document)
                            <a href="{{ route('instructor.documents.download', $document) }}">{{ $document->original_name }}</a>
                            ({{ __('app.documents.version') }} {{ $document->version }})
                        @endif
                    </td>
                    <td>
                        @if (Route::has('instructor.documents.store'))
                            @include('instructor._upload', ['application' => $application, 'item' => $item, 'document' => $document, 'onFile' => $row['state'] === 'on_file'])
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

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

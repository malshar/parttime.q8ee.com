{{-- Expects $rows, $application, $uploads (bool: render upload controls). --}}
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
        @foreach ($rows as $code => $row)
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
                        <div class="small text-danger">{{ __('app.documents.renewal_requested', ['reason' => $row['renewal']->reason]) }}</div>
                    @endif
                    @if ($row['state'] === 'exempted')
                        <div class="small text-success">{{ __('app.exemptions.accepted') }}</div>
                    @elseif ($row['exemption']?->status === 'rejected' && ! $row['document'])
                        <div class="small text-danger">{{ __('app.exemptions.rejected_line', ['note' => $row['exemption']->decision_note]) }}</div>
                    @endif
                    @if ($row['item']->code === 'employer_approval')
                        <div class="small text-muted">{{ __('app.documents.employer_letter_hint') }}</div>
                    @endif
                </td>
                <td>
                    @if ($document)
                        @include('_document_links', ['document' => $document, 'route' => 'instructor'])
                    @endif
                </td>
                <td>
                    @if ($uploads && Route::has('instructor.documents.store'))
                        @include('instructor._upload', ['application' => $application, 'item' => $item, 'document' => $document, 'onFile' => $row['state'] === 'on_file'])
                    @endif
                    @if ($uploads && $item->exemptable && $row['state'] === 'missing' && $application->isEditable())
                        <details class="mt-1">
                            <summary class="small">{{ __('app.exemptions.request') }}</summary>
                            <form method="post" action="{{ route('instructor.exemptions.store', [$application, $row['item']->code]) }}" class="d-flex gap-1 mt-1">
                                @csrf
                                <input type="text" name="reason" class="form-control form-control-sm" placeholder="{{ __('app.exemptions.reason') }}" maxlength="500" required>
                                <button class="btn btn-sm btn-outline-secondary text-nowrap">{{ __('app.exemptions.request') }}</button>
                            </form>
                        </details>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

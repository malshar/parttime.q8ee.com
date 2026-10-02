{{-- Expects $rows, $application, $termOpen. --}}
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
                    @if ($row['stage'] === 2)
                        <span class="badge bg-light text-dark border">{{ __('app.applications.stage2_badge') }}</span>
                    @endif
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
                    @if ($row['exemption'] && ! $row['document'])
                        <div class="small">{{ __('app.exemptions.applicant_reason') }}: {{ $row['exemption']->reason }}</div>
                        @if ($row['exemption']->isPending() && $termOpen && in_array($application->status, \App\Models\Application::REVIEWABLE_STATUSES, true))
                            <form method="post" action="{{ route('admin.exemptions.decide', $row['exemption']) }}" class="d-flex gap-1 mt-1">
                                @csrf
                                <input type="hidden" name="status" value="accepted">
                                <button class="btn btn-sm btn-outline-success text-nowrap">{{ __('app.exemptions.accept') }}</button>
                            </form>
                            <form method="post" action="{{ route('admin.exemptions.decide', $row['exemption']) }}" class="d-flex gap-1 mt-1">
                                @csrf
                                <input type="hidden" name="status" value="rejected">
                                <input type="text" name="decision_note" class="form-control form-control-sm" placeholder="{{ __('app.exemptions.decision_note') }}" maxlength="500" required>
                                <button class="btn btn-sm btn-outline-danger text-nowrap">{{ __('app.exemptions.reject') }}</button>
                            </form>
                        @elseif ($row['exemption']->status === 'rejected')
                            <div class="small text-danger">{{ __('app.exemptions.rejected_line', ['note' => $row['exemption']->decision_note]) }}</div>
                        @endif
                    @endif
                </td>
                <td>
                    @php($file = $document ?? $row['source'])
                    @if ($file)
                        @include('_document_links', ['document' => $file, 'route' => 'admin'])
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

@if ($rows->isEmpty())
    <div class="alert alert-info">{{ __('app.review.no_attention') }}</div>
@else
    <div class="table-responsive">
        <table class="table table-striped align-middle">
            <thead>
            <tr>
                <th>{{ __('app.review.applicant') }}</th>
                <th>{{ __('app.review.term') }}</th>
                <th>{{ __('app.terms.status') }}</th>
                <th>{{ __('app.review.submitted_at') }}</th>
                <th>{{ __('app.common.actions') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($rows as $application)
                <tr>
                    <td>{{ $application->instructor->full_name }}</td>
                    <td>{{ $application->term->label() }}</td>
                    <td><span class="badge bg-secondary">{{ __('app.applications.statuses.'.$application->status) }}</span></td>
                    <td>{{ format_date($application->{$dateField}) }}</td>
                    <td><a href="{{ route('admin.applications.show', $application) }}" class="btn btn-sm btn-eet">{{ __('app.review.open') }}</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif

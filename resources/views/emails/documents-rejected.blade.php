@extends('emails._shell')
@section('body')
    <p>{{ __('app.mail.docs_rejected_body', ['term' => $term], 'ar') }}</p>
    <ul>
        @foreach ($rows as $row)
            <li>
                {{ $row['item']->label_ar }}
                @if ($row['state'] === 'rejected' && $row['document']?->rejection_reason)
                    — {{ $row['document']->rejection_reason }}
                @elseif ($row['renewal'])
                    — {{ __('app.documents.renewal_requested', ['reason' => $row['renewal']->reason], 'ar') }}
                @elseif ($row['exemption']?->status === 'rejected')
                    — {{ __('app.exemptions.rejected_line', ['note' => $row['exemption']->decision_note], 'ar') }}
                @endif
            </li>
        @endforeach
    </ul>
    <p><a href="{{ $url }}">{{ __('app.mail.open_application', [], 'ar') }}</a></p>
@endsection

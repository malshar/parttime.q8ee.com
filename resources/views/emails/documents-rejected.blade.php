@extends('emails._shell')
@section('body')
    <p>{{ __('app.mail.docs_rejected_body', ['term' => $term], 'ar') }}</p>
    <ul>
        @foreach ($rows as $row)
            <li>
                {{ $row['item']->label_ar }}
                @if ($row['document']?->rejection_reason)
                    — {{ $row['document']->rejection_reason }}
                @endif
            </li>
        @endforeach
    </ul>
    <p><a href="{{ $url }}">{{ __('app.mail.open_application', [], 'ar') }}</a></p>
@endsection

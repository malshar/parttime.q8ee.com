@extends('emails._shell')
@section('body')
    <p>{{ __('app.mail.renewal_approved_body', ['year' => $year], 'ar') }}</p>
    <ul>
        @foreach ($items as $label)
            <li>{{ $label }}</li>
        @endforeach
    </ul>
    <p><a href="{{ $url }}">{{ __('app.mail.open_application', [], 'ar') }}</a></p>
@endsection

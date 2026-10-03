@extends('emails._shell')
@section('body')
    <p>{{ __('app.mail.renewal_refused_body', ['year' => $year], 'ar') }}</p>
    @if ($note)
        <p>{{ $note }}</p>
    @endif
@endsection

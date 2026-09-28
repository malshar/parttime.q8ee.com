@extends('emails._shell')
@section('body')
    <p>{{ __('app.mail.submitted_body', ['name' => $name, 'term' => $term], 'ar') }}</p>
    <p><a href="{{ $url }}">{{ __('app.mail.open_application', [], 'ar') }}</a></p>
@endsection

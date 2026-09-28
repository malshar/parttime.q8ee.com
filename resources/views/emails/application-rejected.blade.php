@extends('emails._shell')
@section('body')
    <p>{{ __('app.mail.rejected_body', ['term' => $term, 'reason' => $reason], 'ar') }}</p>
@endsection

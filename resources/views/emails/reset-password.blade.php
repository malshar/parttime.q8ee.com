@extends('emails._shell')
@section('body')
    <p>{{ __('app.mail.reset_body', [], 'ar') }}</p>
    <p><a href="{{ $url }}" style="background:#1d4e89;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;">{{ __('app.mail.reset_button', [], 'ar') }}</a></p>
    <p style="font-size:.85em;color:#6c757d;">{{ $url }}</p>
@endsection

@extends('emails._shell')
@section('body')
    <p>{{ __('app.mail.approved_body', ['term' => $term], 'ar') }}</p>
    <p>{{ __('app.mail.approved_stage2_intro', [], 'ar') }}</p>
    <ul>
        @foreach ($items as $label)
            <li>{{ $label }}</li>
        @endforeach
        <li>{{ __('app.profile.salary_missing', [], 'ar') }}</li>
    </ul>
    <p>{{ __('app.documents.employer_letter_hint', [], 'ar') }}</p>
    <p><a href="{{ $url }}">{{ __('app.mail.open_application', [], 'ar') }}</a></p>
@endsection

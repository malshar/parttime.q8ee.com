@php($parts = $document->relationLoaded('parts') ? $document->parts : $document->parts()->get())
@foreach ($parts as $part)
    @if ($route === 'admin')
        @if ($part->mime === 'application/pdf' || $part->isImage())
            <a href="{{ route('admin.documents.view', $part) }}" data-doc-url="{{ route('admin.documents.view', $part) }}" data-bs-toggle="modal" data-bs-target="#docModal">{{ __('app.review.view') }}</a>
            —
        @endif
        <a href="{{ route('admin.documents.download', $part) }}">{{ $parts->count() > 1 ? __('app.documents.part_n', ['n' => $part->part]) : __('app.documents.download') }}</a>
    @else
        <a href="{{ route('instructor.documents.download', $part) }}">{{ $part->original_name }}</a>
    @endif
    @if (! $loop->last)<br>@endif
@endforeach
<span class="text-muted small">({{ __('app.documents.version') }} {{ $document->version }}@if ($parts->count() > 1), {{ __('app.documents.parts_count', ['n' => $parts->count()]) }}@endif)</span>

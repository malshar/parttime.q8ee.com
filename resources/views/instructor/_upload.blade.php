@if ($application->isEditable())
<form method="post" action="{{ route('instructor.documents.store', [$application, $item->code]) }}" enctype="multipart/form-data" class="d-flex gap-2 align-items-center">
    @csrf
    <input type="file" name="file" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,.docx" required>
    <button class="btn btn-sm btn-eet text-nowrap">{{ $document ? __('app.documents.replace') : __('app.documents.upload') }}</button>
</form>
<div class="form-text">{{ __('app.documents.file_rules') }}</div>
@endif

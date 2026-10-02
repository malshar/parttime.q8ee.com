@if ($application->isEditable() || ($application->acceptsStageTwoUploads() && ($item->isStageTwo() || $item->optional)))
<form method="post" action="{{ route('instructor.documents.store', [$application, $item->code]) }}" enctype="multipart/form-data" class="d-flex gap-2 align-items-center">
    @csrf
    <input type="file" name="files[]" multiple class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,.docx" required>
    <button class="btn btn-sm btn-eet text-nowrap">{{ ($onFile ?? false) ? __('app.documents.newer_copy') : ($document ? __('app.documents.replace') : __('app.documents.upload')) }}</button>
</form>
<div class="form-text">{{ __('app.documents.file_rules_multi') }}</div>
@endif

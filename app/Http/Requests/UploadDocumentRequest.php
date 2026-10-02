<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Validation\Validator;

class UploadDocumentRequest extends FormRequest
{
    /** Set true by {@see prepareForValidation()} when the legacy single-`file` field was used. */
    private bool $legacyField = false;

    protected function prepareForValidation(): void
    {
        // Legacy single-file posts: normalise into the `files` field so one validation/storage path
        // handles both. Checked/set through the Symfony FileBag directly (has()/get()/set()), never
        // through file()/hasFile() — those memoise allFiles() into $convertedFiles, and calling them
        // before the bag is mutated would cache a stale result missing the new `files` entry.
        if (! $this->files->has('files') && $this->files->has('file')) {
            $this->legacyField = true;
            $this->files->set('files', [$this->files->get('file')]);
        }
    }

    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,docx',
                'mimetypes:application/pdf,image/jpeg,image/png,application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        ];
    }

    public function withValidator(Validator $v): void
    {
        $v->after(function (Validator $v) {
            // Runs even when the `array` rule already failed (e.g. `files` posted as a single file,
            // not `files[]`): Arr::wrap() avoids casting a non-array UploadedFile to its properties,
            // and the instanceof guard skips anything that is not actually an uploaded file.
            foreach (Arr::wrap($this->file('files')) as $i => $f) {
                if (! $f instanceof UploadedFile) {
                    continue;
                }
                if (str_ends_with(strtolower($f->getClientOriginalName()), '.zip') || $f->getMimeType() === 'application/zip') {
                    $v->errors()->add("files.$i", __('app.documents.zip_rejected'));
                }
            }

            // Legacy single-file posts: mirror the per-file error back onto `file` so old callers
            // asserting on that field name (pre multi-file-upload) keep working.
            if ($this->legacyField) {
                foreach ($v->errors()->get('files.0') as $message) {
                    $v->errors()->add('file', $message);
                }
            }
        });
    }

    public function messages(): array
    {
        $rules = __('app.documents.file_rules');

        return [
            'files.required' => $rules,
            'files.max' => __('app.documents.too_many_files'),
            'files.*.mimes' => $rules,
            'files.*.mimetypes' => $rules,
            'files.*.max' => $rules,
        ];
    }
}

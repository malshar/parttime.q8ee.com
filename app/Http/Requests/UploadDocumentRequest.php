<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UploadDocumentRequest extends FormRequest
{
    /** Set true by {@see prepareForValidation()} when the legacy single-`file` field was used. */
    private bool $legacyField = false;

    protected function prepareForValidation(): void
    {
        // Legacy single-file posts: normalise into the `files` field so one validation/storage path handles both.
        if (! $this->hasFile('files') && $this->hasFile('file')) {
            $this->legacyField = true;
            $this->merge(['files' => [$this->file('file')]]);
            $this->files->set('files', [$this->file('file')]);
            // file()/hasFile() memoise allFiles() in $convertedFiles; the lines above already
            // called them (via hasFile), so the cache must be cleared or it still misses `files`.
            $this->convertedFiles = null;
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
            foreach ((array) $this->file('files', []) as $i => $f) {
                if ($f && (str_ends_with(strtolower($f->getClientOriginalName()), '.zip') || $f->getMimeType() === 'application/zip')) {
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

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UploadDocumentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,docx',
                'mimetypes:application/pdf,image/jpeg,image/png,application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        ];
    }

    public function withValidator(Validator $v): void
    {
        $v->after(function (Validator $v) {
            $f = $this->file('file');
            if ($f && (str_ends_with(strtolower($f->getClientOriginalName()), '.zip') || $f->getMimeType() === 'application/zip')) {
                $v->errors()->add('file', __('app.documents.zip_rejected'));
            }
        });
    }

    public function messages(): array
    {
        return ['file.mimes' => __('app.documents.file_rules'), 'file.mimetypes' => __('app.documents.file_rules'), 'file.max' => __('app.documents.file_rules')];
    }
}

<?php

namespace App\Http\Requests;

use App\Models\Term;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ImportSectionsRequest extends FormRequest
{
    public function rules(): array
    {
        return ['file' => ['required', 'file', 'max:10240', 'extensions:csv,xlsx']];
    }

    public function withValidator(Validator $v): void
    {
        $v->after(function (Validator $v) {
            if (! Term::current()) {
                $v->errors()->add('file', __('app.terms.none_open'));
            }
        });
    }
}

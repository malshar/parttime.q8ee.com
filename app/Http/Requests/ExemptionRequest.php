<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExemptionRequest extends FormRequest
{
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }

    public function attributes(): array
    {
        return ['reason' => __('app.exemptions.reason')];
    }
}

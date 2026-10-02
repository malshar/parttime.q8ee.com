<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SalaryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'basic_salary' => ['required', 'numeric', 'min:0', 'max:99999'],
            'total_salary' => ['required', 'numeric', 'min:0', 'max:99999', 'gte:basic_salary'],
        ];
    }

    public function attributes(): array
    {
        return ['basic_salary' => __('app.profile.basic_salary'), 'total_salary' => __('app.profile.total_salary')];
    }
}

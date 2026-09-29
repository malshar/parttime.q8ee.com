<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CommitteeDecisionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'outcome' => ['required', Rule::in(['approved', 'rejected'])],
            'committee_met_on' => ['required', 'date', 'before_or_equal:today'],
            'committee_reference' => ['required', 'string', 'max:60'],
            'committee_note' => ['nullable', 'string', 'max:1000', Rule::requiredIf(fn () => $this->outcome === 'rejected')],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordRenewalsRequest extends FormRequest
{
    /** Drop rows whose outcome was left empty: unselected candidates never reach validation. */
    protected function prepareForValidation(): void
    {
        $rows = array_filter((array) $this->input('rows', []), fn ($row) => filled($row['outcome'] ?? null));
        $this->merge(['rows' => $rows]);
    }

    public function rules(): array
    {
        return [
            'year' => ['required', 'regex:/^\d{4}-\d{4}$/'],
            'committee_met_on' => ['required', 'date'],
            'committee_reference' => ['required', 'string', 'max:60'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.outcome' => ['required', Rule::in(['renewed', 'not_renewed'])],
            'rows.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return [
            'year' => __('app.renewals.year'),
            'committee_met_on' => __('app.review.committee_met_on'),
            'committee_reference' => __('app.review.committee_reference'),
            'rows' => __('app.renewals.candidates'),
        ];
    }
}

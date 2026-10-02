<?php

namespace App\Http\Requests;

use App\Models\ChecklistExemption;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideExemptionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([ChecklistExemption::STATUS_ACCEPTED, ChecklistExemption::STATUS_REJECTED])],
            'decision_note' => ['nullable', 'string', 'max:500', Rule::requiredIf(fn () => $this->status === ChecklistExemption::STATUS_REJECTED)],
        ];
    }

    public function attributes(): array
    {
        return ['decision_note' => __('app.exemptions.decision_note')];
    }
}

<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RejectDocumentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([Document::STATUS_ACCEPTED, Document::STATUS_REJECTED])],
            'reason' => ['nullable', 'string', 'max:500', Rule::requiredIf(fn () => $this->status === Document::STATUS_REJECTED)],
        ];
    }
}

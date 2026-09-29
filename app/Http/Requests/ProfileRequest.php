<?php

namespace App\Http\Requests;

use App\Models\Instructor;
use App\Rules\Iban;
use App\Rules\KuwaitCivilId;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProfileRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:150'],
            'civil_id' => ['required', new KuwaitCivilId],
            'civil_id_expires_on' => ['required', 'date', 'after:today'],
            'nationality' => ['required', 'string', 'max:60'],
            'mobile' => ['required', 'regex:/^[569]\d{7}$/'],
            'work_phone' => ['nullable', 'regex:/^\d{8}$/'],
            'home_phone' => ['nullable', 'regex:/^\d{8}$/'],
            'employer' => ['required', 'string', 'max:150'],
            'employer_sector' => ['required', Rule::in(Instructor::SECTORS)],
            'job_title' => ['required', 'string', 'max:120'],
            'highest_degree' => ['required', Rule::in(Instructor::DEGREES)],
            'degree_title' => ['required', 'string', 'max:150'],
            'degree_country' => ['required', 'string', 'size:2', 'alpha'],
            'degree_obtained_on' => ['required', 'date', 'before_or_equal:today'],
            'experience_years' => ['nullable', 'integer', 'min:0', 'max:60', Rule::requiredIf(fn () => $this->highest_degree === 'bachelor')],
            'bank_name' => ['required', 'string', 'max:120'],
            'bank_branch' => ['nullable', 'string', 'max:120'],
            'iban' => ['required', new Iban],
            'basic_salary' => ['required', 'numeric', 'min:0', 'max:99999'],
            'total_salary' => ['required', 'numeric', 'min:0', 'max:99999', 'gte:basic_salary'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'civil_id' => preg_replace('/\s+/', '', (string) $this->civil_id),
            'iban' => Iban::normalize($this->iban),
            'degree_country' => strtoupper((string) $this->degree_country),
        ]);
    }

    public function withValidator(Validator $v): void
    {
        $v->after(function (Validator $v) {
            $existing = Instructor::findByCivilId((string) $this->civil_id);
            if ($existing && $existing->user_id !== $this->ownerUserId()) {
                $v->errors()->add('civil_id', __('app.profile.civil_id_taken'));
            }
        });
    }

    /** The user who owns the profile being edited; overridden by the admin request. */
    protected function ownerUserId(): ?int
    {
        return $this->user()?->id;
    }
}

<?php

namespace App\Http\Requests;

use App\Models\Instructor;
use App\Rules\Iban;
use App\Rules\KuwaitCivilId;
use App\Support\KuwaitLists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class ProfileRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:150'],
            'civil_id' => ['required', new KuwaitCivilId],
            'civil_id_expires_on' => ['required', 'date', 'after:today'],
            'nationality' => ['required', 'string', Rule::in(array_keys(__('app.countries')))],
            'mobile' => ['required', 'regex:/^[569]\d{7}$/'],
            'work_phone' => ['nullable', 'regex:/^\d{8}$/'],
            'home_phone' => ['nullable', 'regex:/^\d{8}$/'],
            'employer_choice' => ['required', 'string', 'max:150'],
            'employer_other' => ['nullable', 'string', 'max:150', Rule::requiredIf(fn () => in_array($this->employer_choice, ['private', 'other'], true))],
            'employer_sector' => ['nullable', Rule::in(Instructor::SECTORS), Rule::requiredIf(fn () => $this->employer_choice === 'other')],
            'job_title' => ['required', 'string', 'max:120'],
            'highest_degree' => ['required', Rule::in(Instructor::DEGREES)],
            'degree_title' => ['required', 'string', 'max:150'],
            'degree_country' => ['required', 'string', 'size:2', 'alpha'],
            'degree_obtained_on' => ['required', 'date', 'before_or_equal:today'],
            'experience_years' => ['nullable', 'integer', 'min:0', 'max:60', Rule::requiredIf(fn () => $this->highest_degree === 'bachelor')],
            'bank_choice' => ['required', 'string', 'max:120'],
            'bank_other' => ['nullable', 'string', 'max:120', Rule::requiredIf(fn () => $this->bank_choice === 'other')],
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

    /** The attributes to store, derived from the choice fields (spec of the 5a design, section B). */
    public function profileAttributes(): array
    {
        $v = $this->validated();
        $choice = $v['employer_choice'];
        if ($choice === 'private') {
            $employer = $v['employer_other'];
            $sector = 'private';
        } elseif ($choice === 'other') {
            $employer = $v['employer_other'];
            $sector = $v['employer_sector'];
        } elseif (KuwaitLists::isEmployer($choice)) {
            $employer = $choice;
            $sector = 'government';
        } else {
            throw ValidationException::withMessages(['employer_choice' => __('app.profile.employer_choice_invalid')]);
        }
        $bank = $v['bank_choice'] === 'other' ? $v['bank_other'] : (KuwaitLists::BANKS[$v['bank_choice']] ?? null);
        if ($bank === null) {
            throw ValidationException::withMessages(['bank_choice' => __('app.profile.bank_choice_invalid')]);
        }
        unset($v['employer_choice'], $v['employer_other'], $v['bank_choice'], $v['bank_other']);

        // Union with the computed values first: array union keeps the left side's value on key collisions,
        // and $v may still carry a submitted (possibly empty) employer_sector from the request.
        return ['employer' => $employer, 'employer_sector' => $sector, 'bank_name' => $bank] + $v;
    }
}

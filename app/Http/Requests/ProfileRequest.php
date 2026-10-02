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
    /** Nationality option that keeps a stored legacy free-text value (not a 2-letter code) unchanged. */
    public const KEEP = '__keep';

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:150'],
            'civil_id' => ['required', new KuwaitCivilId],
            'civil_id_expires_on' => ['required', 'date', 'after:today'],
            'nationality' => ['required', 'string', Rule::in([...array_keys(__('app.countries')), self::KEEP])],
            'mobile' => ['required', 'regex:/^[569]\d{7}$/'],
            'work_phone' => ['nullable', 'regex:/^\d{8}$/'],
            'home_phone' => ['nullable', 'regex:/^\d{8}$/'],
            'employer_choice' => ['required', 'string', 'max:150', Rule::in([...KuwaitLists::EMPLOYERS, 'private', 'other'])],
            'employer_other' => ['nullable', 'string', 'max:150', Rule::requiredIf(fn () => in_array($this->employer_choice, ['private', 'other'], true))],
            'employer_sector' => ['nullable', Rule::in(Instructor::SECTORS), Rule::requiredIf(fn () => $this->employer_choice === 'other')],
            'job_title' => ['required', 'string', 'max:120'],
            'highest_degree' => ['required', Rule::in(Instructor::DEGREES)],
            'degree_title' => ['required', 'string', 'max:150'],
            'degree_country' => ['required', 'string', Rule::in(array_keys(__('app.countries')))],
            'degree_obtained_on' => ['required', 'date', 'before_or_equal:today'],
            'experience_years' => ['nullable', 'integer', 'min:0', 'max:60', Rule::requiredIf(fn () => $this->highest_degree === 'bachelor')],
            'bank_choice' => ['required', 'string', 'max:120', Rule::in([...array_keys(KuwaitLists::BANKS), 'other'])],
            'bank_other' => ['nullable', 'string', 'max:120', Rule::requiredIf(fn () => $this->bank_choice === 'other')],
            'bank_branch' => ['nullable', 'string', 'max:120'],
            'iban' => ['required', new Iban],
            'basic_salary' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'total_salary' => ['nullable', 'numeric', 'min:0', 'max:99999', Rule::when($this->filled('basic_salary'), ['gte:basic_salary'])],
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

    /** Field names in error messages, from the form's own labels. */
    public function attributes(): array
    {
        $labels = [
            'full_name' => 'full_name', 'civil_id' => 'civil_id', 'civil_id_expires_on' => 'civil_id_expires_on',
            'nationality' => 'nationality', 'mobile' => 'mobile', 'work_phone' => 'work_phone', 'home_phone' => 'home_phone',
            'employer_choice' => 'employer', 'employer_other' => 'employer_name', 'employer_sector' => 'employer_sector',
            'job_title' => 'job_title', 'highest_degree' => 'highest_degree', 'degree_title' => 'degree_title',
            'degree_country' => 'degree_country', 'degree_obtained_on' => 'degree_obtained_on', 'experience_years' => 'experience_years',
            'bank_choice' => 'bank_name', 'bank_other' => 'bank_other_name', 'bank_branch' => 'bank_branch', 'iban' => 'iban',
            'basic_salary' => 'basic_salary', 'total_salary' => 'total_salary',
        ];

        return array_map(fn ($key) => __('app.profile.'.$key), $labels);
    }

    public function withValidator(Validator $v): void
    {
        $v->after(function (Validator $v) {
            if ($this->nationality === self::KEEP && ! $this->hasLegacyNationality()) {
                $v->errors()->add('nationality', __('validation.in', ['attribute' => __('app.profile.nationality')]));
            }
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

    /** The stored profile being edited (null for a new one); overridden by the admin request. */
    protected function editedInstructor(): ?Instructor
    {
        return $this->user()?->instructor;
    }

    /** Whether the edited profile holds a free-text nationality that the KEEP option can keep. */
    private function hasLegacyNationality(): bool
    {
        $stored = (string) $this->editedInstructor()?->nationality;

        return $stored !== '' && strlen($stored) !== 2;
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
        if ($v['nationality'] === self::KEEP) {
            $v['nationality'] = $this->editedInstructor()->nationality;
        }

        // Union with the computed values first: array union keeps the left side's value on key collisions,
        // and $v may still carry a submitted (possibly empty) employer_sector from the request.
        return ['employer' => $employer, 'employer_sector' => $sector, 'bank_name' => $bank] + $v;
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttestationUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        $hours = ['required', 'regex:/^\d{1,3}(\.\d{1,3})?$/'];

        return [
            'weeks' => ['required', 'array'],
            'weeks.*.courses_text' => ['nullable', 'string', 'max:2000'],
            'weeks.*.student_count' => ['required', 'integer', 'min:0', 'max:9999'],
            'weeks.*.theory_hours' => $hours,
            'weeks.*.practical_hours' => $hours,
            'weeks.*.field_hours' => $hours,
            'weeks.*.note_ar' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        $msg = __('app.attestations.hours_format');

        return ['weeks.*.theory_hours.regex' => $msg, 'weeks.*.practical_hours.regex' => $msg, 'weeks.*.field_hours.regex' => $msg];
    }

    /** @return array<int, array<string, mixed>> week id => editable columns, hours converted to minutes */
    public function weeks(): array
    {
        $out = [];
        foreach ($this->validated('weeks') as $id => $w) {
            $out[(int) $id] = [
                'courses_text' => (string) ($w['courses_text'] ?? ''),
                'student_count' => (int) $w['student_count'],
                'theory_minutes' => (int) round(((float) $w['theory_hours']) * 60),
                'practical_minutes' => (int) round(((float) $w['practical_hours']) * 60),
                'field_minutes' => (int) round(((float) $w['field_hours']) * 60),
                'note_ar' => (string) ($w['note_ar'] ?? ''),
            ];
        }

        return $out;
    }
}

<?php

namespace App\Http\Requests;

use App\Models\Term;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTermRequest extends FormRequest
{
    public function rules(): array
    {
        $ignore = $this->route('term')?->id;

        return [
            'academic_year' => ['required', 'regex:/^\d{4}-\d{4}$/'],
            'type' => ['required', Rule::in(Term::TYPES),
                Rule::unique('terms')->where('academic_year', $this->academic_year)->ignore($ignore)],
            'teaching_starts_on' => ['required', 'date'],
            'teaching_ends_on' => ['required', 'date', 'after:teaching_starts_on'],
            'holidays' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $v): void
    {
        $v->after(function (Validator $v) {
            $ignore = $this->route('term')?->id;
            if (Term::open()->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->exists()) {
                $v->errors()->add('academic_year', __('app.terms.one_open_only'));
            }
            $seen = [];
            foreach ($this->parsedHolidays() as $i => $h) {
                if ($h === null) {
                    $v->errors()->add('holidays', __('app.terms.holiday_line_invalid', ['line' => $i + 1]));

                    continue;
                }
                if (empty($h['skip'])) {
                    if (isset($seen[$h['date']])) {
                        $v->errors()->add('holidays', __('app.terms.holiday_duplicate', ['date' => $h['date']]));
                    }
                    $seen[$h['date']] = true;
                }
            }
        });
    }

    /** Each line: YYYY-MM-DD|name. Returns [['date'=>..,'name'=>..]|null, ...] */
    public function parsedHolidays(): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim((string) $this->holidays)) ?: [];

        return array_map(function (string $line) {
            $line = trim($line);
            if ($line === '') {
                return ['skip' => true];
            }
            [$date, $name] = array_pad(explode('|', $line, 2), 2, '');
            $date = trim($date);
            $name = trim($name);
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $name === '' || ! strtotime($date)) {
                return null;
            }

            return ['date' => $date, 'name' => $name];
        }, array_values(array_filter($lines, fn ($l) => trim($l) !== '')));
    }
}

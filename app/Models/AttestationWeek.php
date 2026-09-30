<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttestationWeek extends Model
{
    use HasFactory;

    /** Columns the admin may edit; each has a generated_ twin holding the generator's value. */
    public const EDITABLE = ['courses_text', 'student_count', 'theory_minutes', 'practical_minutes', 'field_minutes', 'note_ar'];

    protected $fillable = ['attestation_id', 'week_number', 'date_from', 'date_to', 'working_days',
        'courses_text', 'student_count', 'theory_minutes', 'practical_minutes', 'field_minutes', 'note_ar',
        'generated_courses_text', 'generated_student_count', 'generated_theory_minutes', 'generated_practical_minutes', 'generated_field_minutes', 'generated_note_ar'];

    protected function casts(): array
    {
        return ['working_days' => 'array', 'date_from' => 'date', 'date_to' => 'date'];
    }

    public function attestation(): BelongsTo
    {
        return $this->belongsTo(Attestation::class);
    }

    public function totalMinutes(): int
    {
        return (int) $this->theory_minutes + (int) $this->practical_minutes + (int) $this->field_minutes;
    }

    public function generatedTotalMinutes(): int
    {
        return (int) $this->generated_theory_minutes + (int) $this->generated_practical_minutes + (int) $this->generated_field_minutes;
    }

    /** "7-11" as printed on the form; "7" when the block is a single day. */
    public function datesLabel(): string
    {
        return $this->date_from->day === $this->date_to->day ? (string) $this->date_from->day : $this->date_from->day.'-'.$this->date_to->day;
    }

    public function isEdited(): bool
    {
        foreach (self::EDITABLE as $col) {
            if ((string) $this->$col !== (string) $this->{'generated_'.$col}) {
                return true;
            }
        }

        return false;
    }
}

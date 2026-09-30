<?php

namespace Database\Factories;

use App\Models\Attestation;
use Illuminate\Database\Eloquent\Factories\Factory;

class AttestationWeekFactory extends Factory
{
    public function definition(): array
    {
        return [
            'attestation_id' => Attestation::factory(),
            'week_number' => 1, 'date_from' => '2026-09-13', 'date_to' => '2026-09-17',
            'working_days' => ['2026-09-13', '2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17'],
            'courses_text' => 'الدوائر الكهربائية (7230101)', 'generated_courses_text' => 'الدوائر الكهربائية (7230101)',
            'student_count' => 20, 'generated_student_count' => 20,
            'theory_minutes' => 150, 'generated_theory_minutes' => 150,
            'practical_minutes' => 100, 'generated_practical_minutes' => 100,
            'field_minutes' => 0, 'generated_field_minutes' => 0,
            'note_ar' => 'أسبوع كامل', 'generated_note_ar' => 'أسبوع كامل',
        ];
    }
}

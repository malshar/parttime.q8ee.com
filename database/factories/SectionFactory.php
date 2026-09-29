<?php

namespace Database\Factories;

use App\Models\Section;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

class SectionFactory extends Factory
{
    protected $model = Section::class;

    public function definition(): array
    {
        return [
            'term_id' => Term::factory()->open(),
            'course_code' => '72'.$this->faker->unique()->numerify('#####'),
            'course_name_ar' => 'الدوائر الكهربائية',
            'section_number' => '1',
            'reference_number' => (string) $this->faker->numberBetween(10000, 99999),
            'scheduled_instructor' => null,
            'imported_at' => now(),
        ];
    }

    public function withMeetings(): static
    {
        return $this->afterCreating(function (Section $s) {
            foreach ([0, 2] as $day) {
                $s->meetings()->create(['day_of_week' => $day, 'type' => 'theory', 'starts_at' => '08:00', 'ends_at' => '09:15', 'minutes' => 75, 'activity_ar' => 'محاضرة', 'building' => '04A', 'room' => 'D-101']);
            }
            $s->meetings()->create(['day_of_week' => 1, 'type' => 'practical', 'starts_at' => '09:30', 'ends_at' => '11:10', 'minutes' => 100, 'activity_ar' => 'مختبر', 'building' => '04A', 'room' => 'L-12']);
        });
    }
}

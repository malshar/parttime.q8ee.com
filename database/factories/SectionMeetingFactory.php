<?php

namespace Database\Factories;

use App\Models\Section;
use App\Models\SectionMeeting;
use Illuminate\Database\Eloquent\Factories\Factory;

class SectionMeetingFactory extends Factory
{
    protected $model = SectionMeeting::class;

    public function definition(): array
    {
        return [
            'section_id' => Section::factory(),
            'day_of_week' => 0,
            'type' => 'theory',
            'starts_at' => '08:00',
            'ends_at' => '09:15',
            'minutes' => 75,
            'activity_ar' => 'محاضرة',
        ];
    }
}

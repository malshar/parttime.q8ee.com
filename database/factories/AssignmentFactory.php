<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Section;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssignmentFactory extends Factory
{
    protected $model = Assignment::class;

    public function definition(): array
    {
        return [
            'application_id' => Application::factory()->approved(),
            'section_id' => Section::factory(),
        ];
    }
}

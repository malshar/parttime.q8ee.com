<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApplicationFactory extends Factory
{
    protected $model = Application::class;

    public function definition(): array
    {
        return [
            'term_id' => Term::factory()->open(),
            'instructor_id' => Instructor::factory()->for(User::factory()->instructor()),
            'status' => Application::STATUS_DRAFT,
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn () => ['status' => Application::STATUS_SUBMITTED, 'submitted_at' => now()]);
    }
}

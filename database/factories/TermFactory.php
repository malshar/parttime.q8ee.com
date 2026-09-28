<?php

namespace Database\Factories;

use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

class TermFactory extends Factory
{
    protected $model = Term::class;

    public function definition(): array
    {
        return [
            'academic_year' => '2026-2027', 'type' => 'first',
            'teaching_starts_on' => '2026-09-13', 'teaching_ends_on' => '2026-12-24',
            'status' => Term::STATUS_CLOSED,
        ];
    }

    public function open(): static
    {
        return $this->state(fn () => ['status' => Term::STATUS_OPEN]);
    }
}

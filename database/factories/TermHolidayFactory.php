<?php

namespace Database\Factories;

use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

class TermHolidayFactory extends Factory
{
    public function definition(): array
    {
        return ['term_id' => Term::factory(), 'date' => '2026-06-16', 'name' => 'إجازة رأس السنة الهجرية'];
    }
}

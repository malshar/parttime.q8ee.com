<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\Attestation;
use Illuminate\Database\Eloquent\Factories\Factory;

class AttestationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'application_id' => Application::factory()->approved(),
            'year' => 2026, 'month' => 9,
            'status' => Attestation::STATUS_GENERATED, 'generated_at' => now(),
        ];
    }

    public function exported(): static
    {
        return $this->state(fn () => ['status' => Attestation::STATUS_EXPORTED, 'exported_at' => now()]);
    }
}

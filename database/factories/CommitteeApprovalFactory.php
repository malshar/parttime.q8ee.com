<?php

namespace Database\Factories;

use App\Models\CommitteeApproval;
use App\Models\Instructor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CommitteeApprovalFactory extends Factory
{
    protected $model = CommitteeApproval::class;

    public function definition(): array
    {
        return [
            'instructor_id' => Instructor::factory()->for(User::factory()->instructor()),
            'academic_year' => '2026-2027', 'kind' => CommitteeApproval::KIND_INITIAL,
            'outcome' => CommitteeApproval::OUTCOME_APPROVED,
            'committee_met_on' => '2026-10-01', 'committee_reference' => 'ق/1',
        ];
    }

    public function renewal(): static
    {
        return $this->state(fn () => ['kind' => CommitteeApproval::KIND_RENEWAL]);
    }

    public function notRenewed(): static
    {
        return $this->state(fn () => ['outcome' => CommitteeApproval::OUTCOME_NOT_RENEWED]);
    }
}

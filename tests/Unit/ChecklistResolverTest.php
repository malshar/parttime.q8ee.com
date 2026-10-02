<?php

namespace Tests\Unit;

use App\Models\Instructor;
use App\Models\User;
use App\Services\ChecklistResolver;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChecklistResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChecklistItemSeeder::class);
    }

    private function codes($items): array
    {
        return $items->pluck('code')->values()->all();
    }

    public function test_seeds_fourteen_items_idempotently(): void
    {
        $this->seed(ChecklistItemSeeder::class);
        $this->assertDatabaseCount('checklist_items', 14);
    }

    public function test_local_master_government_requires_only_unconditional_applicant_items(): void
    {
        $i = Instructor::factory()->for(User::factory()->instructor())->create();
        $plan = app(ChecklistResolver::class)->for($i);

        $this->assertSame(['civil_id', 'degree', 'salary_cert', 'iban', 'employer_approval', 'undertaking'], $this->codes($plan->required));
        $this->assertSame(['schedule', 'assignment_letter', 'attestation'], $this->codes($plan->department));
        $this->assertSame(['equivalency', 'social_insurance', 'experience'], $this->codes($plan->notApplicable));
        $this->assertSame(['transcript_bachelor', 'transcript_master'], $this->codes($plan->optional));
    }

    public function test_foreign_degree_adds_equivalency(): void
    {
        $i = Instructor::factory()->foreignDegree()->for(User::factory()->instructor())->create();
        $this->assertTrue(app(ChecklistResolver::class)->for($i)->isRequired('equivalency'));
    }

    public function test_private_sector_adds_social_insurance(): void
    {
        $i = Instructor::factory()->privateSector()->for(User::factory()->instructor())->create();
        $this->assertTrue(app(ChecklistResolver::class)->for($i)->isRequired('social_insurance'));
    }

    public function test_bachelor_adds_experience_certificate(): void
    {
        $i = Instructor::factory()->bachelor()->for(User::factory()->instructor())->create();
        $plan = app(ChecklistResolver::class)->for($i);
        $this->assertTrue($plan->isRequired('experience'));
        $this->assertFalse($plan->isRequired('equivalency'));
    }

    public function test_all_three_conditions_together(): void
    {
        $i = Instructor::factory()->foreignDegree()->privateSector()->bachelor()->for(User::factory()->instructor())->create();
        $this->assertCount(9, app(ChecklistResolver::class)->for($i)->required);
    }
}

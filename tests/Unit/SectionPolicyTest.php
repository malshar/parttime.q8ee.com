<?php

namespace Tests\Unit;

use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class SectionPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_is_allowed_and_instructor_denied_for_every_section_ability(): void
    {
        $admin = User::factory()->admin()->create();
        $instructor = User::factory()->instructor()->create();
        $section = Section::factory()->for(Term::factory()->open())->create();

        $checks = [
            ['viewAny', Section::class],
            ['import', Section::class],
            ['assign', $section],
            ['unassign', $section],
        ];

        foreach ($checks as [$ability, $arguments]) {
            $this->assertTrue(Gate::forUser($admin)->allows($ability, $arguments), "admin should be allowed to {$ability}");
            $this->assertFalse(Gate::forUser($instructor)->allows($ability, $arguments), "instructor should be denied {$ability}");
        }
    }
}

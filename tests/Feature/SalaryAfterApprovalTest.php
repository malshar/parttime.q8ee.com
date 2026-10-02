<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Instructor\ProfileTest;
use Tests\TestCase;

class SalaryAfterApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChecklistItemSeeder::class);
        $this->user = User::factory()->instructor()->create();
    }

    public function test_profile_saves_without_salary_before_approval(): void
    {
        $payload = ProfileTest::payload();
        unset($payload['basic_salary'], $payload['total_salary']);
        $this->actingAs($this->user)->put(route('instructor.profile.update'), $payload)->assertRedirect(route('instructor.home'))->assertSessionHasNoErrors();
        $this->assertNull($this->user->instructor->fresh()->basic_salary);
    }

    public function test_total_must_not_be_below_basic_when_both_given(): void
    {
        $this->actingAs($this->user)->put(route('instructor.profile.update'), ProfileTest::payload(['basic_salary' => '1000', 'total_salary' => '900']))->assertSessionHasErrors('total_salary');
    }

    public function test_salary_route_forbidden_before_approval_and_for_strangers(): void
    {
        $instructor = Instructor::factory()->for($this->user)->create(['basic_salary' => null, 'total_salary' => null]);
        $this->actingAs($this->user)->put(route('instructor.salary.update'), ['basic_salary' => '900', 'total_salary' => '1200'])->assertForbidden();
        Application::factory()->approved()->for(Term::factory()->open())->for($instructor)->create();
        $this->actingAs(User::factory()->instructor()->create())->put(route('instructor.salary.update'), ['basic_salary' => '900', 'total_salary' => '1200'])->assertForbidden();
    }

    public function test_salary_saved_after_approval_despite_profile_lock_and_audited_with_names_only(): void
    {
        $instructor = Instructor::factory()->for($this->user)->create(['basic_salary' => null, 'total_salary' => null]);
        Application::factory()->approved()->for(Term::factory()->open())->for($instructor)->create();
        $this->assertTrue($instructor->hasLockedApplication());

        $this->actingAs($this->user)->put(route('instructor.salary.update'), ['basic_salary' => '900', 'total_salary' => '1200'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('900', $instructor->fresh()->basic_salary);
        $this->assertDatabaseHas('audit_log', ['action' => 'edit_profile', 'user_id' => $this->user->id, 'details' => 'basic_salary,total_salary']);
        $this->assertDatabaseMissing('audit_log', ['details' => '900']);

        $this->actingAs($this->user)->from(route('instructor.home'))->put(route('instructor.salary.update'), ['basic_salary' => 'abc', 'total_salary' => '1200'])
            ->assertRedirect(route('instructor.home'))->assertSessionHasErrors('basic_salary');
    }
}

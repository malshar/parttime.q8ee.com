<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\Instructor;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use App\Services\Sections\AssignmentService;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Term $term;

    private Application $application;

    private Section $section;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChecklistItemSeeder::class);
        $this->admin = User::factory()->admin()->create();
        $this->term = Term::factory()->open()->create();
        $instructor = Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'محمد أحمد علي الفهد']);
        $this->application = Application::factory()->approved()->for($this->term)->for($instructor)->create();
        $this->section = Section::factory()->for($this->term)->withMeetings()->create();
    }

    public function test_assign_and_unassign_recompute_hours_and_audit(): void
    {
        $this->actingAs($this->admin)->post(route('admin.assignments.store', $this->section), ['application_id' => $this->application->id])->assertRedirect();

        $this->assertDatabaseHas('assignments', ['section_id' => $this->section->id, 'application_id' => $this->application->id, 'created_by' => $this->admin->id]);
        $f = $this->application->fresh();
        $this->assertSame(250, $f->weekly_minutes);
        $this->assertSame('4.2', $f->weeklyHoursLabel());
        $this->assertDatabaseHas('audit_log', ['action' => 'assign_section', 'subject_id' => $this->section->id]);

        $this->actingAs($this->admin)->delete(route('admin.assignments.destroy', $this->section))->assertRedirect();
        $this->assertDatabaseMissing('assignments', ['section_id' => $this->section->id]);
        $this->assertSame(0, $this->application->fresh()->weekly_minutes);
        $this->assertDatabaseHas('audit_log', ['action' => 'unassign_section', 'subject_id' => $this->section->id]);
    }

    public function test_rules_only_approved_same_term_open_term_unassigned(): void
    {
        $svc = app(AssignmentService::class);

        $draft = Application::factory()->for($this->term)->create();
        try { $svc->assign($this->section, $draft, $this->admin); $this->fail('draft assigned'); } catch (\DomainException) {}

        $otherTerm = Application::factory()->approved()->for(Term::factory()->create(['academic_year' => '2025-2026', 'type' => 'summer', 'status' => 'closed']))->create();
        try { $svc->assign($this->section, $otherTerm, $this->admin); $this->fail('cross-term assigned'); } catch (\DomainException) {}

        $svc->assign($this->section, $this->application, $this->admin);
        $second = Application::factory()->approved()->for($this->term)->create();
        try { $svc->assign($this->section, $second, $this->admin); $this->fail('double assigned'); } catch (\DomainException) {}

        $this->term->update(['status' => 'closed']);
        try { $svc->unassign($this->section->fresh(), $this->admin); $this->fail('unassigned on closed term'); } catch (\DomainException $e) { $this->assertTrue(true); }
    }

    public function test_controller_maps_rule_violations_to_errors(): void
    {
        $draft = Application::factory()->for($this->term)->create();
        $this->actingAs($this->admin)->post(route('admin.assignments.store', $this->section), ['application_id' => $draft->id])->assertSessionHasErrors('assign');
        $this->assertDatabaseCount('assignments', 0);
    }

    public function test_suggestions_match_normalised_names_only_when_unique(): void
    {
        $this->section->update(['scheduled_instructor' => 'محمد احمد علي الفهد ']); // hamza dropped, trailing space
        $noMatch = Section::factory()->for($this->term)->create(['scheduled_instructor' => 'د. فلان الفلاني']);
        $twin = Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'محمد أحمد علي الفهد']);
        $ambiguous = Section::factory()->for($this->term)->create(['scheduled_instructor' => 'محمد أحمد علي الفهد']);
        Application::factory()->approved()->for($this->term)->for($twin)->create();

        $s = app(AssignmentService::class)->suggestionsFor($this->term);

        $this->assertArrayNotHasKey($this->section->id, $s);   // two approved instructors share the name → ambiguous
        $this->assertArrayNotHasKey($noMatch->id, $s);
        $this->assertArrayNotHasKey($ambiguous->id, $s);

        $twin->delete();
        $s = app(AssignmentService::class)->suggestionsFor($this->term);
        $this->assertSame($this->application->id, $s[$this->section->id]);
    }

    public function test_instructor_cannot_assign(): void
    {
        $this->actingAs($this->application->instructor->user)->post(route('admin.assignments.store', $this->section), ['application_id' => $this->application->id])->assertForbidden();
    }
}

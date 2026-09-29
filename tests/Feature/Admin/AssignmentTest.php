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
use Mockery;
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

    /**
     * A real Assignment::creating() event hook was tried first (per the review note): make the
     * hook insert a competing row for the same section right before the real Assignment::create()
     * call. That reliably reproduces the unique-constraint violation, but AssignmentService::assign()
     * runs inside DB::transaction(), so the hook's insert happens inside that same transaction — when
     * the real insert then fails and the transaction rolls back, the hook's "competing" row is rolled
     * back with it, leaving zero rows and making the row-count assertion impossible to satisfy on a
     * single-connection SQLite :memory: test database. So this uses the review's sanctioned fallback:
     * a real, already-committed competing assignment (created via a first, real call to assign()),
     * plus a partial mock of Section that forces the exists() guard to (falsely) report "unassigned" —
     * exactly the state a losing concurrent request would observe — so the real Assignment::create()
     * call goes on to hit the real unique constraint on section_id.
     */
    public function test_concurrent_assign_race_yields_domain_error_not_500(): void
    {
        $svc = app(AssignmentService::class);

        $other = Application::factory()->approved()->for($this->term)->create();
        $svc->assign($this->section, $other, $this->admin);
        $this->assertDatabaseCount('assignments', 1);

        $racedSection = Mockery::mock(Section::class)->makePartial();
        $racedSection->forceFill($this->section->getAttributes());
        // Eloquent's implicit belongsTo() name-guessing reads the calling method name off
        // debug_backtrace(), which resolves incorrectly once the call passes through a Mockery
        // partial-mock proxy; supplying the real relation (built off the real, unmocked $this->section)
        // sidesteps that rather than relying on passthrough to the inherited term() body.
        $racedSection->shouldReceive('term')->andReturn($this->section->term());
        $racedSection->shouldReceive('assignment->exists')->andReturn(false);

        try {
            $svc->assign($racedSection, $this->application, $this->admin);
            $this->fail('race should have raised a DomainException, not a raw unique-constraint 500');
        } catch (\DomainException $e) {
            $this->assertSame(__('app.assignments.already_assigned'), $e->getMessage());
        } finally {
            Mockery::close();
        }

        $this->assertDatabaseCount('assignments', 1);
        $this->assertDatabaseHas('assignments', ['section_id' => $this->section->id, 'application_id' => $other->id]);
        $this->assertDatabaseMissing('assignments', ['application_id' => $this->application->id]);
    }

    public function test_instructor_cannot_assign(): void
    {
        $this->actingAs($this->application->instructor->user)->post(route('admin.assignments.store', $this->section), ['application_id' => $this->application->id])->assertForbidden();
    }
}

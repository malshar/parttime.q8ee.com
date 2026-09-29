<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Instructor;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignmentScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Term $term;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChecklistItemSeeder::class);
        $this->admin = User::factory()->admin()->create();
        $this->term = Term::factory()->open()->create();
        $instructor = Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'محمد أحمد علي الفهد']);
        $this->application = Application::factory()->approved()->for($this->term)->for($instructor)->create();
    }

    public function test_assignments_index_shows_suggestion_and_dropdown(): void
    {
        $s = Section::factory()->for($this->term)->withMeetings()->create(['scheduled_instructor' => 'محمد احمد علي الفهد']);

        $r = $this->actingAs($this->admin)->get(route('admin.assignments.index'))->assertOk();
        $r->assertSee(__('app.assignments.suggested'));
        $r->assertSee('<option value="'.$this->application->id.'" selected', false);
        $r->assertSee($s->course_code);
    }

    public function test_application_show_and_instructor_home_list_sections(): void
    {
        $s = Section::factory()->for($this->term)->withMeetings()->create();
        Assignment::create(['application_id' => $this->application->id, 'section_id' => $s->id]);
        app(\App\Services\Sections\AssignmentService::class)->recomputeHours($this->application);

        $this->actingAs($this->admin)->get(route('admin.applications.show', $this->application))->assertOk()
            ->assertSee(__('app.assignments.my_sections'))->assertSee($s->course_code)->assertSee('4.2');

        $this->actingAs($this->application->instructor->user)->get(route('instructor.home'))->assertOk()
            ->assertSee($s->course_code)->assertSee('4.2')->assertDontSee(__('app.assignments.unassign'));
    }

    public function test_dashboard_alerts_for_unassigned_approved_and_missing_sections(): void
    {
        Section::factory()->for($this->term)->create(['missing_since_import' => true, 'course_code' => '7299999']);

        $r = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();
        $r->assertSee(__('app.review.alert_unassigned', ['name' => 'محمد أحمد علي الفهد']));
        $r->assertSee(__('app.review.alert_missing_section', ['section' => '7299999 / 1']));
    }

    public function test_instructor_cannot_open_assignments_index(): void
    {
        $this->actingAs($this->application->instructor->user)->get(route('admin.assignments.index'))->assertForbidden();
    }
}

<?php

namespace Tests\Feature\Instructor;

use App\Models\Application;
use App\Models\ChecklistItem;
use App\Models\ChecklistRenewal;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use App\Services\ApplicationWorkflow;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChecklistItemSeeder::class);
        $this->user = User::factory()->instructor()->create();
        Instructor::factory()->for($this->user)->create();
    }

    public function test_home_without_profile_redirects_to_profile(): void
    {
        $bare = User::factory()->instructor()->create();
        $this->actingAs($bare)->get(route('instructor.home'))->assertRedirect(route('instructor.profile.edit'));
    }

    public function test_home_shows_no_open_term_message(): void
    {
        $this->actingAs($this->user)->get(route('instructor.home'))->assertOk()->assertSee(__('app.terms.none_open'));
    }

    public function test_home_header_shows_user_name_role_and_profile_link(): void
    {
        $this->actingAs($this->user)->get(route('instructor.home'))->assertOk()
            ->assertSee($this->user->name)
            ->assertSee(__('app.auth.roles.instructor'))
            ->assertSee(route('instructor.profile.edit'));
    }

    public function test_start_creates_one_draft_per_open_term(): void
    {
        $term = Term::factory()->open()->create();

        $this->actingAs($this->user)->post(route('instructor.applications.start'))->assertRedirect();
        $this->actingAs($this->user)->post(route('instructor.applications.start'))->assertRedirect();

        $this->assertDatabaseCount('applications', 1);
        $app = Application::firstOrFail();
        $this->assertSame(Application::STATUS_DRAFT, $app->status);
        $this->assertTrue($app->term->is($term));
    }

    public function test_start_refused_when_no_open_term(): void
    {
        Term::factory()->create(['status' => 'closed']);
        $this->actingAs($this->user)->post(route('instructor.applications.start'))->assertRedirect(route('instructor.home'));
        $this->assertDatabaseCount('applications', 0);
    }

    public function test_start_without_profile_redirects_to_profile(): void
    {
        Term::factory()->open()->create();
        $bare = User::factory()->instructor()->create();

        $this->actingAs($bare)->post(route('instructor.applications.start'))->assertRedirect(route('instructor.profile.edit'));
        $this->assertDatabaseCount('applications', 0);
    }

    public function test_show_lists_required_department_and_not_applicable_items(): void
    {
        $term = Term::factory()->open()->create();
        $app = Application::factory()->for($term)->for($this->user->instructor)->create();

        $r = $this->actingAs($this->user)->get(route('instructor.applications.show', $app))->assertOk();
        $r->assertSee('صورة البطاقة المدنية سارية المفعول');
        $r->assertSee(__('app.applications.department_items'));
        $r->assertSee('كشف التكليف للمنتدب');
        $r->assertSee(__('app.applications.not_applicable_items'));
        $r->assertSee('صورة من معادلة المؤهل العلمي');
    }

    public function test_other_instructor_cannot_view_application(): void
    {
        $term = Term::factory()->open()->create();
        $app = Application::factory()->for($term)->for($this->user->instructor)->create();
        $other = User::factory()->instructor()->create();
        Instructor::factory()->for($other)->create();

        $this->actingAs($other)->get(route('instructor.applications.show', $app))->assertForbidden();
    }

    public function test_changing_profile_changes_required_items_and_keeps_uploads(): void
    {
        $term = Term::factory()->open()->create();
        $app = Application::factory()->for($term)->for($this->user->instructor)->create();
        $this->user->instructor->update(['degree_country' => 'GB']);

        $checklist = app(ApplicationWorkflow::class)->checklist($app->fresh());
        $this->assertSame('missing', $checklist['equivalency']['state']);

        $this->user->instructor->update(['degree_country' => 'KW']);
        $this->assertArrayNotHasKey('equivalency', app(ApplicationWorkflow::class)->checklist($app->fresh()));
    }

    public function test_closed_term_application_is_not_editable(): void
    {
        $term = Term::factory()->create(['status' => 'closed']);
        $app = Application::factory()->for($term)->for($this->user->instructor)->create();

        $this->assertFalse($app->isEditable());
        $this->actingAs($this->user)->get(route('instructor.applications.show', $app))->assertOk()->assertSee(__('app.applications.term_closed'));
    }

    public function test_on_file_row_shows_badge_source_term_and_optional_upload(): void
    {
        $this->seed(ChecklistItemSeeder::class);
        $user = User::factory()->instructor()->create();
        $instructor = Instructor::factory()->for($user)->create();
        $old = Term::factory()->create(['academic_year' => '2025-2026', 'type' => 'second', 'teaching_starts_on' => '2026-01-11', 'teaching_ends_on' => '2026-05-14', 'status' => Term::STATUS_CLOSED]);
        $previous = Application::factory()->for($old)->for($instructor)->create(['status' => Application::STATUS_APPROVED]);
        Document::factory()->for($previous)->forItem('degree')->accepted()->create(['reviewed_at' => now()->subMonth()]);
        $application = Application::factory()->for(Term::factory()->open()->create(['academic_year' => '2026-2027']))->for($instructor)->create();
        $item = ChecklistItem::where('code', 'iban')->first();
        ChecklistRenewal::factory()->for($application)->create(['checklist_item_id' => $item->id, 'reason' => 'الآيبان تغير']);

        $r = $this->actingAs($user)->get(route('instructor.applications.show', $application))->assertOk();

        $r->assertSee(__('app.documents.states.on_file'));
        $r->assertSee(__('app.documents.on_file_from', ['term' => $old->label()]));
        $r->assertSee(__('app.documents.newer_copy'));
        $r->assertSee(__('app.documents.renewal_requested', ['reason' => 'الآيبان تغير']));
    }
}

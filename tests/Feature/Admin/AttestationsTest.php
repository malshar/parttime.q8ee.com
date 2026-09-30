<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Attestation;
use App\Models\Instructor;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttestationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Term $term;

    private Application $assigned;

    private Application $unassigned;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->term = Term::factory()->open()->create(['type' => 'summer', 'academic_year' => '2025-2026', 'teaching_starts_on' => '2026-06-07', 'teaching_ends_on' => '2026-07-23']);
        $this->assigned = Application::factory()->approved()->for($this->term)->for(Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'أحمد سالم']))->create();
        Assignment::factory()->for($this->assigned)->for(Section::factory()->for($this->term)->withMeetings()->create())->create();
        $this->unassigned = Application::factory()->approved()->for($this->term)->for(Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'بدر ناصر']))->create();
    }

    public function test_instructor_is_forbidden_on_index_and_generate(): void
    {
        $user = User::factory()->instructor()->create();
        $this->actingAs($user)->get(route('admin.attestations.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.attestations.generate'), ['term' => $this->term->id, 'month' => 1])->assertForbidden();
    }

    public function test_index_lists_only_assigned_approved_applications_with_status(): void
    {
        Attestation::factory()->exported()->for($this->assigned)->create(['year' => 2026, 'month' => 6]);

        $r = $this->actingAs($this->admin)->get(route('admin.attestations.index', ['term' => $this->term->id, 'month' => 1]))->assertOk();

        $r->assertSee('أحمد سالم');
        $r->assertDontSee('بدر ناصر');
        $r->assertSee(__('app.attestations.status_exported'));
        $r->assertSee('الشهر الأول/ يونيو');
        $r->assertSee('الشهر الثاني/ يوليو');
    }

    public function test_index_defaults_to_the_current_month_inside_the_term(): void
    {
        $this->travelTo('2026-07-10');

        $r = $this->actingAs($this->admin)->get(route('admin.attestations.index'))->assertOk();

        $r->assertSee('<option value="2" selected', false);
        $r->assertSee(__('app.attestations.status_none'));
    }

    public function test_generate_missing_creates_only_missing_and_audits(): void
    {
        $second = Application::factory()->approved()->for($this->term)->for(Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'خالد عيسى']))->create();
        Assignment::factory()->for($second)->for(Section::factory()->for($this->term)->withMeetings()->create())->create();
        Attestation::factory()->for($this->assigned)->create(['year' => 2026, 'month' => 6]);

        $this->actingAs($this->admin)->post(route('admin.attestations.generate'), ['term' => $this->term->id, 'month' => 1])
            ->assertRedirect(route('admin.attestations.index', ['term' => $this->term->id, 'month' => 1]))
            ->assertSessionHas('status', __('app.attestations.generated_count', ['n' => 1]));

        $this->assertSame(1, Attestation::where('application_id', $second->id)->count());
        $this->assertSame(1, Attestation::where('application_id', $this->assigned->id)->count());
        $this->assertDatabaseHas('audit_log', ['action' => 'generate_attestation', 'subject_id' => Attestation::where('application_id', $second->id)->value('id'), 'details' => 'new', 'user_id' => $this->admin->id]);
    }

    public function test_generate_missing_on_closed_term_is_refused(): void
    {
        $this->term->update(['status' => Term::STATUS_CLOSED]);

        $this->actingAs($this->admin)->post(route('admin.attestations.generate'), ['term' => $this->term->id, 'month' => 1])
            ->assertSessionHasErrors('attestation');
        $this->assertSame(0, Attestation::count());
    }

    public function test_generate_missing_skips_instructor_whose_assignments_were_removed_but_keeps_existing(): void
    {
        $a = Attestation::factory()->for($this->assigned)->create(['year' => 2026, 'month' => 6]);
        Assignment::where('application_id', $this->assigned->id)->delete();

        $this->actingAs($this->admin)->post(route('admin.attestations.generate'), ['term' => $this->term->id, 'month' => 1])
            ->assertSessionHas('status', __('app.attestations.generated_count', ['n' => 0]));

        $this->assertDatabaseHas('attestations', ['id' => $a->id]);
        $r = $this->actingAs($this->admin)->get(route('admin.attestations.index', ['term' => $this->term->id, 'month' => 1]));
        $r->assertDontSee('أحمد سالم');
    }
}

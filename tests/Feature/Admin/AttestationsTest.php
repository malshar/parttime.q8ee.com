<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Attestation;
use App\Models\Instructor;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use App\Services\Attestations\AttestationGenerator;
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

    private function generated(): Attestation
    {
        return app(AttestationGenerator::class)->generate($this->assigned, 2026, 6, $this->admin);
    }

    public function test_show_page_lists_weeks_and_generated_values(): void
    {
        $a = $this->generated();
        $a->weeks[0]->update(['theory_minutes' => 60]);

        $r = $this->actingAs($this->admin)->get(route('admin.attestations.show', $a))->assertOk();

        $r->assertSee('الشهر الأول/ يونيو');
        $r->assertSee('7-11');
        $r->assertSee(__('app.attestations.generated_value', ['value' => '2.5']));
        $r->assertSee('name="weeks['.$a->weeks[0]->id.'][theory_hours]"', false);
        $r->assertSee('value="1"', false);
    }

    public function test_save_converts_hours_to_minutes_keeps_twins_and_audits_changed_columns(): void
    {
        $a = $this->generated();
        $w = $a->weeks[1];

        $this->actingAs($this->admin)->put(route('admin.attestations.update', $a), ['weeks' => [
            $w->id => ['courses_text' => $w->courses_text, 'student_count' => 21, 'theory_hours' => '1.333', 'practical_hours' => '1.5', 'field_hours' => '0', 'note_ar' => $w->note_ar],
        ]])->assertRedirect(route('admin.attestations.show', $a))->assertSessionHas('status', __('app.attestations.saved'));

        $w->refresh();
        $this->assertSame(21, $w->student_count);
        $this->assertSame(80, $w->theory_minutes);
        $this->assertSame(90, $w->practical_minutes);
        $this->assertSame(150, $w->generated_theory_minutes);
        $this->assertTrue($w->isEdited());
        $this->assertDatabaseHas('audit_log', ['action' => 'update_attestation', 'subject_id' => $a->id, 'details' => 'practical_minutes,student_count,theory_minutes']);
    }

    public function test_save_rejects_comma_decimals_and_negative_counts(): void
    {
        $a = $this->generated();
        $w = $a->weeks[0];

        $this->actingAs($this->admin)->from(route('admin.attestations.show', $a))->put(route('admin.attestations.update', $a), ['weeks' => [
            $w->id => ['courses_text' => '', 'student_count' => -1, 'theory_hours' => '2,5', 'practical_hours' => '0', 'field_hours' => '0', 'note_ar' => ''],
        ]])->assertRedirect(route('admin.attestations.show', $a))->assertSessionHasErrors(["weeks.{$w->id}.theory_hours", "weeks.{$w->id}.student_count"]);

        $this->assertSame(150, $w->fresh()->theory_minutes);
    }

    public function test_save_refused_when_exported_or_term_closed(): void
    {
        $a = $this->generated();
        $w = $a->weeks[0];
        $payload = ['weeks' => [$w->id => ['courses_text' => 'x', 'student_count' => 1, 'theory_hours' => '1', 'practical_hours' => '0', 'field_hours' => '0', 'note_ar' => '']]];

        $a->update(['status' => Attestation::STATUS_EXPORTED]);
        $this->actingAs($this->admin)->put(route('admin.attestations.update', $a), $payload)->assertSessionHasErrors('attestation');

        $a->update(['status' => Attestation::STATUS_GENERATED]);
        $this->term->update(['status' => Term::STATUS_CLOSED]);
        $this->actingAs($this->admin)->put(route('admin.attestations.update', $a), $payload)->assertSessionHasErrors('attestation');
        $this->assertSame(150, $w->fresh()->theory_minutes);
    }

    public function test_regenerate_discards_edits_and_audits(): void
    {
        $a = $this->generated();
        $a->weeks[0]->update(['student_count' => 99]);

        $this->actingAs($this->admin)->post(route('admin.attestations.regenerate', $a))->assertRedirect(route('admin.attestations.show', $a));

        $this->assertSame(0, $a->fresh()->weeks[0]->student_count);
        $this->assertDatabaseHas('audit_log', ['action' => 'generate_attestation', 'subject_id' => $a->id, 'details' => 'regenerated']);
    }

    public function test_unlock_returns_exported_to_generated_and_regenerate_then_works(): void
    {
        $a = $this->generated();
        $a->update(['status' => Attestation::STATUS_EXPORTED, 'exported_at' => now()]);

        $this->actingAs($this->admin)->post(route('admin.attestations.unlock', $a))->assertRedirect(route('admin.attestations.show', $a));
        $this->assertSame(Attestation::STATUS_GENERATED, $a->fresh()->status);
        $this->assertNotNull($a->fresh()->exported_at);
        $this->assertDatabaseHas('audit_log', ['action' => 'unlock_attestation', 'subject_id' => $a->id]);

        $this->actingAs($this->admin)->post(route('admin.attestations.regenerate', $a))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('admin.attestations.unlock', $a))->assertSessionHasErrors('attestation');
    }

    public function test_instructor_forbidden_on_show_update_regenerate_unlock(): void
    {
        $a = $this->generated();
        $user = User::factory()->instructor()->create();
        $this->actingAs($user)->get(route('admin.attestations.show', $a))->assertForbidden();
        $this->actingAs($user)->put(route('admin.attestations.update', $a), [])->assertForbidden();
        $this->actingAs($user)->post(route('admin.attestations.regenerate', $a))->assertForbidden();
        $this->actingAs($user)->post(route('admin.attestations.unlock', $a))->assertForbidden();
    }
}

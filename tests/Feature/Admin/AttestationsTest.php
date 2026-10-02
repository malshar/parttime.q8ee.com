<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Attestation;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use App\Services\Attestations\AttestationGenerator;
use Database\Seeders\ChecklistItemSeeder;
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
        $this->seed(ChecklistItemSeeder::class);
        $this->admin = User::factory()->admin()->create();
        $this->term = Term::factory()->open()->create(['type' => 'summer', 'academic_year' => '2025-2026', 'teaching_starts_on' => '2026-06-07', 'teaching_ends_on' => '2026-07-23']);
        $this->assigned = Application::factory()->approved()->for($this->term)->for(Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'أحمد سالم']))->create();
        Assignment::factory()->for($this->assigned)->for(Section::factory()->for($this->term)->withMeetings()->create())->create();
        $this->completeStageTwo($this->assigned);
        $this->unassigned = Application::factory()->approved()->for($this->term)->for(Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'بدر ناصر']))->create();
    }

    /** Marks an approved application's stage 2 complete: the four stage-2 items accepted, plus social_insurance if private-sector, and both salary fields set. */
    private function completeStageTwo(Application $application): void
    {
        $codes = ['salary_cert', 'iban', 'employer_approval', 'undertaking'];
        if ($application->instructor->isPrivateSector()) {
            $codes[] = 'social_insurance';
        }
        foreach ($codes as $code) {
            Document::factory()->for($application)->forItem($code)->accepted()->create();
        }
        $application->instructor->update(['basic_salary' => '900', 'total_salary' => '1200']);
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
        $this->completeStageTwo($second);
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
        $this->assertSame(1, Attestation::count());
        // The existing attestation stays reachable: its row is still on the month page (spec §5.1) ...
        $r = $this->actingAs($this->admin)->get(route('admin.attestations.index', ['term' => $this->term->id, 'month' => 1]))->assertOk();
        $r->assertSee('أحمد سالم');
        $r->assertSee(route('admin.attestations.show', $a));
        $r->assertDontSee('بدر ناصر');
        // ... and the application page still links to the attestations.
        $this->actingAs($this->admin)->get(route('admin.applications.show', $this->assigned))
            ->assertSee(route('admin.attestations.index', ['term' => $this->term->id]));
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

    /** @return array<int, array<string, string|int>> the form payload for every week, as the show page would post it */
    private function unchangedPayload(Attestation $a, string $eol = "\n"): array
    {
        return $a->weeks->mapWithKeys(fn ($w) => [$w->id => [
            'courses_text' => str_replace("\n", $eol, $w->courses_text), 'student_count' => $w->student_count,
            'theory_hours' => Section::hoursForForm($w->theory_minutes), 'practical_hours' => Section::hoursForForm($w->practical_minutes),
            'field_hours' => Section::hoursForForm($w->field_minutes), 'note_ar' => str_replace("\n", $eol, $w->note_ar),
        ]])->all();
    }

    public function test_crlf_from_textareas_is_not_an_edit(): void
    {
        Assignment::factory()->for($this->assigned)->for(Section::factory()->for($this->term)->withMeetings()->create(['course_name_ar' => 'الرسم الهندسي']))->create();
        $a = $this->generated();
        $this->assertStringContainsString("\n", $a->weeks[0]->courses_text);

        $this->actingAs($this->admin)->put(route('admin.attestations.update', $a), ['weeks' => $this->unchangedPayload($a, "\r\n")])
            ->assertRedirect(route('admin.attestations.show', $a));

        $this->assertSame(0, AuditLog::where('action', 'update_attestation')->where('details', '!=', '')->count());
        foreach ($a->fresh()->weeks as $w) {
            $this->assertFalse($w->isEdited(), "week {$w->week_number}");
        }
        $this->actingAs($this->admin)->get(route('admin.attestations.show', $a))->assertOk()->assertDontSee('المولد:');
    }

    public function test_save_without_changes_writes_no_audit_row(): void
    {
        $a = $this->generated();

        $this->actingAs($this->admin)->put(route('admin.attestations.update', $a), ['weeks' => $this->unchangedPayload($a)])
            ->assertRedirect(route('admin.attestations.show', $a))->assertSessionHas('status', __('app.attestations.saved'));

        $this->assertDatabaseMissing('audit_log', ['action' => 'update_attestation']);
    }

    public function test_show_header_has_masked_civil_id_and_iban_only(): void
    {
        $a = $this->generated();
        $i = $this->assigned->instructor;

        $r = $this->actingAs($this->admin)->get(route('admin.attestations.show', $a))->assertOk();

        $r->assertSee($i->maskedCivilId());
        $r->assertSee($i->maskedIban());
        $r->assertDontSee($i->civil_id);
        $r->assertDontSee($i->iban);
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

    public function test_dashboard_alerts_missing_for_started_month_and_unexported_for_finished_month(): void
    {
        $this->travelTo('2026-07-10');
        Attestation::factory()->for($this->assigned)->create(['year' => 2026, 'month' => 6]);   // generated, June ended → unexported

        $r = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();

        $r->assertSee(__('app.attestations.alert_unexported', ['n' => 1, 'month' => 'الشهر الأول/ يونيو']));
        $r->assertSee(__('app.attestations.alert_missing', ['n' => 1, 'month' => 'الشهر الثاني/ يوليو']));
        $r->assertDontSee(__('app.attestations.alert_missing', ['n' => 1, 'month' => 'الشهر الأول/ يونيو']));
        $r->assertSee('1 مزاولة غير مولدة في الشهر الثاني/ يوليو.');   // wording: "month" said once
        $r->assertSee(route('admin.attestations.index', ['term' => $this->term->id, 'month' => 2]));
    }

    public function test_dashboard_unexported_alert_counts_attestations_of_unlisted_instructors(): void
    {
        $this->travelTo('2026-07-10');
        Attestation::factory()->for($this->assigned)->create(['year' => 2026, 'month' => 6]);
        Assignment::where('application_id', $this->assigned->id)->delete();

        $r = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();

        $r->assertSee(__('app.attestations.alert_unexported', ['n' => 1, 'month' => 'الشهر الأول/ يونيو']));
    }

    public function test_dashboard_has_no_attestation_alerts_before_the_term_starts(): void
    {
        $this->travelTo('2026-05-01');

        $r = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();

        // Not a bare assertDontSee('مزاولة'): the nav bar always links to
        // app.attestations.title ("المزاولة الشهرية"), which contains that
        // substring regardless of alerts. Assert on the alert phrasing itself.
        $r->assertDontSee('غير مولدة في');
        $r->assertDontSee('غير مصدرة في');
    }

    public function test_save_with_stale_week_ids_is_refused_and_writes_nothing(): void
    {
        $a = $this->generated();
        $oldId = $a->weeks[0]->id;
        $this->actingAs($this->admin)->post(route('admin.attestations.regenerate', $a));   // new week rows, new ids

        $this->actingAs($this->admin)->put(route('admin.attestations.update', $a), ['weeks' => [
            $oldId => ['courses_text' => 'x', 'student_count' => 5, 'theory_hours' => '1', 'practical_hours' => '0', 'field_hours' => '0', 'note_ar' => ''],
        ]])->assertSessionHasErrors(['attestation' => __('app.attestations.stale_form')]);

        $this->assertSame(0, $a->fresh()->weeks->where('student_count', 5)->count());
        $this->assertDatabaseMissing('audit_log', ['action' => 'update_attestation']);
    }

    public function test_regenerate_button_hidden_and_action_refused_without_assignments(): void
    {
        $a = $this->generated();
        Assignment::where('application_id', $this->assigned->id)->delete();

        $this->actingAs($this->admin)->get(route('admin.attestations.show', $a))->assertDontSee(route('admin.attestations.regenerate', $a));
        $this->actingAs($this->admin)->post(route('admin.attestations.regenerate', $a))->assertSessionHasErrors(['attestation' => __('app.attestations.no_assignments')]);
    }
}

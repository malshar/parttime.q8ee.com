<?php

namespace Tests\Feature\Admin;

use App\Mail\DocumentsRejected;
use App\Models\Application;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $instructorUser;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        $this->seed(ChecklistItemSeeder::class);
        $this->admin = User::factory()->admin()->create();
        $this->instructorUser = User::factory()->instructor()->create();
        $instructor = Instructor::factory()->for($this->instructorUser)->create();
        $this->application = Application::factory()->submitted()->for(Term::factory()->open())->for($instructor)->create();
        foreach (['civil_id', 'degree', 'transcript_bachelor', 'transcript_master', 'salary_cert', 'iban', 'employer_approval', 'undertaking'] as $code) {
            Document::factory()->for($this->application)->forItem($code)->create();
        }
    }

    private function acceptAll(): void
    {
        foreach ($this->application->latestDocuments() as $doc) {
            $this->actingAs($this->admin)->post(route('admin.documents.review', $doc), ['status' => 'accepted']);
        }
    }

    public function test_dashboard_lists_submitted_applications(): void
    {
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()
            ->assertSee($this->application->instructor->full_name)->assertSee(__('app.applications.statuses.submitted'));
    }

    public function test_applications_index_filters_on_complete(): void
    {
        $this->application->update(['status' => Application::STATUS_COMPLETE]);
        $other = Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'متقدم آخر قيد المراجعة']);
        Application::factory()->submitted()->for($this->application->term)->for($other)->create();

        $this->actingAs($this->admin)->get(route('admin.applications.index', ['status' => 'complete']))->assertOk()
            ->assertSee('<option value="complete" selected', false)
            ->assertSee($this->application->instructor->full_name)
            ->assertDontSee('متقدم آخر قيد المراجعة');
    }

    public function test_show_masks_sensitive_until_reveal_which_is_audited(): void
    {
        $civil = $this->application->instructor->civil_id;
        $r = $this->actingAs($this->admin)->get(route('admin.applications.show', $this->application))->assertOk();
        $r->assertSee($this->application->instructor->maskedCivilId())->assertDontSee($civil);
        $this->assertSame(Application::STATUS_UNDER_REVIEW, $this->application->fresh()->status);

        $this->actingAs($this->admin)->post(route('admin.applications.reveal', $this->application))->assertRedirect();
        $this->actingAs($this->admin)->get(route('admin.applications.show', $this->application))->assertSee($civil);
        $this->assertDatabaseHas('audit_log', ['user_id' => $this->admin->id, 'action' => 'reveal_sensitive', 'subject_id' => $this->application->id]);
    }

    public function test_rejecting_a_document_marks_incomplete_without_mailing(): void
    {
        $doc = $this->application->latestDocuments()->get('iban');

        $this->actingAs($this->admin)->post(route('admin.documents.review', $doc), ['status' => 'rejected', 'reason' => 'غير واضح'])
            ->assertRedirect();

        $this->assertSame('rejected', $doc->fresh()->status);
        $this->assertSame('غير واضح', $doc->fresh()->rejection_reason);
        $this->assertSame(Application::STATUS_INCOMPLETE, $this->application->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_reject_requires_reason(): void
    {
        $doc = $this->application->latestDocuments()->get('iban');
        $this->actingAs($this->admin)->post(route('admin.documents.review', $doc), ['status' => 'rejected'])->assertSessionHasErrors('reason');
    }

    public function test_complete_blocked_until_latest_versions_all_accepted(): void
    {
        // iban is a stage-2 item (5b): the complete gate now reads stage-1 rows only, so the
        // rejected/re-uploaded item must be a stage-1 one (civil_id) to still block completion.
        $this->acceptAll();
        $civilId = $this->application->latestDocuments()->get('civil_id');
        $this->actingAs($this->admin)->post(route('admin.documents.review', $civilId), ['status' => 'rejected', 'reason' => 'x']);

        $this->actingAs($this->admin)->post(route('admin.applications.complete', $this->application))->assertSessionHasErrors('complete');

        // Applicant re-uploads (v2, pending) → still blocked; admin accepts v2 → complete works even though v1 stays rejected.
        $v2 = Document::factory()->for($this->application)->forItem('civil_id')->create(['version' => 2]);
        $this->actingAs($this->admin)->post(route('admin.applications.complete', $this->application))->assertSessionHasErrors('complete');
        $this->actingAs($this->admin)->post(route('admin.documents.review', $v2), ['status' => 'accepted']);

        $this->actingAs($this->admin)->post(route('admin.applications.complete', $this->application))->assertRedirect();

        $fresh = $this->application->fresh();
        $this->assertSame(Application::STATUS_COMPLETE, $fresh->status);
    }

    public function test_complete_blocked_on_closed_term(): void
    {
        $this->acceptAll();
        $this->application->term->update(['status' => 'closed']);

        $this->actingAs($this->admin)->post(route('admin.applications.complete', $this->application))->assertSessionHasErrors('complete');
        $this->assertNotSame(Application::STATUS_COMPLETE, $this->application->fresh()->status);
    }

    public function test_document_review_refused_on_final_application(): void
    {
        // degree is a stage-1 item (5b): a stage-2 item (e.g. iban) is now reviewable while
        // approved (see ApprovedPhaseTest), so this generic final-application refusal must use
        // a stage-1 item to stay meaningful.
        $this->acceptAll();
        $this->application->update(['status' => Application::STATUS_APPROVED, 'decided_at' => now()]);
        $doc = $this->application->latestDocuments()->get('degree');

        $this->actingAs($this->admin)->post(route('admin.documents.review', $doc), ['status' => 'rejected', 'reason' => 'x'])
            ->assertSessionHasErrors('review');

        $this->assertSame('accepted', $doc->fresh()->status);
        $this->assertSame(Application::STATUS_APPROVED, $this->application->fresh()->status);
        Mail::assertNotSent(DocumentsRejected::class);
    }

    public function test_admin_document_download_is_audited_and_instructor_cannot_use_admin_routes(): void
    {
        $doc = $this->application->latestDocuments()->get('civil_id');
        Storage::disk('local')->put($doc->path, 'pdf');

        $this->actingAs($this->admin)->get(route('admin.documents.download', $doc))->assertOk();
        $this->assertDatabaseHas('audit_log', ['action' => 'download_document', 'subject_id' => $doc->id]);

        $this->actingAs($this->instructorUser)->get(route('admin.documents.download', $doc))->assertForbidden();
        $this->actingAs($this->instructorUser)->post(route('admin.documents.review', $doc), ['status' => 'accepted'])->assertForbidden();
    }

    public function test_document_review_blocked_on_closed_term(): void
    {
        $this->application->term->update(['status' => 'closed']);
        $doc = $this->application->latestDocuments()->get('iban');

        $this->actingAs($this->admin)->post(route('admin.documents.review', $doc), ['status' => 'rejected', 'reason' => 'x'])
            ->assertSessionHasErrors(['review' => __('app.applications.term_closed')]);

        $this->assertSame('pending', $doc->fresh()->status);
        $this->assertSame(Application::STATUS_SUBMITTED, $this->application->fresh()->status);
        Mail::assertNotSent(DocumentsRejected::class);

        $this->actingAs($this->admin)->get(route('admin.applications.show', $this->application))->assertOk()
            ->assertSee(__('app.applications.term_closed'))
            ->assertDontSee(route('admin.documents.review', $doc))
            ->assertDontSee(route('admin.applications.committee', $this->application));
    }

    public function test_superseded_document_version_cannot_be_reviewed(): void
    {
        $v1 = $this->application->latestDocuments()->get('iban');
        Document::factory()->for($this->application)->forItem('iban')->create(['version' => 2]);

        $this->actingAs($this->admin)->post(route('admin.documents.review', $v1), ['status' => 'rejected', 'reason' => 'x'])
            ->assertSessionHasErrors(['review' => __('app.review.superseded_version')]);

        $this->assertSame('pending', $v1->fresh()->status);
        $this->assertNull($v1->fresh()->reviewed_by);
        $this->assertSame(Application::STATUS_SUBMITTED, $this->application->fresh()->status);
        Mail::assertNotSent(DocumentsRejected::class);
    }

    public function test_show_warns_when_bachelor_experience_below_ten(): void
    {
        $warning = __('app.review.experience_below_min', ['years' => 7]);
        $this->application->instructor->update(['highest_degree' => 'bachelor', 'experience_years' => 7]);
        $this->actingAs($this->admin)->get(route('admin.applications.show', $this->application))->assertOk()->assertSee($warning);

        $this->application->instructor->update(['experience_years' => 12]);
        $this->actingAs($this->admin)->get(route('admin.applications.show', $this->application))->assertOk()
            ->assertDontSee(__('app.review.experience_below_min', ['years' => 12]));

        $this->application->instructor->update(['highest_degree' => 'master', 'experience_years' => null]);
        $this->actingAs($this->admin)->get(route('admin.applications.show', $this->application))->assertOk()
            ->assertDontSee(__('app.review.experience_below_min', ['years' => '']));
    }

    public function test_decision_can_be_set_after_approval(): void
    {
        $this->acceptAll();
        $this->application->update(['status' => Application::STATUS_APPROVED, 'decided_at' => now()]);
        $this->assertSame(Application::STATUS_APPROVED, $this->application->fresh()->status);

        $this->actingAs($this->admin)->get(route('admin.applications.show', $this->application))->assertOk()
            ->assertSee(route('admin.applications.decision', $this->application));

        $this->actingAs($this->admin)->post(route('admin.applications.decision', $this->application), [
            'assignment_decision_number' => '456/2026', 'assignment_decision_date' => '2026-10-05',
        ])->assertRedirect()->assertSessionHas('status', __('app.review.decision_saved'));

        $fresh = $this->application->fresh();
        $this->assertSame('456/2026', $fresh->assignment_decision_number);
        $this->assertSame('2026-10-05', $fresh->assignment_decision_date->toDateString());
        $this->assertDatabaseHas('audit_log', ['user_id' => $this->admin->id, 'action' => 'set_decision', 'subject_id' => $this->application->id]);

        $this->actingAs($this->admin)->get(route('admin.applications.show', $this->application))->assertSee('456/2026');

        $this->actingAs($this->admin)->post(route('admin.applications.decision', $this->application), [])
            ->assertSessionHasErrors(['assignment_decision_number', 'assignment_decision_date']);

        $this->actingAs($this->instructorUser)->post(route('admin.applications.decision', $this->application), [
            'assignment_decision_number' => '1', 'assignment_decision_date' => '2026-10-05',
        ])->assertForbidden();
    }

    public function test_decision_refused_when_not_approved(): void
    {
        $this->actingAs($this->admin)->post(route('admin.applications.decision', $this->application), [
            'assignment_decision_number' => '456/2026', 'assignment_decision_date' => '2026-10-05',
        ])->assertSessionHasErrors('decision');

        $this->assertNull($this->application->fresh()->assignment_decision_number);
        $this->assertDatabaseMissing('audit_log', ['action' => 'set_decision']);
    }

    public function test_show_header_and_profile_card_show_role_and_applicant_email(): void
    {
        $r = $this->actingAs($this->admin)->get(route('admin.applications.show', $this->application))->assertOk();

        $r->assertSee(__('app.auth.roles.admin'));
        $r->assertSee($this->admin->name);
        $r->assertSee('mailto:'.$this->instructorUser->email, false);
        $r->assertSee($this->instructorUser->email);
    }

    public function test_pdf_document_row_offers_popup_and_docx_row_does_not(): void
    {
        $pdf = $this->application->latestDocuments()->get('civil_id');
        $docx = Document::factory()->for($this->application)->forItem('degree')->create([
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'version' => 2,
        ]);

        $r = $this->actingAs($this->admin)->get(route('admin.applications.show', $this->application))->assertOk();

        $r->assertSee('data-doc-url="'.route('admin.documents.view', $pdf).'"', false);
        $r->assertDontSee('data-doc-url="'.route('admin.documents.view', $docx).'"', false);
    }

    public function test_admin_sees_on_file_link_and_fresh_copy_form_only_when_allowed(): void
    {
        $instructor = $this->application->instructor;
        $old = Term::factory()->create(['academic_year' => '2025-2026', 'type' => 'second', 'teaching_starts_on' => '2026-01-11', 'teaching_ends_on' => '2026-05-14', 'status' => Term::STATUS_CLOSED]);
        $previous = Application::factory()->for($old)->for($instructor)->create(['status' => Application::STATUS_APPROVED]);
        $src = Document::factory()->for($previous)->forItem('degree')->accepted()->create(['reviewed_at' => now()->subMonth()]);
        $this->application->latestDocuments()->get('degree')->delete();

        $r = $this->actingAs($this->admin)->get(route('admin.applications.show', $this->application))->assertOk();
        $r->assertSee(__('app.documents.states.on_file'));
        $r->assertSee(route('admin.documents.view', $src));
        $r->assertSee(route('admin.applications.renewals.store', [$this->application, 'degree']));

        $this->application->update(['status' => Application::STATUS_APPROVED]);
        $this->actingAs($this->admin)->get(route('admin.applications.show', $this->application))
            ->assertDontSee(route('admin.applications.renewals.store', [$this->application, 'degree']));
    }
}

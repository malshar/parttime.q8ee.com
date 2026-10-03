<?php

namespace Tests\Feature;

use App\Mail\ContinuationApproved;
use App\Models\Application;
use App\Models\ChecklistExemption;
use App\Models\CommitteeApproval;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use App\Services\ApplicationWorkflow;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContinuationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Instructor $instructor;

    private Term $first;

    private Term $second;

    private Application $initial;

    private ApplicationWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['mail.admin_notify' => 'admin@example.com']);
        $this->seed(ChecklistItemSeeder::class);
        $this->user = User::factory()->instructor()->create();
        $this->instructor = Instructor::factory()->for($this->user)->create(['basic_salary' => '900', 'total_salary' => '1200']);
        $this->first = Term::factory()->create(['academic_year' => '2026-2027', 'type' => 'first', 'teaching_starts_on' => '2026-09-13', 'teaching_ends_on' => '2026-12-24', 'status' => 'closed']);
        $this->second = Term::factory()->open()->create(['academic_year' => '2026-2027', 'type' => 'second', 'teaching_starts_on' => '2027-02-07', 'teaching_ends_on' => '2027-05-27']);
        $this->initial = Application::factory()->approved()->for($this->first)->for($this->instructor)->create();
        foreach (['civil_id', 'degree', 'transcript_bachelor', 'transcript_master', 'salary_cert', 'iban', 'employer_approval', 'undertaking'] as $code) {
            Document::factory()->for($this->initial)->forItem($code)->accepted()->create();
        }
        $approval = CommitteeApproval::factory()->for($this->instructor)->create(['academic_year' => '2026-2027']);
        $this->initial->update(['approval_id' => $approval->id]);
        $this->workflow = app(ApplicationWorkflow::class);
    }

    public function test_start_makes_a_continuation_when_the_year_is_approved(): void
    {
        $app = $this->workflow->start($this->instructor, $this->second);
        $this->assertSame(Application::KIND_CONTINUATION, $app->kind);
        $this->assertSame($this->instructor->approvalFor('2026-2027')->id, $app->approval_id);
    }

    public function test_start_is_initial_without_an_approval_or_in_another_year(): void
    {
        $other = Instructor::factory()->for(User::factory()->instructor())->create();
        $this->assertSame(Application::KIND_INITIAL, $this->workflow->start($other, $this->second)->kind);

        $next = Term::factory()->open()->create(['academic_year' => '2027-2028', 'type' => 'first', 'teaching_starts_on' => '2027-09-12', 'teaching_ends_on' => '2027-12-23']);
        $this->assertSame(Application::KIND_INITIAL, $this->workflow->start($this->instructor, $next)->kind);
    }

    public function test_continuation_requires_only_renewing_papers(): void
    {
        $app = $this->workflow->start($this->instructor, $this->second);
        $rows = $this->workflow->checklist($app);
        $this->assertSame(ApplicationWorkflow::STATE_ON_FILE, $rows['degree']['state']);
        $this->assertSame(ApplicationWorkflow::STATE_ON_FILE, $rows['iban']['state']);
        $this->assertSame(['salary_cert', 'employer_approval', 'undertaking'], array_keys(array_filter($rows, fn ($r) => $r['state'] === 'missing')));
        $this->assertSame(['شهادة راتب حديثة', 'موافقة جهة العمل', 'نموذج إقرار وتعهد'], $this->workflow->requiredMissing($app));
        $this->assertFalse($this->workflow->allRequiredUploaded($app));
        foreach (['salary_cert', 'employer_approval', 'undertaking'] as $code) {
            Document::factory()->for($app)->forItem($code)->create();
        }
        $this->assertTrue($this->workflow->allRequiredUploaded($app->fresh()));
        $this->assertSame([], $this->workflow->requiredMissing($app->fresh()));
    }

    public function test_continuation_requires_civil_id_when_expired(): void
    {
        $this->instructor->update(['civil_id_expires_on' => now()->subDay()->toDateString()]);
        $app = $this->workflow->start($this->instructor->fresh(), $this->second);
        $this->assertSame('missing', $this->workflow->checklist($app)['civil_id']['state']);
        $this->assertContains('صورة البطاقة المدنية سارية المفعول', $this->workflow->requiredMissing($app));
    }

    public function test_file_complete_approves_a_continuation_directly(): void
    {
        $app = $this->workflow->start($this->instructor, $this->second);
        foreach (['salary_cert', 'employer_approval', 'undertaking'] as $code) {
            Document::factory()->for($app)->forItem($code)->accepted()->create();
        }
        $app->update(['status' => Application::STATUS_UNDER_REVIEW]);
        $admin = User::factory()->admin()->create();
        $this->workflow->markComplete($app->fresh(), $admin);

        $fresh = $app->fresh();
        $this->assertSame(Application::STATUS_APPROVED, $fresh->status);
        $this->assertNotNull($fresh->decided_at);
        $this->assertNull($fresh->committee_outcome);
        $this->assertSame($this->instructor->approvalFor('2026-2027')->id, $fresh->approval_id);
        $this->assertDatabaseHas('audit_log', ['action' => 'approve_continuation', 'subject_id' => $app->id]);
        Mail::assertSent(ContinuationApproved::class);
        $this->assertTrue($this->workflow->stageTwoComplete($fresh));
    }

    public function test_committee_decision_and_exemptions_refused_on_continuation(): void
    {
        $app = $this->workflow->start($this->instructor, $this->second);
        $app->update(['status' => Application::STATUS_COMPLETE]);
        try {
            $this->workflow->committeeDecision($app->fresh(), User::factory()->admin()->create(), 'approved', '2027-02-01', 'ق/9', null);
            $this->fail('expected refusal');
        } catch (\DomainException $e) {
            $this->assertSame(__('app.review.committee_not_needed'), $e->getMessage());
        }
        $app->update(['status' => Application::STATUS_DRAFT]);
        $this->actingAs($this->user)->post(route('instructor.exemptions.store', [$app, 'transcript_bachelor']), ['reason' => 'x'])->assertForbidden();
    }

    public function test_accepted_exemption_on_an_earlier_application_carries_over_as_exempted(): void
    {
        // A different instructor on $this->first/$this->second (terms are unique per
        // academic_year+type) so the initial application has no transcript_bachelor document at
        // all (an accepted exemption stands in for it), unlike $this->instructor's setUp fixture
        // which already has that item accepted as a document.
        $instructor = Instructor::factory()->for(User::factory()->instructor())->create(['basic_salary' => '900', 'total_salary' => '1200']);
        $initial = Application::factory()->approved()->for($this->first)->for($instructor)->create();
        foreach (['civil_id', 'degree', 'transcript_master', 'salary_cert', 'iban', 'employer_approval', 'undertaking'] as $code) {
            Document::factory()->for($initial)->forItem($code)->accepted()->create();
        }
        $exemption = ChecklistExemption::factory()->for($initial)->forItem('transcript_bachelor')->accepted()->create();
        $approval = CommitteeApproval::factory()->for($instructor)->create(['academic_year' => '2026-2027']);
        $initial->update(['approval_id' => $approval->id]);

        $app = $this->workflow->start($instructor, $this->second);
        $row = $this->workflow->checklist($app)['transcript_bachelor'];

        $this->assertSame(ApplicationWorkflow::STATE_EXEMPTED, $row['state']);
        $this->assertTrue($row['exemption']->is($exemption));
        $this->assertNotContains($exemption->item->label_ar, $this->workflow->requiredMissing($app));
    }

    public function test_approving_a_first_terms_application_converts_an_initial_draft_in_the_second_term(): void
    {
        $instructor = Instructor::factory()->for(User::factory()->instructor())->create();
        $first = Term::factory()->open()->create(['academic_year' => '2027-2028', 'type' => 'first', 'teaching_starts_on' => '2027-09-12', 'teaching_ends_on' => '2027-12-23']);
        $second = Term::factory()->open()->create(['academic_year' => '2027-2028', 'type' => 'second', 'teaching_starts_on' => '2028-02-06', 'teaching_ends_on' => '2028-05-26']);
        $initialApp = Application::factory()->complete()->for($first)->for($instructor)->create();
        $secondDraft = $this->workflow->start($instructor, $second);
        $this->assertSame(Application::KIND_INITIAL, $secondDraft->kind);

        $admin = User::factory()->admin()->create();
        $this->workflow->committeeDecision($initialApp->fresh(), $admin, 'approved', '2027-10-01', 'ق/5', null);

        $secondDraft->refresh();
        $this->assertSame(Application::KIND_CONTINUATION, $secondDraft->kind);
        $this->assertSame($instructor->approvalFor('2027-2028')->id, $secondDraft->approval_id);
        $this->assertSame(Application::STATUS_DRAFT, $secondDraft->status);
    }

    public function test_committee_decision_replaces_a_not_renewed_row_on_reapplication(): void
    {
        $instructor = Instructor::factory()->for(User::factory()->instructor())->create();
        $notRenewed = CommitteeApproval::factory()->for($instructor)->renewal()->notRenewed()->create(['academic_year' => '2027-2028']);
        $term = Term::factory()->open()->create(['academic_year' => '2027-2028', 'type' => 'first', 'teaching_starts_on' => '2027-09-12', 'teaching_ends_on' => '2027-12-23']);
        $app = $this->workflow->start($instructor, $term);
        $this->assertSame(Application::KIND_INITIAL, $app->kind);
        $app->update(['status' => Application::STATUS_COMPLETE]);

        $admin = User::factory()->admin()->create();
        $this->workflow->committeeDecision($app->fresh(), $admin, 'approved', '2027-10-01', 'ق/6', null);

        $this->assertDatabaseMissing('committee_approvals', ['id' => $notRenewed->id]);
        $rows = CommitteeApproval::where('instructor_id', $instructor->id)->where('academic_year', '2027-2028')->get();
        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame(CommitteeApproval::KIND_INITIAL, $row->kind);
        $this->assertSame(CommitteeApproval::OUTCOME_APPROVED, $row->outcome);
        $this->assertSame($row->id, $app->fresh()->approval_id);
    }

    public function test_mark_complete_refuses_a_continuation_without_an_approval(): void
    {
        $app = $this->workflow->start($this->instructor, $this->second);
        foreach (['salary_cert', 'employer_approval', 'undertaking'] as $code) {
            Document::factory()->for($app)->forItem($code)->accepted()->create();
        }
        $app->update(['status' => Application::STATUS_UNDER_REVIEW, 'kind' => Application::KIND_CONTINUATION, 'approval_id' => null]);
        // Contrived edge case (should not occur via normal start()/convertToContinuations() flows):
        // a continuation application with no linked approval and no approved row for the year.
        $this->instructor->approvals()->delete();

        $admin = User::factory()->admin()->create();
        try {
            $this->workflow->markComplete($app->fresh(), $admin);
            $this->fail('expected refusal');
        } catch (\DomainException $e) {
            $this->assertSame(__('app.review.committee_not_needed'), $e->getMessage());
        }
    }

    public function test_reopen_rederives_the_application_kind(): void
    {
        $app = $this->workflow->start($this->instructor, $this->second);
        $app->update(['status' => Application::STATUS_WITHDRAWN, 'decided_at' => now(), 'kind' => Application::KIND_INITIAL, 'approval_id' => null]);
        $admin = User::factory()->admin()->create();

        $this->workflow->reopen($app->fresh(), $admin);

        $fresh = $app->fresh();
        $this->assertSame(Application::STATUS_DRAFT, $fresh->status);
        $this->assertSame(Application::KIND_CONTINUATION, $fresh->kind);
        $this->assertSame($this->instructor->approvalFor('2026-2027')->id, $fresh->approval_id);
    }

    public function test_pages_show_continuation_wording_and_hide_academic_uploads(): void
    {
        $this->actingAs($this->user)->get(route('instructor.home'))->assertOk()->assertSee(__('app.applications.start_continuation'));
        $app = $this->workflow->start($this->instructor, $this->second);
        $r = $this->actingAs($this->user)->get(route('instructor.applications.show', $app))->assertOk();
        $r->assertSee(__('app.applications.continuation_title'))->assertSee(__('app.applications.academic_on_file'))->assertSee(__('app.applications.term_papers'));
        $r->assertDontSee(route('instructor.documents.store', [$app, 'degree']));
        $r->assertSee(route('instructor.documents.store', [$app, 'salary_cert']));
        // iban is on_file (satisfied) and does not renew each term: no "newer copy" control on a continuation.
        $r->assertDontSee(route('instructor.documents.store', [$app, 'iban']));
        $r->assertDontSee(__('app.exemptions.request'));
        $r->assertSee(__('app.applications.still_required'));

        foreach (['salary_cert', 'employer_approval', 'undertaking'] as $code) {
            Document::factory()->for($app)->forItem($code)->create();
        }
        $this->actingAs($this->user)->get(route('instructor.applications.show', $app->fresh()))->assertOk()
            ->assertDontSee(__('app.applications.still_required'));

        $app->update(['status' => Application::STATUS_UNDER_REVIEW]);
        $admin = User::factory()->admin()->create();
        $r = $this->actingAs($admin)->get(route('admin.applications.show', $app))->assertOk();
        $r->assertSee(__('app.applications.kinds.continuation'))->assertDontSee(__('app.review.committee_save'));
    }
}

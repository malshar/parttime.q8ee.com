<?php

namespace Tests\Feature\Admin;

use App\Mail\RenewalApproved;
use App\Mail\RenewalRefused;
use App\Models\Application;
use App\Models\CommitteeApproval;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use App\Services\ApplicationWorkflow;
use App\Services\RenewalService;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RenewalsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Instructor $a;

    private Instructor $b;

    private Instructor $c;

    private Term $nextFirst;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        $this->seed(ChecklistItemSeeder::class);
        $this->admin = User::factory()->admin()->create();
        $old = Term::factory()->create(['academic_year' => '2026-2027', 'type' => 'second', 'teaching_starts_on' => '2027-02-07', 'teaching_ends_on' => '2027-05-27', 'status' => 'closed']);
        $this->a = Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'أحمد المرشح']);
        $this->b = Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'بدر المرشح']);
        $this->c = Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'جابر المرفوض']);
        foreach ([$this->a, $this->b] as $i) {
            CommitteeApproval::factory()->for($i)->create(['academic_year' => '2026-2027']);
            Application::factory()->approved()->for($old)->for($i)->create();
        }
        Application::factory()->for($old)->for($this->c)->create(['status' => Application::STATUS_REJECTED]);
        $this->nextFirst = Term::factory()->open()->create(['academic_year' => '2027-2028', 'type' => 'first', 'teaching_starts_on' => '2027-09-12', 'teaching_ends_on' => '2027-12-23']);
    }

    public function test_candidates_are_previous_year_approved_without_a_target_row(): void
    {
        $names = app(RenewalService::class)->candidates('2027-2028')->pluck('full_name')->all();
        $this->assertSame(['أحمد المرشح', 'بدر المرشح'], $names);
        CommitteeApproval::factory()->for($this->a)->renewal()->create(['academic_year' => '2027-2028']);
        $this->assertSame(['بدر المرشح'], app(RenewalService::class)->candidates('2027-2028')->pluck('full_name')->all());
    }

    public function test_page_lists_candidates_and_requires_admin(): void
    {
        $this->actingAs($this->admin)->get(route('admin.renewals.index', ['year' => '2027-2028']))->assertOk()->assertSee('أحمد المرشح')->assertDontSee('جابر المرفوض');
        $this->actingAs($this->a->user)->get(route('admin.renewals.index'))->assertForbidden();
    }

    public function test_page_defaults_to_the_newest_first_terms_year_when_it_has_candidates(): void
    {
        // setUp's only `first` term is 2027-2028 (open), and it has candidates (a, b) since
        // 2026-2027 is approved for both. The malformed query value must fall back to it,
        // not to the year after the current open term (2028-2029).
        $this->actingAs($this->admin)->get(route('admin.renewals.index', ['year' => 'not-a-year']))
            ->assertOk()->assertSee('2027-2028')->assertSee('أحمد المرشح')->assertDontSee('2028-2029');
    }

    public function test_page_falls_back_to_next_year_when_the_newest_first_term_has_no_candidates(): void
    {
        CommitteeApproval::factory()->for($this->a)->renewal()->create(['academic_year' => '2027-2028']);
        CommitteeApproval::factory()->for($this->b)->renewal()->create(['academic_year' => '2027-2028']);
        // Now 2027-2028 (the newest first term's year) has no candidates left, so the default
        // falls back to the year after the current open term's year: 2028-2029.
        $this->actingAs($this->admin)->get(route('admin.renewals.index', ['year' => 'not-a-year']))
            ->assertOk()->assertSee('2028-2029');
    }

    public function test_recorded_rows_table_shows_initial_approvals_with_the_review_outcome_label(): void
    {
        CommitteeApproval::factory()->for($this->c)->create(['academic_year' => '2027-2028']);
        $this->actingAs($this->admin)->get(route('admin.renewals.index', ['year' => '2027-2028']))
            ->assertOk()->assertSee(__('app.review.approval_kinds.initial'))->assertSee(__('app.review.outcomes.approved'));
    }

    public function test_record_creates_rows_drafts_and_mails(): void
    {
        $this->actingAs($this->admin)->post(route('admin.renewals.store'), [
            'year' => '2027-2028', 'committee_met_on' => '2027-06-15', 'committee_reference' => 'ق/22',
            'rows' => [$this->a->id => ['outcome' => 'renewed'], $this->b->id => ['outcome' => 'not_renewed', 'note' => 'تقييم ضعيف']],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $ra = $this->a->approvalFor('2027-2028');
        $this->assertSame(CommitteeApproval::KIND_RENEWAL, $ra->kind);
        $this->assertTrue($ra->isApproved());
        $this->assertSame('ق/22', $ra->committee_reference);
        $draft = Application::where('instructor_id', $this->a->id)->where('term_id', $this->nextFirst->id)->firstOrFail();
        $this->assertSame(Application::KIND_CONTINUATION, $draft->kind);
        $this->assertSame(Application::STATUS_DRAFT, $draft->status);
        $this->assertSame($ra->id, $draft->approval_id);

        $rb = $this->b->approvalFor('2027-2028');
        $this->assertSame(CommitteeApproval::OUTCOME_NOT_RENEWED, $rb->outcome);
        $this->assertSame('تقييم ضعيف', $rb->note);
        $this->assertDatabaseMissing('applications', ['instructor_id' => $this->b->id, 'term_id' => $this->nextFirst->id]);

        Mail::assertSent(RenewalApproved::class, fn ($m) => $m->hasTo($this->a->user->email));
        Mail::assertSent(RenewalRefused::class, fn ($m) => $m->hasTo($this->b->user->email));
        $this->assertDatabaseHas('audit_log', ['action' => 'renewal_approved', 'subject_id' => $this->a->id, 'details' => '2027-2028']);
        $this->assertDatabaseHas('audit_log', ['action' => 'renewal_refused', 'subject_id' => $this->b->id, 'details' => '2027-2028']);
        $this->assertDatabaseMissing('audit_log', ['details' => 'تقييم ضعيف']);
    }

    public function test_start_reuses_the_renewal_draft(): void
    {
        app(RenewalService::class)->record('2027-2028', [$this->a->id => ['outcome' => 'renewed']], '2027-06-15', 'ق/22', $this->admin);
        $app = app(ApplicationWorkflow::class)->start($this->a, $this->nextFirst);
        $this->assertSame(1, Application::where('instructor_id', $this->a->id)->where('term_id', $this->nextFirst->id)->count());
        $this->assertSame(Application::KIND_CONTINUATION, $app->kind);
    }

    public function test_record_converts_an_existing_initial_draft_to_continuation(): void
    {
        // The instructor already clicked "start" on the new term before the committee's batch ran,
        // so ApplicationWorkflow::start() left a draft with kind=initial (no approval existed yet).
        $existing = app(ApplicationWorkflow::class)->start($this->a, $this->nextFirst);
        $this->assertSame(Application::KIND_INITIAL, $existing->kind);

        app(RenewalService::class)->record('2027-2028', [$this->a->id => ['outcome' => 'renewed']], '2027-06-15', 'ق/22', $this->admin);

        $this->assertSame(1, Application::where('instructor_id', $this->a->id)->where('term_id', $this->nextFirst->id)->count());
        $existing->refresh();
        $this->assertSame(Application::KIND_CONTINUATION, $existing->kind);
        $this->assertSame(Application::STATUS_DRAFT, $existing->status);
        $this->assertSame($this->a->approvalFor('2027-2028')->id, $existing->approval_id);
    }

    public function test_record_converts_non_draft_initial_applications_in_the_target_year(): void
    {
        // A submitted initial application in a later term of the target year: kind and
        // approval_id convert, but its status is left as-is (only a `complete` one moves).
        $secondTerm = Term::factory()->open()->create(['academic_year' => '2027-2028', 'type' => 'second', 'teaching_starts_on' => '2028-02-06', 'teaching_ends_on' => '2028-05-26']);
        $submitted = Application::factory()->submitted()->for($secondTerm)->for($this->a)->create(['kind' => Application::KIND_INITIAL]);
        $summerTerm = Term::factory()->open()->create(['academic_year' => '2027-2028', 'type' => 'summer', 'teaching_starts_on' => '2028-06-19', 'teaching_ends_on' => '2028-08-20']);
        $complete = Application::factory()->complete()->for($summerTerm)->for($this->a)->create(['kind' => Application::KIND_INITIAL]);

        app(RenewalService::class)->record('2027-2028', [$this->a->id => ['outcome' => 'renewed']], '2027-06-15', 'ق/22', $this->admin);

        $ra = $this->a->approvalFor('2027-2028');
        $submitted->refresh();
        $this->assertSame(Application::KIND_CONTINUATION, $submitted->kind);
        $this->assertSame($ra->id, $submitted->approval_id);
        $this->assertSame(Application::STATUS_SUBMITTED, $submitted->status);

        $complete->refresh();
        $this->assertSame(Application::KIND_CONTINUATION, $complete->kind);
        $this->assertSame($ra->id, $complete->approval_id);
        $this->assertSame(Application::STATUS_UNDER_REVIEW, $complete->status);
        $this->assertNull($complete->complete_at);
    }

    public function test_record_refused_when_the_first_term_is_closed(): void
    {
        $this->nextFirst->update(['status' => Term::STATUS_CLOSED]);

        try {
            app(RenewalService::class)->record('2027-2028', [$this->a->id => ['outcome' => 'renewed']], '2027-06-15', 'ق/22', $this->admin);
            $this->fail('expected refusal');
        } catch (\DomainException $e) {
            $this->assertSame(__('app.renewals.first_term_closed', ['year' => '2027-2028']), $e->getMessage());
        }
        $this->assertNull($this->a->approvalFor('2027-2028'));
    }

    public function test_record_refusals(): void
    {
        $post = fn (array $over = []) => $this->actingAs($this->admin)->post(route('admin.renewals.store'), array_merge([
            'year' => '2027-2028', 'committee_met_on' => '2027-06-15', 'committee_reference' => 'ق/22',
            'rows' => [$this->a->id => ['outcome' => 'renewed']],
        ], $over));
        $post(['rows' => []])->assertSessionHasErrors('rows');
        $post(['rows' => [$this->c->id => ['outcome' => 'renewed']]])->assertSessionHasErrors('renewals');
        $this->nextFirst->delete();
        $post()->assertSessionHasErrors('renewals');
        $this->assertDatabaseCount('committee_approvals', 2);
    }

    public function test_double_submit_is_refused_without_partial_writes(): void
    {
        app(RenewalService::class)->record('2027-2028', [$this->a->id => ['outcome' => 'renewed']], '2027-06-15', 'ق/22', $this->admin);
        $this->actingAs($this->admin)->post(route('admin.renewals.store'), [
            'year' => '2027-2028', 'committee_met_on' => '2027-06-15', 'committee_reference' => 'ق/22',
            'rows' => [$this->a->id => ['outcome' => 'renewed'], $this->b->id => ['outcome' => 'renewed']],
        ])->assertSessionHasErrors(['renewals' => __('app.renewals.already_recorded')]);
        $this->assertNull($this->b->approvalFor('2027-2028'));
    }

    public function test_delete_allowed_only_on_renewal_with_draft(): void
    {
        app(RenewalService::class)->record('2027-2028', [$this->a->id => ['outcome' => 'renewed']], '2027-06-15', 'ق/22', $this->admin);
        $row = $this->a->approvalFor('2027-2028');
        $draft = Application::where('instructor_id', $this->a->id)->where('term_id', $this->nextFirst->id)->firstOrFail();
        $path = 'applications/'.$draft->id.'/civil-id.pdf';
        Storage::disk('local')->put($path, 'x');
        Document::factory()->for($draft)->create(['path' => $path]);

        $this->actingAs($this->admin)->delete(route('admin.renewals.destroy', $row))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($this->a->fresh()->approvalFor('2027-2028'));
        $this->assertDatabaseMissing('applications', ['instructor_id' => $this->a->id, 'term_id' => $this->nextFirst->id]);
        $this->assertDatabaseHas('audit_log', ['action' => 'delete_renewal', 'subject_id' => $this->a->id, 'details' => '2027-2028']);
        Storage::disk('local')->assertMissing($path);

        app(RenewalService::class)->record('2027-2028', [$this->a->id => ['outcome' => 'renewed']], '2027-06-15', 'ق/22', $this->admin);
        Application::where('instructor_id', $this->a->id)->where('term_id', $this->nextFirst->id)->update(['status' => Application::STATUS_SUBMITTED]);
        $this->actingAs($this->admin)->delete(route('admin.renewals.destroy', $this->a->approvalFor('2027-2028')))->assertSessionHasErrors('renewals');
        $initial = CommitteeApproval::factory()->for($this->c)->create(['academic_year' => '2026-2027']);
        $this->actingAs($this->admin)->delete(route('admin.renewals.destroy', $initial))->assertSessionHasErrors('renewals');
    }

    public function test_names_list_document_contains_candidates_and_is_audited(): void
    {
        $r = $this->actingAs($this->admin)->get(route('admin.renewals.list', ['year' => '2027-2028']))->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $path = tempnam(sys_get_temp_dir(), 'list').'.docx';
        file_put_contents($path, $r->streamedContent());
        $zip = new \ZipArchive;
        $zip->open($path);
        $text = html_entity_decode(strip_tags($zip->getFromName('word/document.xml')));
        $zip->close();
        @unlink($path);
        $this->assertStringContainsString('2027-2028', $text);
        $this->assertStringContainsString('أحمد المرشح', $text);
        $this->assertStringContainsString($this->a->civil_id, $text);
        $this->assertStringNotContainsString('جابر', $text);
        $this->assertDatabaseHas('audit_log', ['action' => 'export_renewal_list', 'details' => '2027-2028']);
    }
}

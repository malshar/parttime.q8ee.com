<?php

namespace Tests\Feature\Admin;

use App\Exceptions\TermCloseBlockedException;
use App\Models\Application;
use App\Models\Assignment;
use App\Models\Attestation;
use App\Models\AuditLog;
use App\Models\Instructor;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use App\Services\ApplicationWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Js;
use Tests\TestCase;

class TermCloseTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Term $term;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->term = Term::factory()->open()->create();
    }

    private function application(string $status, string $name): Application
    {
        $instructor = Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => $name]);

        return Application::factory()->for($this->term)->for($instructor)->create(['status' => $status]);
    }

    public function test_close_refused_while_an_application_is_unfinished_and_lists_it(): void
    {
        $this->application(Application::STATUS_UNDER_REVIEW, 'سعود فهد');
        $this->application(Application::STATUS_APPROVED, 'ناصر علي');

        $r = $this->actingAs($this->admin)->from(route('admin.terms.index'))->post(route('admin.terms.close', $this->term));

        $r->assertRedirect(route('admin.terms.index'))->assertSessionHasErrors(['close' => __('app.terms.close_blocked')]);
        $this->assertSame(Term::STATUS_OPEN, $this->term->fresh()->status);
        $page = $this->actingAs($this->admin)->get(route('admin.terms.index'));
        $page->assertSee('سعود فهد')->assertSee(__('app.applications.statuses.under_review'));
        $page->assertDontSee('ناصر علي');
        $this->assertDatabaseMissing('audit_log', ['action' => 'close_term']);
    }

    public function test_close_withdraws_drafts_keeps_final_ones_and_audits_the_count(): void
    {
        $draft = $this->application(Application::STATUS_DRAFT, 'أ');
        $draft2 = $this->application(Application::STATUS_DRAFT, 'ب');
        $approved = $this->application(Application::STATUS_APPROVED, 'ج');
        $rejected = $this->application(Application::STATUS_REJECTED, 'د');
        $withdrawn = $this->application(Application::STATUS_WITHDRAWN, 'ه');

        $this->actingAs($this->admin)->post(route('admin.terms.close', $this->term))
            ->assertRedirect()->assertSessionHas('status', __('app.terms.closed_with_drafts', ['n' => 2]));

        $this->assertSame(Term::STATUS_CLOSED, $this->term->fresh()->status);
        $this->assertSame(Application::STATUS_WITHDRAWN, $draft->fresh()->status);
        $this->assertNotNull($draft2->fresh()->decided_at);
        $this->assertSame(Application::STATUS_APPROVED, $approved->fresh()->status);
        $this->assertSame(Application::STATUS_REJECTED, $rejected->fresh()->status);
        $this->assertSame(Application::STATUS_WITHDRAWN, $withdrawn->fresh()->status);
        $this->assertDatabaseHas('audit_log', ['action' => 'close_term', 'subject_id' => $this->term->id, 'details' => 'drafts_withdrawn=2', 'user_id' => $this->admin->id]);
    }

    public function test_close_succeeds_with_unexported_attestation_and_downloads_still_work(): void
    {
        $approved = $this->application(Application::STATUS_APPROVED, 'خالد');
        Assignment::factory()->for($approved)->for(Section::factory()->for($this->term)->withMeetings()->create())->create();
        $a = Attestation::factory()->for($approved)->create(['year' => $this->term->teaching_starts_on->year, 'month' => $this->term->teaching_starts_on->month]);

        $this->actingAs($this->admin)->post(route('admin.terms.close', $this->term))->assertSessionHasNoErrors();

        $this->assertSame(Term::STATUS_CLOSED, $this->term->fresh()->status);
        $r = $this->actingAs($this->admin)->get(route('admin.attestations.download', [$a, 'format' => 'docx']))->assertOk();
        ob_start();
        $r->baseResponse->sendContent();   // deleteFileAfterSend: leave no temp .docx behind
        ob_end_clean();
    }

    public function test_close_on_closed_term_is_forbidden_and_instructor_cannot_close(): void
    {
        $this->term->update(['status' => Term::STATUS_CLOSED]);
        $this->actingAs($this->admin)->post(route('admin.terms.close', $this->term))->assertForbidden();

        $open = Term::factory()->open()->create(['academic_year' => '2027-2028']);
        $this->actingAs(User::factory()->instructor()->create())->post(route('admin.terms.close', $open))->assertForbidden();
        $this->assertSame(Term::STATUS_OPEN, $open->fresh()->status);
    }

    public function test_second_close_is_refused_and_audited_once(): void
    {
        $this->actingAs($this->admin)->post(route('admin.terms.close', $this->term))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('admin.terms.close', $this->term))->assertForbidden();

        $this->assertSame(1, AuditLog::where('action', 'close_term')->count());
    }

    public function test_close_with_a_stale_term_is_refused_under_the_lock(): void
    {
        $stale = Term::find($this->term->id);
        app(ApplicationWorkflow::class)->closeTerm($this->term, $this->admin);

        try {
            app(ApplicationWorkflow::class)->closeTerm($stale, $this->admin);
            $this->fail('A second close of the same term must be refused.');
        } catch (\DomainException $e) {
            $this->assertSame(__('app.applications.term_closed'), $e->getMessage());
        }
        $this->assertSame(1, AuditLog::where('action', 'close_term')->count());
    }

    public function test_close_rechecks_blockers_under_the_lock(): void
    {
        $draft = $this->application(Application::STATUS_DRAFT, 'أ');
        $stale = Term::find($this->term->id);
        $draft->update(['status' => Application::STATUS_SUBMITTED]);

        $this->expectException(TermCloseBlockedException::class);
        try {
            app(ApplicationWorkflow::class)->closeTerm($stale, $this->admin);
        } finally {
            $this->assertSame(Term::STATUS_OPEN, $this->term->fresh()->status);
            $this->assertSame(Application::STATUS_SUBMITTED, $draft->fresh()->status);
        }
    }

    public function test_bare_domain_exception_from_close_is_shown_as_a_message(): void
    {
        $this->mock(ApplicationWorkflow::class)->shouldReceive('closeTerm')->andThrow(new \DomainException(__('app.applications.term_closed')));

        $this->actingAs($this->admin)->post(route('admin.terms.close', $this->term))
            ->assertRedirect(route('admin.terms.index'))
            ->assertSessionHasErrors(['close' => __('app.applications.term_closed')]);
    }

    public function test_terms_page_shows_each_message_once_and_confirms_close(): void
    {
        $this->application(Application::STATUS_UNDER_REVIEW, 'سعود فهد');
        $this->actingAs($this->admin)->post(route('admin.terms.close', $this->term));
        $html = $this->actingAs($this->admin)->get(route('admin.terms.index'))->getContent();
        $this->assertSame(1, substr_count($html, e(__('app.terms.close_blocked'))));
        $this->assertStringContainsString('سعود فهد', $html);

        $page = $this->actingAs($this->admin)->get(route('admin.terms.index'));
        $page->assertSee('onsubmit="return confirm(', false);
        $this->assertStringContainsString('return confirm('.Js::from(__('app.terms.close_confirm'))->toHtml().')', $page->getContent());

        Application::query()->update(['status' => Application::STATUS_APPROVED]);
        $this->actingAs($this->admin)->post(route('admin.terms.close', $this->term));
        $html = $this->actingAs($this->admin)->get(route('admin.terms.index'))->getContent();
        $this->assertSame(1, substr_count($html, e(__('app.terms.closed_with_drafts', ['n' => 0]))));
    }
}

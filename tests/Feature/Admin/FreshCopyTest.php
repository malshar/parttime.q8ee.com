<?php

namespace Tests\Feature\Admin;

use App\Mail\DocumentsRejected;
use App\Models\Application;
use App\Models\ChecklistRenewal;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use App\Services\ApplicationWorkflow;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class FreshCopyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Instructor $instructor;

    private Application $previous;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(ChecklistItemSeeder::class);
        $this->admin = User::factory()->admin()->create();
        $this->instructor = Instructor::factory()->for(User::factory()->instructor())->create();
        $old = Term::factory()->create(['academic_year' => '2025-2026', 'type' => 'second', 'teaching_starts_on' => '2026-01-11', 'teaching_ends_on' => '2026-05-14', 'status' => Term::STATUS_CLOSED]);
        $current = Term::factory()->open()->create(['academic_year' => '2026-2027', 'type' => 'first']);
        $this->previous = Application::factory()->for($old)->for($this->instructor)->create(['status' => Application::STATUS_APPROVED]);
        foreach (['civil_id', 'degree', 'iban'] as $code) {
            Document::factory()->for($this->previous)->forItem($code)->accepted()->create(['reviewed_at' => now()->subMonth()]);
        }
        $this->application = Application::factory()->for($current)->for($this->instructor)->create(['status' => Application::STATUS_UNDER_REVIEW, 'submitted_at' => now(), 'reviewed_at' => now()]);
        foreach (['salary_cert', 'employer_approval', 'undertaking'] as $code) {
            Document::factory()->for($this->application)->forItem($code)->accepted()->create();
        }
    }

    private function url(string $code): string
    {
        return route('admin.applications.renewals.store', [$this->application, $code]);
    }

    public function test_request_creates_row_marks_incomplete_and_audits(): void
    {
        $this->actingAs($this->admin)->post($this->url('degree'), ['reason' => 'الشهادة غير واضحة'])
            ->assertRedirect()->assertSessionHas('status', __('app.review.fresh_copy_requested'));

        $this->assertDatabaseHas('checklist_renewals', ['application_id' => $this->application->id, 'reason' => 'الشهادة غير واضحة', 'requested_by' => $this->admin->id, 'notified_at' => null]);
        $this->assertSame(Application::STATUS_INCOMPLETE, $this->application->fresh()->status);
        $this->assertNull($this->application->fresh()->complete_at);
        $this->assertDatabaseHas('audit_log', ['action' => 'request_fresh_copy', 'subject_id' => $this->application->id, 'details' => 'degree']);
        $this->assertSame('missing', app(ApplicationWorkflow::class)->checklist($this->application->fresh())['degree']['state']);
        Mail::assertNothingSent();
    }

    public function test_request_refused_for_non_on_file_row_closed_term_wrong_status_and_instructor(): void
    {
        $this->actingAs($this->admin)->post($this->url('salary_cert'), ['reason' => 'x'])->assertSessionHasErrors(['renewal' => __('app.review.fresh_copy_wrong_state')]);
        $this->actingAs($this->admin)->post($this->url('degree'), [])->assertSessionHasErrors('reason');

        $this->application->update(['status' => Application::STATUS_APPROVED]);
        $this->actingAs($this->admin)->post($this->url('degree'), ['reason' => 'x'])->assertSessionHasErrors(['renewal' => __('app.review.fresh_copy_wrong_status')]);
        $this->application->update(['status' => Application::STATUS_UNDER_REVIEW]);

        $this->application->term->update(['status' => Term::STATUS_CLOSED]);
        $this->actingAs($this->admin)->post($this->url('degree'), ['reason' => 'x'])->assertSessionHasErrors('renewal');
        $this->application->term->update(['status' => Term::STATUS_OPEN]);

        $this->actingAs($this->instructor->user)->post($this->url('degree'), ['reason' => 'x'])->assertForbidden();
        $this->assertSame(0, ChecklistRenewal::count());
    }

    public function test_request_is_included_in_the_notice_email_once(): void
    {
        $this->actingAs($this->admin)->post($this->url('degree'), ['reason' => 'الشهادة غير واضحة']);
        $wf = app(ApplicationWorkflow::class);
        $this->assertCount(1, $wf->pendingRejectionNotices($this->application->fresh()));

        $this->actingAs($this->admin)->post(route('admin.applications.notify_rejections', $this->application))->assertRedirect();

        Mail::assertSent(DocumentsRejected::class, function (DocumentsRejected $m) {
            $html = $m->render();

            return str_contains($html, 'صورة من المؤهل العلمي') && str_contains($html, 'الشهادة غير واضحة');
        });
        $this->assertNotNull(ChecklistRenewal::first()->notified_at);
        $this->assertCount(0, $wf->pendingRejectionNotices($this->application->fresh()));
    }

    public function test_fresh_upload_after_request_is_accepted_and_application_resubmits(): void
    {
        $this->actingAs($this->admin)->post($this->url('degree'), ['reason' => 'x']);
        $wf = app(ApplicationWorkflow::class);

        $doc = Document::factory()->for($this->application)->forItem('degree')->create();
        $wf->afterUpload($this->application->fresh());
        $this->assertSame(Application::STATUS_SUBMITTED, $this->application->fresh()->status);

        $wf->markUnderReview($this->application->fresh());
        $wf->reviewDocument($doc, $this->admin, Document::STATUS_ACCEPTED, null);

        $row = $wf->checklist($this->application->fresh())['degree'];
        $this->assertSame('accepted', $row['state']);
        $this->assertNotNull($row['renewal']);
        $this->assertTrue($wf->allRequiredAccepted($this->application->fresh()));
    }

    public function test_renewal_error_is_shown_once_on_the_review_page(): void
    {
        $this->actingAs($this->admin)->from(route('admin.applications.show', $this->application))
            ->post($this->url('salary_cert'), ['reason' => 'x']);
        $html = $this->actingAs($this->admin)->get(route('admin.applications.show', $this->application))->getContent();

        $this->assertSame(1, substr_count($html, e(__('app.review.fresh_copy_wrong_state'))));
    }

    public function test_notice_wording_is_neutral_for_a_fresh_copy_request(): void
    {
        $this->actingAs($this->admin)->post($this->url('degree'), ['reason' => 'الشهادة غير واضحة']);
        $this->actingAs($this->admin)->post(route('admin.applications.notify_rejections', $this->application))
            ->assertSessionHas('status', __('app.review.notified', ['count' => 1]));
        $this->assertStringNotContainsString('مرفوض', __('app.review.notified', ['count' => 1]));

        Mail::assertSent(DocumentsRejected::class, function (DocumentsRejected $m) {
            $html = $m->render();

            return str_contains($html, 'تحتاج إلى تصحيح أو تحديث') && ! str_contains($html, 'مرفوض');
        });
        $this->actingAs($this->instructor->user)->get(route('instructor.applications.show', $this->application))
            ->assertOk()->assertSee(__('app.applications.fix_rejected'))->assertDontSee('مرفوضة');
    }
}

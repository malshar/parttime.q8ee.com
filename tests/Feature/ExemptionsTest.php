<?php

namespace Tests\Feature;

use App\Mail\DocumentsRejected;
use App\Models\Application;
use App\Models\ChecklistExemption;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use App\Services\ApplicationWorkflow;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ExemptionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $admin;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(ChecklistItemSeeder::class);
        $this->user = User::factory()->instructor()->create();
        $this->admin = User::factory()->admin()->create();
        $instructor = Instructor::factory()->for($this->user)->create();
        $this->application = Application::factory()->for(Term::factory()->open())->for($instructor)->create();
    }

    private function request(string $code, array $data = ['reason' => 'الجامعة لا تصدر كشوف قديمة'], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->post(route('instructor.exemptions.store', [$this->application, $code]), $data);
    }

    public function test_applicant_requests_an_exemption_and_it_is_audited(): void
    {
        $this->request('transcript_bachelor')->assertRedirect(route('instructor.applications.show', $this->application));
        $this->assertDatabaseHas('checklist_exemptions', ['application_id' => $this->application->id, 'status' => 'pending', 'reason' => 'الجامعة لا تصدر كشوف قديمة']);
        $this->assertDatabaseHas('audit_log', ['action' => 'request_exemption', 'user_id' => $this->user->id, 'details' => 'transcript_bachelor']);
        $this->assertSame(ApplicationWorkflow::STATE_EXEMPTION_REQUESTED, app(ApplicationWorkflow::class)->checklist($this->application)['transcript_bachelor']['state']);
    }

    public function test_reason_is_required_and_capped(): void
    {
        $this->request('transcript_bachelor', ['reason' => ''])->assertSessionHasErrors('reason');
        $this->request('transcript_bachelor', ['reason' => str_repeat('س', 501)])->assertSessionHasErrors('reason');
    }

    public function test_refused_for_non_exemptable_stage_two_submitted_or_stranger(): void
    {
        $this->request('degree')->assertForbidden();
        $this->request('iban')->assertForbidden();
        $this->request('transcript_bachelor', as: User::factory()->instructor()->create())->assertForbidden();
        $this->application->update(['status' => Application::STATUS_SUBMITTED]);
        $this->request('transcript_bachelor')->assertForbidden();
    }

    public function test_refused_when_a_document_is_already_uploaded_or_request_pending(): void
    {
        Document::factory()->for($this->application)->forItem('transcript_bachelor')->create();
        $this->request('transcript_bachelor')->assertSessionHasErrors('exemption');
        $this->application->documents()->delete();
        $this->request('transcript_bachelor')->assertRedirect();
        $this->request('transcript_bachelor')->assertSessionHasErrors('exemption');
    }

    public function test_re_request_after_rejection_resets_the_row(): void
    {
        $e = ChecklistExemption::factory()->for($this->application)->forItem('transcript_bachelor')->rejected()->create(['decided_by' => $this->admin->id, 'notified_at' => now()]);
        $this->application->update(['status' => Application::STATUS_INCOMPLETE]);
        $this->request('transcript_bachelor', ['reason' => 'سبب جديد'])->assertRedirect();
        $e->refresh();
        $this->assertSame('pending', $e->status);
        $this->assertSame('سبب جديد', $e->reason);
        $this->assertNull($e->decided_by);
        $this->assertNull($e->decision_note);
        $this->assertNull($e->notified_at);
    }

    public function test_admin_accepts_and_rejects_with_audit_and_status_change(): void
    {
        $this->application->update(['status' => Application::STATUS_UNDER_REVIEW]);
        $a = ChecklistExemption::factory()->for($this->application)->forItem('transcript_bachelor')->create();
        $b = ChecklistExemption::factory()->for($this->application)->forItem('transcript_master')->create();

        $this->actingAs($this->admin)->post(route('admin.exemptions.decide', $a), ['status' => 'accepted'])->assertRedirect();
        $this->assertSame('accepted', $a->fresh()->status);
        $this->assertSame($this->admin->id, $a->fresh()->decided_by);
        $this->assertDatabaseHas('audit_log', ['action' => 'exemption_accepted', 'details' => 'transcript_bachelor']);
        $this->assertSame(Application::STATUS_UNDER_REVIEW, $this->application->fresh()->status);

        $this->actingAs($this->admin)->post(route('admin.exemptions.decide', $b), ['status' => 'rejected'])->assertSessionHasErrors('decision_note');
        $this->actingAs($this->admin)->post(route('admin.exemptions.decide', $b), ['status' => 'rejected', 'decision_note' => 'اطلبه من الجامعة'])->assertRedirect();
        $this->assertSame('rejected', $b->fresh()->status);
        $this->assertDatabaseHas('audit_log', ['action' => 'exemption_rejected', 'details' => 'transcript_master']);
        $this->assertSame(Application::STATUS_INCOMPLETE, $this->application->fresh()->status);
        $this->assertDatabaseMissing('audit_log', ['details' => 'اطلبه من الجامعة']);
    }

    public function test_decide_refused_for_instructor_non_pending_and_closed_term(): void
    {
        $this->application->update(['status' => Application::STATUS_UNDER_REVIEW]);
        $e = ChecklistExemption::factory()->for($this->application)->forItem('transcript_bachelor')->accepted()->create();
        $this->actingAs($this->user)->post(route('admin.exemptions.decide', $e), ['status' => 'accepted'])->assertForbidden();
        $this->actingAs($this->admin)->post(route('admin.exemptions.decide', $e), ['status' => 'accepted'])->assertSessionHasErrors('exemption');
        $p = ChecklistExemption::factory()->for($this->application)->forItem('transcript_master')->create();
        $this->application->term->update(['status' => 'closed']);
        $this->actingAs($this->admin)->post(route('admin.exemptions.decide', $p), ['status' => 'accepted'])->assertSessionHasErrors('exemption');
    }

    public function test_rejected_exemption_is_in_the_consolidated_notice_once(): void
    {
        $this->application->update(['status' => Application::STATUS_INCOMPLETE]);
        ChecklistExemption::factory()->for($this->application)->forItem('transcript_bachelor')->rejected('اطلبه من الجامعة')->create();
        $workflow = app(ApplicationWorkflow::class);
        $this->assertCount(1, $workflow->pendingRejectionNotices($this->application));

        $workflow->notifyRejections($this->application, $this->admin);
        Mail::assertSent(DocumentsRejected::class, fn ($m) => str_contains($m->render(), 'اطلبه من الجامعة') && str_contains($m->render(), 'كشف درجات البكالوريوس'));
        $this->assertSame([], $workflow->pendingRejectionNotices($this->application->fresh()));
    }
}

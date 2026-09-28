<?php

namespace Tests\Feature\Admin;

use App\Mail\ApplicationApproved;
use App\Mail\ApplicationRejected;
use App\Mail\DocumentsRejected;
use App\Models\Application;
use App\Models\AuditLog;
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
        foreach (['civil_id', 'degree', 'salary_cert', 'iban', 'employer_approval', 'undertaking'] as $code) {
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

    public function test_rejecting_a_document_marks_incomplete_and_mails_instructor(): void
    {
        $doc = $this->application->latestDocuments()->get('iban');

        $this->actingAs($this->admin)->post(route('admin.documents.review', $doc), ['status' => 'rejected', 'reason' => 'غير واضح'])
            ->assertRedirect();

        $this->assertSame('rejected', $doc->fresh()->status);
        $this->assertSame('غير واضح', $doc->fresh()->rejection_reason);
        $this->assertSame(Application::STATUS_INCOMPLETE, $this->application->fresh()->status);
        Mail::assertSent(DocumentsRejected::class, fn ($m) => $m->hasTo($this->instructorUser->email));
    }

    public function test_reject_requires_reason(): void
    {
        $doc = $this->application->latestDocuments()->get('iban');
        $this->actingAs($this->admin)->post(route('admin.documents.review', $doc), ['status' => 'rejected'])->assertSessionHasErrors('reason');
    }

    public function test_approve_blocked_until_latest_versions_all_accepted(): void
    {
        $this->acceptAll();
        $iban = $this->application->latestDocuments()->get('iban');
        $this->actingAs($this->admin)->post(route('admin.documents.review', $iban), ['status' => 'rejected', 'reason' => 'x']);

        $this->actingAs($this->admin)->post(route('admin.applications.approve', $this->application))->assertSessionHasErrors('approve');

        // Applicant re-uploads (v2, pending) → still blocked; admin accepts v2 → approve works even though v1 stays rejected.
        $v2 = Document::factory()->for($this->application)->forItem('iban')->create(['version' => 2]);
        $this->actingAs($this->admin)->post(route('admin.applications.approve', $this->application))->assertSessionHasErrors('approve');
        $this->actingAs($this->admin)->post(route('admin.documents.review', $v2), ['status' => 'accepted']);

        $this->actingAs($this->admin)->post(route('admin.applications.approve', $this->application), [
            'assignment_decision_number' => '123/2026', 'assignment_decision_date' => '2026-09-20',
        ])->assertRedirect();

        $fresh = $this->application->fresh();
        $this->assertSame(Application::STATUS_APPROVED, $fresh->status);
        $this->assertSame('123/2026', $fresh->assignment_decision_number);
        Mail::assertSent(ApplicationApproved::class, fn ($m) => $m->hasTo($this->instructorUser->email));
    }

    public function test_approve_blocked_on_closed_term(): void
    {
        $this->acceptAll();
        $this->application->term->update(['status' => 'closed']);

        $this->actingAs($this->admin)->post(route('admin.applications.approve', $this->application))->assertSessionHasErrors('approve');
        $this->assertNotSame(Application::STATUS_APPROVED, $this->application->fresh()->status);
    }

    public function test_reject_application_with_reason_mails_instructor(): void
    {
        $this->actingAs($this->admin)->post(route('admin.applications.reject', $this->application), ['reason' => 'لا يستوفي الشروط'])->assertRedirect();

        $this->assertSame(Application::STATUS_REJECTED, $this->application->fresh()->status);
        Mail::assertSent(ApplicationRejected::class);
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
}

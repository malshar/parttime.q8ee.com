<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CompleteStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(ChecklistItemSeeder::class);
        $this->admin = User::factory()->admin()->create();
        $instructor = Instructor::factory()->for(User::factory()->instructor())->create();
        $this->application = Application::factory()->for(Term::factory()->open())->for($instructor)
            ->create(['status' => Application::STATUS_UNDER_REVIEW]);
        foreach (['civil_id', 'degree', 'transcript_bachelor', 'transcript_master', 'salary_cert', 'iban', 'employer_approval', 'undertaking'] as $code) {
            Document::factory()->for($this->application)->forItem($code)->accepted()->create();
        }
    }

    public function test_mark_complete_when_all_required_accepted(): void
    {
        $this->actingAs($this->admin)->post(route('admin.applications.complete', $this->application))->assertRedirect();

        $fresh = $this->application->fresh();
        $this->assertSame(Application::STATUS_COMPLETE, $fresh->status);
        $this->assertNotNull($fresh->complete_at);
        $this->assertDatabaseHas('audit_log', ['action' => 'mark_complete', 'subject_id' => $this->application->id]);
    }

    public function test_mark_complete_refused_when_a_document_is_pending(): void
    {
        Document::factory()->for($this->application)->forItem('iban')->create(['version' => 2]); // pending v2

        $this->actingAs($this->admin)->post(route('admin.applications.complete', $this->application))
            ->assertSessionHasErrors('complete');
        $this->assertSame(Application::STATUS_UNDER_REVIEW, $this->application->fresh()->status);
    }

    public function test_mark_complete_refused_on_closed_term_and_on_wrong_status(): void
    {
        $this->application->term->update(['status' => 'closed']);
        $this->actingAs($this->admin)->post(route('admin.applications.complete', $this->application))->assertSessionHasErrors('complete');

        $this->application->term->update(['status' => 'open']);
        $this->application->update(['status' => Application::STATUS_DRAFT]);
        $this->actingAs($this->admin)->post(route('admin.applications.complete', $this->application))->assertSessionHasErrors('complete');
    }

    public function test_rejecting_a_document_from_complete_returns_to_incomplete(): void
    {
        $this->application->update(['status' => Application::STATUS_COMPLETE, 'complete_at' => now()]);
        $doc = $this->application->latestDocuments()->get('iban');

        $this->actingAs($this->admin)->post(route('admin.documents.review', $doc), ['status' => 'rejected', 'reason' => 'x'])->assertRedirect();

        $fresh = $this->application->fresh();
        $this->assertSame(Application::STATUS_INCOMPLETE, $fresh->status);
        $this->assertNull($fresh->complete_at);
    }

    public function test_dashboard_shows_three_groups(): void
    {
        $this->application->update(['status' => Application::STATUS_COMPLETE, 'complete_at' => now()->subDays(3)]);
        Application::factory()->submitted()->for($this->application->term)->create();

        $r = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();
        $r->assertSee(__('app.review.group_department'));
        $r->assertSee(__('app.review.group_committee'));
        $r->assertSee(__('app.review.group_alerts'));
        $r->assertSee(__('app.review.waiting_days', ['days' => 3]));
    }

    public function test_complete_is_not_editable_by_instructor_and_upload_is_refused(): void
    {
        $this->application->update(['status' => Application::STATUS_COMPLETE, 'complete_at' => now()]);
        $this->assertFalse($this->application->fresh()->isEditable());
        $this->assertFalse($this->application->fresh()->isFinal());
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Mail\ApplicationApproved;
use App\Mail\ApplicationRejected;
use App\Models\Application;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CommitteeDecisionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $instructorUser;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(ChecklistItemSeeder::class);
        $this->admin = User::factory()->admin()->create();
        $this->instructorUser = User::factory()->instructor()->create();
        $instructor = Instructor::factory()->for($this->instructorUser)->create();
        $this->application = Application::factory()->complete()->for(Term::factory()->open())->for($instructor)->create();
    }

    private function payload(array $o = []): array
    {
        return array_merge(['outcome' => 'approved', 'committee_met_on' => now()->toDateString(), 'committee_reference' => 'ل.ت 12/2026', 'committee_note' => ''], $o);
    }

    public function test_approved_outcome_sets_status_and_mails(): void
    {
        $this->actingAs($this->admin)->post(route('admin.applications.committee', $this->application), $this->payload())->assertRedirect();

        $f = $this->application->fresh();
        $this->assertSame(Application::STATUS_APPROVED, $f->status);
        $this->assertSame('approved', $f->committee_outcome);
        $this->assertSame('ل.ت 12/2026', $f->committee_reference);
        $this->assertNotNull($f->decided_at);
        $this->assertDatabaseHas('audit_log', ['action' => 'committee_decision', 'subject_id' => $f->id]);
        Mail::assertSent(ApplicationApproved::class, fn ($m) => $m->hasTo($this->instructorUser->email));
    }

    public function test_rejected_outcome_requires_note_and_mails(): void
    {
        $this->actingAs($this->admin)->post(route('admin.applications.committee', $this->application), $this->payload(['outcome' => 'rejected']))
            ->assertSessionHasErrors('committee_note');

        $this->actingAs($this->admin)->post(route('admin.applications.committee', $this->application), $this->payload(['outcome' => 'rejected', 'committee_note' => 'لا يستوفي الشروط']))
            ->assertRedirect();

        $f = $this->application->fresh();
        $this->assertSame(Application::STATUS_REJECTED, $f->status);
        $this->assertSame('لا يستوفي الشروط', $f->rejection_reason);
        Mail::assertSent(ApplicationRejected::class);
    }

    public function test_refused_unless_complete(): void
    {
        $this->application->update(['status' => Application::STATUS_UNDER_REVIEW]);
        $this->actingAs($this->admin)->post(route('admin.applications.committee', $this->application), $this->payload())->assertSessionHasErrors('committee');
        $this->assertSame(Application::STATUS_UNDER_REVIEW, $this->application->fresh()->status);
    }

    public function test_second_submission_is_refused_and_sends_no_second_mail(): void
    {
        $this->actingAs($this->admin)->post(route('admin.applications.committee', $this->application), $this->payload());
        $this->actingAs($this->admin)->post(route('admin.applications.committee', $this->application), $this->payload(['outcome' => 'rejected', 'committee_note' => 'x']))
            ->assertSessionHasErrors('committee');

        $this->assertSame(Application::STATUS_APPROVED, $this->application->fresh()->status);
        Mail::assertSent(ApplicationApproved::class, 1);
        Mail::assertNotSent(ApplicationRejected::class);
    }

    public function test_refused_on_closed_term_and_future_meeting_date(): void
    {
        $this->actingAs($this->admin)->post(route('admin.applications.committee', $this->application), $this->payload(['committee_met_on' => now()->addDay()->toDateString()]))
            ->assertSessionHasErrors('committee_met_on');

        $this->application->term->update(['status' => 'closed']);
        $this->actingAs($this->admin)->post(route('admin.applications.committee', $this->application), $this->payload())->assertSessionHasErrors('committee');
    }

    public function test_old_approve_and_reject_routes_are_gone(): void
    {
        $this->assertFalse(Route::has('admin.applications.approve'));
        $this->assertFalse(Route::has('admin.applications.reject'));
    }

    public function test_outcome_is_preserved_after_validation_failure(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.applications.show', $this->application))
            ->post(route('admin.applications.committee', $this->application), $this->payload(['outcome' => 'rejected']))
            ->assertSessionHasErrors('committee_note')
            ->assertRedirect(route('admin.applications.show', $this->application));

        $this->actingAs($this->admin)->get(route('admin.applications.show', $this->application))
            ->assertSee('<option value="rejected" selected', false)
            ->assertDontSee('<option value="approved" selected', false);
    }
}

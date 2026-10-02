<?php

namespace Tests\Feature;

use App\Mail\ApplicationApproved;
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

class StagePagesTest extends TestCase
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
        $this->application = Application::factory()->for(Term::factory()->open())->for(Instructor::factory()->for($this->user)->state(['basic_salary' => null, 'total_salary' => null]))->create();
    }

    public function test_instructor_page_has_two_sections_and_exemption_button_before_approval(): void
    {
        $r = $this->actingAs($this->user)->get(route('instructor.applications.show', $this->application))->assertOk();
        $r->assertSeeInOrder([__('app.applications.stage1_title'), 'كشف درجات البكالوريوس', __('app.applications.stage2_title'), 'شهادة راتب حديثة']);
        $r->assertSee(__('app.exemptions.request'));
        $r->assertSee(__('app.documents.stage2_hint'));
        $r->assertSee(__('app.documents.employer_letter_hint'));
        $r->assertDontSee(route('instructor.documents.store', [$this->application, 'iban']));
        $r->assertDontSee(route('instructor.salary.update'));
    }

    public function test_instructor_page_after_approval_shows_stage_two_uploads_salary_form_and_missing_list(): void
    {
        $this->application->update(['status' => Application::STATUS_APPROVED]);
        $r = $this->actingAs($this->user)->get(route('instructor.applications.show', $this->application))->assertOk();
        $r->assertSee(route('instructor.documents.store', [$this->application, 'iban']));
        $r->assertDontSee(route('instructor.documents.store', [$this->application, 'degree']));
        $r->assertSee(route('instructor.salary.update'));
        $r->assertDontSee(__('app.exemptions.request'));
        $r->assertSee(__('app.applications.stage2_pending'));
    }

    public function test_instructor_page_states_stage_two_complete(): void
    {
        $this->application->update(['status' => Application::STATUS_APPROVED]);
        $this->application->instructor->update(['basic_salary' => '900', 'total_salary' => '1200']);
        foreach (['salary_cert', 'iban', 'employer_approval', 'undertaking'] as $code) {
            Document::factory()->for($this->application)->forItem($code)->accepted()->create();
        }
        $this->actingAs($this->user)->get(route('instructor.applications.show', $this->application))->assertOk()
            ->assertSee(__('app.applications.stage2_complete'))->assertDontSee(route('instructor.salary.update'));
    }

    public function test_admin_page_shows_exemption_forms_badges_and_decision_messages(): void
    {
        $this->application->update(['status' => Application::STATUS_UNDER_REVIEW]);
        foreach (['civil_id', 'degree', 'transcript_master'] as $code) {
            Document::factory()->for($this->application)->forItem($code)->accepted()->create();
        }
        $e = ChecklistExemption::factory()->for($this->application)->forItem('transcript_bachelor')->create(['reason' => 'سبب الطالب']);
        $r = $this->actingAs($this->admin)->get(route('admin.applications.show', $this->application))->assertOk();
        $r->assertSee('سبب الطالب')->assertSee(route('admin.exemptions.decide', $e))->assertSee(__('app.exemptions.accept'));
        $r->assertSee(__('app.review.complete_blocked_exemptions'));
        $r->assertSee(__('app.applications.stage2_badge'));
    }

    public function test_admin_page_after_approval_shows_stage_two_state(): void
    {
        $this->application->update(['status' => Application::STATUS_APPROVED]);
        $this->actingAs($this->admin)->get(route('admin.applications.show', $this->application))->assertOk()
            ->assertSee(__('app.review.stage2_missing'))->assertSee(__('app.profile.salary_missing'));
    }

    public function test_approval_mail_lists_stage_two_items(): void
    {
        $this->application->update(['status' => Application::STATUS_COMPLETE]);
        app(ApplicationWorkflow::class)->committeeDecision($this->application, $this->admin, 'approved', '2026-10-01', 'ق/1', null);
        Mail::assertSent(ApplicationApproved::class, fn ($m) => str_contains($r = $m->render(), 'شهادة راتب حديثة') && str_contains($r, __('app.mail.approved_stage2_intro', [], 'ar')) && str_contains($r, __('app.documents.employer_letter_hint', [], 'ar')));
    }
}

<?php

namespace Tests\Feature\Instructor;

use App\Mail\ApplicationSubmitted;
use App\Models\Application;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use App\Services\ApplicationWorkflow;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SubmitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['mail.admin_notify' => 'admin@example.com']);
        $this->seed(ChecklistItemSeeder::class);
        $this->user = User::factory()->instructor()->create();
        $instructor = Instructor::factory()->for($this->user)->create(); // local, government, master → 6 required
        $this->application = Application::factory()->for(Term::factory()->open())->for($instructor)->create();
    }

    private function uploadAll(array $except = []): void
    {
        foreach (['civil_id', 'degree', 'transcript_bachelor', 'transcript_master', 'salary_cert', 'iban', 'employer_approval', 'undertaking'] as $code) {
            if (! in_array($code, $except, true)) {
                Document::factory()->for($this->application)->forItem($code)->create();
            }
        }
    }

    public function test_submit_blocked_until_all_required_uploaded(): void
    {
        // iban is a stage-2 item (5b): submission now reads stage-1 rows only, so the missing
        // item must be a stage-1 one (civil_id) to still block submission.
        $this->uploadAll(except: ['civil_id']);

        $this->actingAs($this->user)->post(route('instructor.applications.submit', $this->application))
            ->assertSessionHasErrors('submit');
        $this->assertSame(Application::STATUS_DRAFT, $this->application->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_submit_sets_status_and_mails_admin(): void
    {
        $this->uploadAll();

        $this->actingAs($this->user)->post(route('instructor.applications.submit', $this->application))
            ->assertRedirect(route('instructor.applications.show', $this->application));

        $fresh = $this->application->fresh();
        $this->assertSame(Application::STATUS_SUBMITTED, $fresh->status);
        $this->assertNotNull($fresh->submitted_at);
        Mail::assertSent(ApplicationSubmitted::class, fn ($m) => $m->hasTo('admin@example.com'));
    }

    public function test_reupload_of_rejected_item_returns_incomplete_to_submitted(): void
    {
        $this->uploadAll(except: ['iban']);
        Document::factory()->for($this->application)->forItem('iban')->rejected()->create();
        $this->application->update(['status' => Application::STATUS_INCOMPLETE]);

        Document::factory()->for($this->application)->forItem('iban')->create(['version' => 2]);
        app(ApplicationWorkflow::class)->afterUpload($this->application->fresh());

        $this->assertSame(Application::STATUS_SUBMITTED, $this->application->fresh()->status);
    }

    public function test_submit_logs_warning_when_admin_notify_unset(): void
    {
        config(['mail.admin_notify' => null]);
        $this->uploadAll();
        Log::spy();

        $this->actingAs($this->user)->post(route('instructor.applications.submit', $this->application))
            ->assertRedirect(route('instructor.applications.show', $this->application));

        $this->assertSame(Application::STATUS_SUBMITTED, $this->application->fresh()->status);
        Log::shouldHaveReceived('warning')->once()
            ->with('ADMIN_NOTIFY_EMAIL is not set; admin was not notified of application submission', ['application_id' => $this->application->id]);
        Mail::assertNothingSent();
    }

    public function test_withdraw_from_draft_or_submitted_only(): void
    {
        $this->actingAs($this->user)->post(route('instructor.applications.withdraw', $this->application))->assertRedirect();
        $this->assertSame(Application::STATUS_WITHDRAWN, $this->application->fresh()->status);

        $this->application->update(['status' => Application::STATUS_APPROVED]);
        $this->actingAs($this->user)->post(route('instructor.applications.withdraw', $this->application))->assertForbidden();
    }

    public function test_submit_survives_mail_failure(): void
    {
        $this->uploadAll();
        Exceptions::fake();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('smtp down'));

        $this->actingAs($this->user)->post(route('instructor.applications.submit', $this->application))
            ->assertRedirect(route('instructor.applications.show', $this->application));

        $this->assertSame(Application::STATUS_SUBMITTED, $this->application->fresh()->status);
        Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'smtp down');
    }
}

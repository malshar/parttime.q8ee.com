<?php

namespace Tests\Feature\Admin;

use App\Mail\DocumentsRejected;
use App\Models\Application;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RejectionNotifyTest extends TestCase
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
        $this->application = Application::factory()->for(Term::factory()->open())->for($instructor)->create(['status' => Application::STATUS_UNDER_REVIEW]);
        foreach (['civil_id', 'degree', 'transcript_bachelor', 'transcript_master', 'salary_cert', 'iban', 'employer_approval', 'undertaking'] as $code) {
            Document::factory()->for($this->application)->forItem($code)->create();
        }
    }

    private function reject(string $code, string $reason = 'غير واضح'): void
    {
        $doc = $this->application->latestDocuments()->get($code);
        $this->actingAs($this->admin)->post(route('admin.documents.review', $doc), ['status' => 'rejected', 'reason' => $reason]);
    }

    public function test_rejecting_documents_sends_nothing_until_notify(): void
    {
        $this->reject('iban');
        $this->reject('degree');

        Mail::assertNothingSent();
        $this->assertSame(Application::STATUS_INCOMPLETE, $this->application->fresh()->status);

        $this->actingAs($this->admin)->post(route('admin.applications.notify_rejections', $this->application))->assertRedirect();

        Mail::assertSent(DocumentsRejected::class, 1);
        Mail::assertSent(DocumentsRejected::class, fn ($m) => $m->hasTo($this->instructorUser->email) && count($m->rows) === 2);
        $this->assertSame(2, Document::where('status', 'rejected')->whereNotNull('notified_at')->count());
        $this->assertDatabaseHas('audit_log', ['action' => 'notify_rejections', 'subject_id' => $this->application->id]);
    }

    public function test_notify_refused_when_nothing_pending(): void
    {
        $this->actingAs($this->admin)->post(route('admin.applications.notify_rejections', $this->application))->assertSessionHasErrors('notify');
        Mail::assertNothingSent();
    }

    public function test_show_offers_button_only_when_pending(): void
    {
        $this->actingAs($this->admin)->get(route('admin.applications.show', $this->application))->assertDontSee(__('app.review.notify_rejections'));
        $this->reject('iban');
        $this->actingAs($this->admin)->get(route('admin.applications.show', $this->application))->assertSee(__('app.review.notify_rejections'));
        $this->actingAs($this->admin)->post(route('admin.applications.notify_rejections', $this->application));
        $this->actingAs($this->admin)->get(route('admin.applications.show', $this->application))->assertDontSee(__('app.review.notify_rejections'));
    }

    public function test_reupload_after_notice_makes_new_rejection_pending_again(): void
    {
        $this->reject('iban');
        $this->actingAs($this->admin)->post(route('admin.applications.notify_rejections', $this->application));
        Document::factory()->for($this->application)->forItem('iban')->create(['version' => 2]);
        $this->reject('iban', 'ما زال غير واضح');

        $this->actingAs($this->admin)->post(route('admin.applications.notify_rejections', $this->application))->assertRedirect();
        Mail::assertSent(DocumentsRejected::class, 2);
    }
}

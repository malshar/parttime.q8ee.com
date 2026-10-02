<?php

namespace Tests\Feature;

use App\Mail\DocumentsRejected;
use App\Models\Application;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use App\Services\ApplicationWorkflow;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApprovedPhaseTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $admin;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        $this->seed(ChecklistItemSeeder::class);
        $this->user = User::factory()->instructor()->create();
        $this->admin = User::factory()->admin()->create();
        $instructor = Instructor::factory()->for($this->user)->create();
        $this->application = Application::factory()->approved()->for(Term::factory()->open())->for($instructor)->create();
        foreach (['civil_id', 'degree', 'transcript_bachelor', 'transcript_master'] as $code) {
            Document::factory()->for($this->application)->forItem($code)->accepted()->create();
        }
    }

    private function upload(string $code)
    {
        return $this->actingAs($this->user)->post(route('instructor.documents.store', [$this->application, $code]), ['file' => UploadedFile::fake()->create('f.pdf', 10, 'application/pdf')]);
    }

    public function test_stage_two_upload_allowed_stage_one_refused_after_approval(): void
    {
        $this->upload('iban')->assertRedirect();
        $this->assertSame('pending', app(ApplicationWorkflow::class)->checklist($this->application)['iban']['state']);
        $this->upload('degree')->assertForbidden();
        $this->assertSame(Application::STATUS_APPROVED, $this->application->fresh()->status);
    }

    public function test_stage_two_upload_refused_on_closed_term(): void
    {
        $this->application->term->update(['status' => 'closed']);
        $this->upload('iban')->assertForbidden();
    }

    public function test_admin_reviews_stage_two_after_approval_without_status_change(): void
    {
        $doc = Document::factory()->for($this->application)->forItem('iban')->create();
        $this->actingAs($this->admin)->post(route('admin.documents.review', $doc), ['status' => 'accepted'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('accepted', $doc->fresh()->status);

        $doc2 = Document::factory()->for($this->application)->forItem('undertaking')->create();
        $this->actingAs($this->admin)->post(route('admin.documents.review', $doc2), ['status' => 'rejected', 'reason' => 'غير موقع'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('rejected', $doc2->fresh()->status);
        $this->assertSame(Application::STATUS_APPROVED, $this->application->fresh()->status, 'a stage-2 rejection never changes the status');
    }

    public function test_stage_one_review_refused_after_approval(): void
    {
        $doc = $this->application->latestDocuments()->get('degree');
        $this->actingAs($this->admin)->post(route('admin.documents.review', $doc), ['status' => 'rejected', 'reason' => 'x'])->assertSessionHasErrors('review');
        $this->assertSame('accepted', $doc->fresh()->status);
    }

    public function test_rejection_notice_works_while_approved(): void
    {
        Document::factory()->for($this->application)->forItem('undertaking')->rejected('غير موقع')->create();
        $this->actingAs($this->admin)->post(route('admin.applications.notify_rejections', $this->application))->assertRedirect()->assertSessionHasNoErrors();
        Mail::assertSent(DocumentsRejected::class);
        $this->assertSame(Application::STATUS_APPROVED, $this->application->fresh()->status);
    }

    public function test_fresh_copy_on_stage_two_on_file_row_keeps_approved_status(): void
    {
        $earlier = Application::factory()->for(Term::factory()->create(['teaching_starts_on' => now()->subYear(), 'teaching_ends_on' => now()->subMonths(8), 'status' => 'closed', 'academic_year' => '2025-2026']))
            ->for($this->application->instructor)->create(['status' => Application::STATUS_APPROVED]);
        Document::factory()->for($earlier)->forItem('iban')->accepted()->create();
        $this->assertSame(ApplicationWorkflow::STATE_ON_FILE, app(ApplicationWorkflow::class)->checklist($this->application)['iban']['state']);

        $this->actingAs($this->admin)->post(route('admin.applications.renewals.store', [$this->application, 'iban']), ['reason' => 'تغير البنك'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(Application::STATUS_APPROVED, $this->application->fresh()->status);
        $this->assertSame('missing', app(ApplicationWorkflow::class)->checklist($this->application->fresh())['iban']['state']);
    }
}

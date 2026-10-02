<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use App\Services\ApplicationWorkflow;
use App\Services\ChecklistDocument;
use App\Services\ChecklistResolver;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Optional checklist items (transcripts, 2026-10-02): uploadable, shown with a badge, never blocking, never printed. */
class OptionalItemsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        $this->seed(ChecklistItemSeeder::class);
        $this->user = User::factory()->instructor()->create();
        $instructor = Instructor::factory()->for($this->user)->create(); // local, government, master
        $this->application = Application::factory()->for(Term::factory()->open())->for($instructor)->create();
    }

    private function uploadRequired(): void
    {
        foreach (['civil_id', 'degree', 'salary_cert', 'iban', 'employer_approval', 'undertaking'] as $code) {
            Document::factory()->for($this->application)->forItem($code)->accepted()->create();
        }
    }

    public function test_seeder_adds_two_optional_transcript_items_after_the_degree(): void
    {
        $this->assertDatabaseCount('checklist_items', 14);
        $this->assertDatabaseHas('checklist_items', ['code' => 'transcript_bachelor', 'optional' => true, 'condition' => 'always', 'provided_by' => 'applicant']);
        $this->assertDatabaseHas('checklist_items', ['code' => 'transcript_master', 'optional' => true, 'condition' => 'master_or_above', 'provided_by' => 'applicant']);
        $this->assertDatabaseHas('checklist_items', ['code' => 'degree', 'sort_order' => 5]);
        $this->assertDatabaseHas('checklist_items', ['code' => 'transcript_bachelor', 'sort_order' => 6]);
        $this->assertDatabaseHas('checklist_items', ['code' => 'equivalency', 'sort_order' => 8]);
    }

    public function test_plan_groups_optional_items_separately_and_master_transcript_follows_the_degree(): void
    {
        $plan = app(ChecklistResolver::class)->for($this->application->instructor);
        $this->assertSame(['civil_id', 'degree', 'salary_cert', 'iban', 'employer_approval', 'undertaking'], $plan->required->pluck('code')->all());
        $this->assertSame(['transcript_bachelor', 'transcript_master'], $plan->optional->pluck('code')->all());
        $this->assertTrue($plan->isUploadable('transcript_master'));
        $this->assertFalse($plan->isRequired('transcript_master'));

        $bachelor = Instructor::factory()->bachelor()->for(User::factory()->instructor())->create();
        $plan = app(ChecklistResolver::class)->for($bachelor);
        $this->assertSame(['transcript_bachelor'], $plan->optional->pluck('code')->all());
        $this->assertContains('transcript_master', $plan->notApplicable->pluck('code')->all());
    }

    public function test_checklist_rows_flag_optional_items_and_instructor_can_upload_them(): void
    {
        $rows = app(ApplicationWorkflow::class)->checklist($this->application);
        $this->assertFalse($rows['degree']['optional']);
        $this->assertTrue($rows['transcript_bachelor']['optional']);
        $this->assertSame('missing', $rows['transcript_bachelor']['state']);

        $this->actingAs($this->user)
            ->post(route('instructor.documents.store', [$this->application, 'transcript_bachelor']), ['file' => UploadedFile::fake()->create('t.pdf', 10, 'application/pdf')])
            ->assertRedirect(route('instructor.applications.show', $this->application));
        $this->assertSame('pending', app(ApplicationWorkflow::class)->checklist($this->application)['transcript_bachelor']['state']);
    }

    public function test_missing_or_pending_optional_items_never_block_submission_or_completion(): void
    {
        $this->uploadRequired();
        Document::factory()->for($this->application)->forItem('transcript_master')->create(); // pending, never reviewed
        $workflow = app(ApplicationWorkflow::class);

        $this->assertTrue($workflow->allRequiredUploaded($this->application));
        $this->assertTrue($workflow->allRequiredAccepted($this->application));

        $workflow->submit($this->application);
        $workflow->markUnderReview($this->application->refresh());
        $workflow->markComplete($this->application->refresh(), User::factory()->admin()->create());
        $this->assertSame(Application::STATUS_COMPLETE, $this->application->refresh()->status);
    }

    public function test_pages_show_the_optional_badge_and_the_printed_check_list_omits_optional_items(): void
    {
        $this->actingAs($this->user)->get(route('instructor.applications.show', $this->application))
            ->assertOk()->assertSee('كشف درجات البكالوريوس')->assertSee(__('app.documents.optional'));

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('admin.applications.show', $this->application))
            ->assertOk()->assertSee('كشف درجات الماجستير')->assertSee(__('app.documents.optional'));

        $path = app(ChecklistDocument::class)->build($this->application, $admin);
        $zip = new \ZipArchive;
        $zip->open($path);
        $text = html_entity_decode(strip_tags(str_replace('</w:p>', "\n", $zip->getFromName('word/document.xml'))));
        $zip->close();
        @unlink($path);
        $this->assertStringContainsString('صورة من المؤهل العلمي', $text);
        $this->assertStringNotContainsString('كشف درجات', $text);
    }
}

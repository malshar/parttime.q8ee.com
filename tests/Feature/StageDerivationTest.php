<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ChecklistExemption;
use App\Models\ChecklistItem;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use App\Services\ApplicationWorkflow;
use App\Services\ChecklistDocument;
use App\Services\ChecklistResolver;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StageDerivationTest extends TestCase
{
    use RefreshDatabase;

    private Application $application;

    private ApplicationWorkflow $workflow;

    private const STAGE1 = ['civil_id', 'degree', 'transcript_bachelor', 'transcript_master'];

    private const STAGE2 = ['salary_cert', 'iban', 'employer_approval', 'undertaking'];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(ChecklistItemSeeder::class);
        $instructor = Instructor::factory()->for(User::factory()->instructor())->create(['basic_salary' => null, 'total_salary' => null]);
        $this->application = Application::factory()->for(Term::factory()->open())->for($instructor)->create();
        $this->workflow = app(ApplicationWorkflow::class);
    }

    private function accept(array $codes): void
    {
        foreach ($codes as $code) {
            Document::factory()->for($this->application)->forItem($code)->accepted()->create();
        }
    }

    public function test_plan_splits_applicable_items_by_stage(): void
    {
        $plan = app(ChecklistResolver::class)->for($this->application->instructor);
        $this->assertSame(self::STAGE1, $plan->stage1->pluck('code')->all());
        $this->assertSame(self::STAGE2, $plan->stage2->pluck('code')->all());
        $this->assertSame([...self::STAGE1, ...self::STAGE2], $plan->required->pluck('code')->all());
    }

    public function test_rows_come_stage_one_first_with_stage_and_exemption_keys(): void
    {
        $rows = $this->workflow->checklist($this->application);
        $this->assertSame([...self::STAGE1, ...self::STAGE2], array_keys($rows));
        $this->assertSame(1, $rows['degree']['stage']);
        $this->assertSame(2, $rows['iban']['stage']);
        $this->assertNull($rows['transcript_bachelor']['exemption']);
    }

    public function test_exemption_states(): void
    {
        ChecklistExemption::factory()->for($this->application)->forItem('transcript_bachelor')->create();
        ChecklistExemption::factory()->for($this->application)->forItem('transcript_master')->accepted()->create();
        $rows = $this->workflow->checklist($this->application);
        $this->assertSame(ApplicationWorkflow::STATE_EXEMPTION_REQUESTED, $rows['transcript_bachelor']['state']);
        $this->assertSame(ApplicationWorkflow::STATE_EXEMPTED, $rows['transcript_master']['state']);

        $rows['transcript_bachelor']['exemption']->update(['status' => ChecklistExemption::STATUS_REJECTED, 'decision_note' => 'x', 'decided_at' => now()]);
        $rows = $this->workflow->checklist($this->application);
        $this->assertSame('missing', $rows['transcript_bachelor']['state']);
        $this->assertSame('x', $rows['transcript_bachelor']['exemption']->decision_note);
    }

    public function test_uploaded_document_wins_over_an_exemption(): void
    {
        ChecklistExemption::factory()->for($this->application)->forItem('transcript_bachelor')->accepted()->create();
        Document::factory()->for($this->application)->forItem('transcript_bachelor')->create();
        $this->assertSame('pending', $this->workflow->checklist($this->application)['transcript_bachelor']['state']);
    }

    public function test_submit_gate_reads_stage_one_only_and_accepts_a_pending_exemption(): void
    {
        $this->assertFalse($this->workflow->allRequiredUploaded($this->application));
        $this->accept(['civil_id', 'degree', 'transcript_master']);
        ChecklistExemption::factory()->for($this->application)->forItem('transcript_bachelor')->create();
        $this->assertTrue($this->workflow->allRequiredUploaded($this->application), 'stage-2 items and a pending exemption must not block submission');
    }

    public function test_complete_gate_blocks_on_pending_exemption_and_passes_on_accepted_one(): void
    {
        $this->accept(['civil_id', 'degree', 'transcript_master']);
        $exemption = ChecklistExemption::factory()->for($this->application)->forItem('transcript_bachelor')->create();
        $this->assertFalse($this->workflow->allRequiredAccepted($this->application));
        $this->assertTrue($this->workflow->hasUndecidedExemptions($this->application));

        $exemption->update(['status' => ChecklistExemption::STATUS_ACCEPTED, 'decided_at' => now()]);
        $this->assertTrue($this->workflow->allRequiredAccepted($this->application), 'stage-2 items must not block the committee step');
        $this->assertFalse($this->workflow->hasUndecidedExemptions($this->application));
    }

    public function test_mark_complete_message_names_exemptions_when_they_are_the_only_blocker(): void
    {
        $this->accept(['civil_id', 'degree', 'transcript_master']);
        ChecklistExemption::factory()->for($this->application)->forItem('transcript_bachelor')->create();
        $this->application->update(['status' => Application::STATUS_UNDER_REVIEW]);
        try {
            $this->workflow->markComplete($this->application, User::factory()->admin()->create());
            $this->fail('expected DomainException');
        } catch (\DomainException $e) {
            $this->assertSame(__('app.review.complete_blocked_exemptions'), $e->getMessage());
        }
    }

    public function test_stage_two_complete_needs_documents_and_salary(): void
    {
        $this->application->update(['status' => Application::STATUS_APPROVED]);
        $this->assertFalse($this->workflow->stageTwoComplete($this->application));
        $this->assertContains('شهادة راتب حديثة', $this->workflow->stageTwoMissing($this->application));

        $this->accept(self::STAGE2);
        $this->assertFalse($this->workflow->stageTwoComplete($this->application), 'salary still missing');
        $this->assertSame([__('app.profile.salary_missing')], $this->workflow->stageTwoMissing($this->application));

        $this->application->instructor->update(['basic_salary' => '900', 'total_salary' => '1200']);
        $this->assertTrue($this->workflow->stageTwoComplete($this->application->fresh()));
        $this->assertSame([], $this->workflow->stageTwoMissing($this->application->fresh()));
    }

    public function test_stage_two_counts_on_file_copies_but_not_renewing_items(): void
    {
        $this->application->update(['status' => Application::STATUS_APPROVED]);
        $this->application->instructor->update(['basic_salary' => '900', 'total_salary' => '1200']);
        $earlier = Application::factory()->for(Term::factory()->create(['academic_year' => '2025-2026', 'teaching_starts_on' => now()->subYear(), 'teaching_ends_on' => now()->subMonths(8), 'status' => 'closed']))
            ->for($this->application->instructor)->create(['status' => Application::STATUS_APPROVED]);
        foreach (['iban', 'salary_cert'] as $code) {
            Document::factory()->for($earlier)->forItem($code)->accepted()->create();
        }
        $this->accept(['employer_approval', 'undertaking']);
        $rows = $this->workflow->checklist($this->application->fresh());
        $this->assertSame(ApplicationWorkflow::STATE_ON_FILE, $rows['iban']['state']);
        $this->assertSame('missing', $rows['salary_cert']['state'], 'renews each term');
        $this->assertFalse($this->workflow->stageTwoComplete($this->application->fresh()));
    }

    public function test_stage_two_is_never_complete_before_approval(): void
    {
        $this->accept([...self::STAGE1, ...self::STAGE2]);
        $this->application->instructor->update(['basic_salary' => '900', 'total_salary' => '1200']);
        $this->assertFalse($this->workflow->stageTwoComplete($this->application->fresh()));
    }

    public function test_optional_rows_are_ignored_by_every_gate(): void
    {
        ChecklistItem::where('code', 'transcript_master')->update(['optional' => true]);
        $this->accept(['civil_id', 'degree', 'transcript_bachelor']);
        $this->assertTrue($this->workflow->allRequiredUploaded($this->application));
        $this->assertTrue($this->workflow->allRequiredAccepted($this->application));
    }

    public function test_accepts_stage_two_uploads_only_when_approved_on_an_open_term(): void
    {
        $this->assertFalse($this->application->acceptsStageTwoUploads());
        $this->application->update(['status' => Application::STATUS_APPROVED]);
        $this->assertTrue($this->application->fresh()->acceptsStageTwoUploads());
        $this->assertTrue($this->application->instructor->hasApprovedApplicationInOpenTerm());
        $this->application->term->update(['status' => 'closed']);
        $this->assertFalse($this->application->fresh()->acceptsStageTwoUploads());
        $this->assertFalse($this->application->instructor->fresh()->hasApprovedApplicationInOpenTerm());
    }

    public function test_printed_check_list_prints_official_items_only(): void
    {
        $admin = User::factory()->admin()->create();
        $path = app(ChecklistDocument::class)->build($this->application, $admin);
        $zip = new \ZipArchive;
        $zip->open($path);
        $text = html_entity_decode(strip_tags(str_replace('</w:p>', "\n", $zip->getFromName('word/document.xml'))));
        $zip->close();
        @unlink($path);
        $this->assertStringContainsString('شهادة راتب حديثة', $text);
        $this->assertStringNotContainsString('كشف درجات', $text);
    }
}

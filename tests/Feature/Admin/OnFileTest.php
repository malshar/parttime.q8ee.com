<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\ChecklistItem;
use App\Models\ChecklistRenewal;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use App\Services\ApplicationWorkflow;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnFileTest extends TestCase
{
    use RefreshDatabase;

    private Instructor $instructor;

    private Term $old;

    private Term $current;

    private Application $previous;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChecklistItemSeeder::class);
        $this->instructor = Instructor::factory()->for(User::factory()->instructor())->create(['civil_id_expires_on' => now()->addYear()->toDateString()]);
        $this->old = Term::factory()->create(['academic_year' => '2025-2026', 'type' => 'second', 'teaching_starts_on' => '2026-01-11', 'teaching_ends_on' => '2026-05-14', 'status' => Term::STATUS_CLOSED]);
        $this->current = Term::factory()->open()->create(['academic_year' => '2026-2027', 'type' => 'first']);
        $this->previous = Application::factory()->for($this->old)->for($this->instructor)->create(['status' => Application::STATUS_APPROVED]);
        $this->application = Application::factory()->for($this->current)->for($this->instructor)->create();
    }

    private function workflow(): ApplicationWorkflow
    {
        return app(ApplicationWorkflow::class);
    }

    private function accepted(Application $app, string $code, ?string $reviewedAt = null): Document
    {
        return Document::factory()->for($app)->forItem($code)->accepted()->create(['reviewed_at' => $reviewedAt ?? now()->subMonth()]);
    }

    public function test_accepted_earlier_document_is_on_file_with_its_source(): void
    {
        $src = $this->accepted($this->previous, 'degree');

        $row = $this->workflow()->checklist($this->application)['degree'];

        $this->assertSame('on_file', $row['state']);
        $this->assertTrue($row['source']->is($src));
        $this->assertNull($row['document']);
        $this->assertNull($row['renewal']);
    }

    public function test_renewing_items_and_pending_or_rejected_copies_are_never_on_file(): void
    {
        $this->accepted($this->previous, 'salary_cert');
        Document::factory()->for($this->previous)->forItem('iban')->create();           // pending
        Document::factory()->for($this->previous)->forItem('civil_id')->rejected()->create();

        $c = $this->workflow()->checklist($this->application);

        $this->assertSame('missing', $c['salary_cert']['state']);
        $this->assertSame('missing', $c['iban']['state']);
        $this->assertSame('missing', $c['civil_id']['state']);
    }

    public function test_civil_id_on_file_only_while_not_expired(): void
    {
        $this->accepted($this->previous, 'civil_id');

        $this->instructor->update(['civil_id_expires_on' => now()->addDay()->toDateString()]);
        $this->assertSame('on_file', $this->workflow()->checklist($this->application->fresh())['civil_id']['state']);

        $this->instructor->update(['civil_id_expires_on' => now()->toDateString()]);
        $this->assertSame('missing', $this->workflow()->checklist($this->application->fresh())['civil_id']['state']);
    }

    public function test_latest_accepted_copy_wins_and_rejected_newer_copy_is_ignored(): void
    {
        $older = Term::factory()->create(['academic_year' => '2024-2025', 'type' => 'first', 'teaching_starts_on' => '2024-09-08', 'teaching_ends_on' => '2024-12-19', 'status' => Term::STATUS_CLOSED]);
        $oldest = Application::factory()->for($older)->for($this->instructor)->create(['status' => Application::STATUS_WITHDRAWN]);
        $a = $this->accepted($oldest, 'degree', '2024-10-01 10:00:00');
        $b = $this->accepted($this->previous, 'degree', '2026-02-01 10:00:00');
        $this->assertTrue($this->workflow()->checklist($this->application)['degree']['source']->is($b));

        Document::factory()->for($this->previous)->forItem('iban')->rejected()->create(['reviewed_at' => '2026-02-02 10:00:00']);
        $this->accepted($oldest, 'iban', '2024-10-02 10:00:00');
        $row = $this->workflow()->checklist($this->application->fresh())['iban'];
        $this->assertSame('on_file', $row['state']);
        $this->assertSame($oldest->id, $row['source']->application_id);
    }

    public function test_only_earlier_terms_and_other_applications_count(): void
    {
        $later = Term::factory()->create(['academic_year' => '2027-2028', 'type' => 'first', 'teaching_starts_on' => '2027-09-12', 'teaching_ends_on' => '2027-12-23', 'status' => Term::STATUS_CLOSED]);
        $future = Application::factory()->for($later)->for($this->instructor)->create(['status' => Application::STATUS_APPROVED]);
        $this->accepted($future, 'degree');
        $this->assertSame('missing', $this->workflow()->checklist($this->application)['degree']['state']);

        $stranger = Application::factory()->for($this->old)->create(['status' => Application::STATUS_APPROVED]);
        $this->accepted($stranger, 'iban');
        $this->assertSame('missing', $this->workflow()->checklist($this->application->fresh())['iban']['state']);
    }

    public function test_unreviewed_earlier_draft_or_withdrawn_application_gives_nothing(): void
    {
        // A distinct earlier term is used for the draft: `applications` has a unique
        // (term_id, instructor_id) key, and $this->previous already occupies $this->old.
        $earlier = Term::factory()->create(['academic_year' => '2024-2025', 'type' => 'second', 'teaching_starts_on' => '2025-01-12', 'teaching_ends_on' => '2025-05-15', 'status' => Term::STATUS_CLOSED]);
        $draft = Application::factory()->for($earlier)->for($this->instructor)->create(['status' => Application::STATUS_DRAFT]);
        $this->previous->update(['status' => Application::STATUS_WITHDRAWN]);
        Document::factory()->for($this->previous)->forItem('degree')->create();

        foreach ($this->workflow()->checklist($this->application) as $row) {
            $this->assertNotSame('on_file', $row['state']);
        }
    }

    public function test_document_in_this_application_and_renewal_row_take_precedence(): void
    {
        $this->accepted($this->previous, 'degree');
        $this->accepted($this->previous, 'iban');
        Document::factory()->for($this->application)->forItem('degree')->create();
        $item = ChecklistItem::where('code', 'iban')->first();
        $renewal = ChecklistRenewal::factory()->for($this->application)->create(['checklist_item_id' => $item->id, 'reason' => 'الآيبان تغير']);

        $c = $this->workflow()->checklist($this->application);

        $this->assertSame('pending', $c['degree']['state']);
        $this->assertNull($c['degree']['source']);
        $this->assertSame('missing', $c['iban']['state']);
        $this->assertTrue($c['iban']['renewal']->is($renewal));
    }

    public function test_on_file_counts_as_uploaded_and_accepted(): void
    {
        foreach (['civil_id', 'degree', 'iban'] as $code) {
            $this->accepted($this->previous, $code);
        }
        foreach (['salary_cert', 'employer_approval', 'undertaking'] as $code) {
            Document::factory()->for($this->application)->forItem($code)->accepted()->create();
        }

        $this->assertTrue($this->workflow()->allRequiredUploaded($this->application));
        $this->assertTrue($this->workflow()->allRequiredAccepted($this->application));
    }

    public function test_instructor_iban_edit_after_acceptance_drops_only_iban(): void
    {
        $this->accepted($this->previous, 'iban');
        $this->accepted($this->previous, 'degree');
        AuditLog::record($this->instructor->user_id, 'edit_profile', $this->instructor, null, 'bank_name,iban');

        $c = $this->workflow()->checklist($this->application);

        $this->assertSame('missing', $c['iban']['state']);
        $this->assertNull($c['iban']['source']);
        $this->assertSame('on_file', $c['degree']['state']);
    }

    public function test_unrelated_or_other_instructor_edit_keeps_copy_on_file(): void
    {
        $this->accepted($this->previous, 'iban');
        AuditLog::record($this->instructor->user_id, 'edit_profile', $this->instructor, null, 'mobile');
        $stranger = Instructor::factory()->for(User::factory()->instructor())->create();
        AuditLog::record($stranger->user_id, 'edit_profile', $stranger, null, 'iban');

        $this->assertSame('on_file', $this->workflow()->checklist($this->application)['iban']['state']);
    }

    public function test_admin_edit_of_civil_id_after_acceptance_drops_civil_id(): void
    {
        $this->accepted($this->previous, 'civil_id');
        $this->assertSame('on_file', $this->workflow()->checklist($this->application)['civil_id']['state']);

        // Subject is one of the instructor's applications: both subject forms count.
        $admin = User::factory()->admin()->create();
        AuditLog::record($admin->id, 'admin_edit_profile', $this->previous, null, 'civil_id,mobile');

        $this->assertSame('missing', $this->workflow()->checklist($this->application->fresh())['civil_id']['state']);
    }

    public function test_edit_before_acceptance_keeps_copy_on_file(): void
    {
        $this->travelTo(now()->subMonths(2));
        AuditLog::record($this->instructor->user_id, 'edit_profile', $this->instructor, null, 'iban');
        $this->travelBack();
        $this->accepted($this->previous, 'iban');

        $this->assertSame('on_file', $this->workflow()->checklist($this->application)['iban']['state']);
    }
}

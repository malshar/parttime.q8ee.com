<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\CommitteeApproval;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use App\Services\ApplicationWorkflow;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class YearApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(ChecklistItemSeeder::class);
    }

    public function test_committee_approval_creates_the_year_row_and_links_the_application(): void
    {
        $admin = User::factory()->admin()->create();
        $app = Application::factory()->complete()->for(Term::factory()->open())->create();
        app(ApplicationWorkflow::class)->committeeDecision($app, $admin, 'approved', '2026-10-01', 'ق/7', 'ملاحظة');

        $row = CommitteeApproval::where('instructor_id', $app->instructor_id)->firstOrFail();
        $this->assertSame('2026-2027', $row->academic_year);
        $this->assertSame(CommitteeApproval::KIND_INITIAL, $row->kind);
        $this->assertSame(CommitteeApproval::OUTCOME_APPROVED, $row->outcome);
        $this->assertSame('ق/7', $row->committee_reference);
        $this->assertSame('ملاحظة', $row->note);
        $this->assertSame($admin->id, $row->decided_by);
        $this->assertSame($row->id, $app->fresh()->approval_id);
        $this->assertTrue($app->instructor->hasApprovalFor('2026-2027'));
        $this->assertFalse($app->instructor->hasApprovalFor('2027-2028'));
    }

    public function test_rejection_creates_no_row(): void
    {
        $app = Application::factory()->complete()->for(Term::factory()->open())->create();
        app(ApplicationWorkflow::class)->committeeDecision($app, User::factory()->admin()->create(), 'rejected', '2026-10-01', 'ق/8', 'غير مستوف');
        $this->assertDatabaseCount('committee_approvals', 0);
        $this->assertFalse($app->instructor->hasApprovalFor('2026-2027'));
    }

    public function test_not_renewed_row_is_not_an_approval(): void
    {
        $instructor = Instructor::factory()->for(User::factory()->instructor())->create();
        CommitteeApproval::factory()->for($instructor)->renewal()->notRenewed()->create(['academic_year' => '2027-2028']);
        $this->assertFalse($instructor->hasApprovalFor('2027-2028'));
        $this->assertNotNull($instructor->approvalFor('2027-2028'));
    }

    public function test_one_row_per_instructor_and_year(): void
    {
        $instructor = Instructor::factory()->for(User::factory()->instructor())->create();
        CommitteeApproval::factory()->for($instructor)->create(['academic_year' => '2026-2027']);
        $this->expectException(QueryException::class);
        CommitteeApproval::factory()->for($instructor)->create(['academic_year' => '2026-2027']);
    }
}

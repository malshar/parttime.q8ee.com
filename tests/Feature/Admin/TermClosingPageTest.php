<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\Attestation;
use App\Models\CommitteeApproval;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use App\Services\TermClosingReport;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TermClosingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_rows_counts_and_page(): void
    {
        $this->seed(ChecklistItemSeeder::class);
        $admin = User::factory()->admin()->create();
        $term = Term::factory()->open()->create();   // 2026-09-13 .. 2026-12-24 → 4 months
        $next = Term::factory()->create(['type' => 'second', 'teaching_starts_on' => '2027-02-07', 'teaching_ends_on' => '2027-05-27']);
        $i = Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'سالم المنتدب']);
        $app = Application::factory()->approved()->for($term)->for($i)->create();
        CommitteeApproval::factory()->for($i)->create();
        Attestation::factory()->for($app)->create(['year' => 2026, 'month' => 9, 'status' => Attestation::STATUS_EXPORTED]);
        Attestation::factory()->for($app)->create(['year' => 2026, 'month' => 10, 'status' => Attestation::STATUS_GENERATED]);
        $cont = Application::factory()->continuation()->for($next)->for($i)->create();

        $report = app(TermClosingReport::class);
        $rows = $report->rows($term);
        $row = $rows->first();
        $this->assertSame(['exported', 'generated', 'missing', 'missing'], array_column($row['months'], 'status'));
        $this->assertTrue($row['next']->is($cont));
        $this->assertContains('شهادة راتب حديثة', $row['nextMissing']);
        $this->assertSame(['approved' => 1, 'months_unexported' => 3, 'continuations_missing' => 0], $report->counts($rows, $report->nextTerm($term)));

        $this->actingAs($admin)->get(route('admin.terms.closing', $term))->assertOk()
            ->assertSee('سالم المنتدب')->assertSee(__('app.terms.closing_title'))->assertSee(__('app.applications.statuses.draft'))
            ->assertSee(route('admin.applications.show', $cont));
        $this->actingAs($i->user)->get(route('admin.terms.closing', $term))->assertForbidden();

        $term->update(['status' => 'closed']);
        $this->actingAs($admin)->get(route('admin.terms.closing', $term))->assertOk();
        $this->actingAs($admin)->get(route('admin.terms.index'))->assertOk()->assertSee(route('admin.terms.closing', $term));
    }
}

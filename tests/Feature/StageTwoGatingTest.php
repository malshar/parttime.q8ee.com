<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use App\Services\Attestations\AttestationService;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StageTwoGatingTest extends TestCase
{
    use RefreshDatabase;

    private Term $term;

    private Application $ready;

    private Application $waiting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChecklistItemSeeder::class);
        $this->term = Term::factory()->open()->create();
        $this->ready = $this->approved('جاهز', salary: true, docs: true);
        $this->waiting = $this->approved('منتظر', salary: false, docs: true);
    }

    private function approved(string $name, bool $salary, bool $docs): Application
    {
        $instructor = Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => $name,
            'basic_salary' => $salary ? '900' : null, 'total_salary' => $salary ? '1200' : null]);
        $app = Application::factory()->approved()->for($this->term)->for($instructor)->create();
        if ($docs) {
            foreach (['salary_cert', 'iban', 'employer_approval', 'undertaking'] as $code) {
                Document::factory()->for($app)->forItem($code)->accepted()->create();
            }
        }
        $section = Section::factory()->for($this->term)->create();
        Assignment::factory()->for($app)->for($section)->create();

        return $app;
    }

    public function test_listed_excludes_applications_with_stage_two_incomplete(): void
    {
        $service = app(AttestationService::class);
        $this->assertSame([$this->ready->id], $service->listed($this->term)->pluck('id')->all());
        $this->assertSame([$this->waiting->id], $service->awaitingDocuments($this->term)->pluck('id')->all());
        $this->assertSame([__('app.profile.salary_missing')], $service->awaitingDocuments($this->term)->first()->missing);
    }

    public function test_generate_missing_skips_waiting_applications(): void
    {
        $admin = User::factory()->admin()->create();
        $m = $this->term->months()[0];
        $n = app(AttestationService::class)->generateMissing($this->term, $m['year'], $m['month'], $admin);
        $this->assertSame(1, $n);
        $this->assertDatabaseMissing('attestations', ['application_id' => $this->waiting->id]);
    }

    public function test_attestation_page_and_dashboard_list_waiting_applications_with_missing_items(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('admin.attestations.index', ['term' => $this->term->id]))->assertOk()
            ->assertSee(__('app.attestations.awaiting_documents'))->assertSee('منتظر')->assertSee(__('app.profile.salary_missing'));
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()
            ->assertSee(__('app.review.group_awaiting_documents'))->assertSee('منتظر')->assertDontSee('جاهز — '.__('app.profile.salary_missing'));
    }
}

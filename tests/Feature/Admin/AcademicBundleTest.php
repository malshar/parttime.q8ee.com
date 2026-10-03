<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\ChecklistExemption;
use App\Models\CommitteeApproval;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use App\Services\AcademicBundle;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AcademicBundleTest extends TestCase
{
    use RefreshDatabase;

    private Instructor $instructor;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ChecklistItemSeeder::class);
        $this->admin = User::factory()->admin()->create();
        $this->instructor = Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'محمد أحمد علي الفهد', 'basic_salary' => '900', 'total_salary' => '1200']);
        $old = Application::factory()->approved()->for(Term::factory()->create())->for($this->instructor)->create();
        $new = Application::factory()->approved()->for(Term::factory()->create(['type' => 'second', 'teaching_starts_on' => '2027-02-07', 'teaching_ends_on' => '2027-05-27']))->for($this->instructor)->create();
        CommitteeApproval::factory()->for($this->instructor)->create();
        foreach ([[$old, 'degree', 'old-degree.pdf', 1], [$new, 'degree', 'new-degree.pdf', 1], [$new, 'degree', 'new-degree-2.pdf', 2], [$old, 'civil_id', 'id.pdf', 1], [$old, 'iban', 'iban.pdf', 1]] as [$app, $code, $name, $part]) {
            $path = "applications/{$app->id}/".$name;
            Storage::disk('local')->put($path, 'content '.$name);
            Document::factory()->for($app)->forItem($code)->accepted()->create(['path' => $path, 'original_name' => $name, 'part' => $part, 'reviewed_at' => $app->is($new) ? now() : now()->subYear()]);
        }
        ChecklistExemption::factory()->for($new)->forItem('transcript_bachelor')->accepted()->create();
    }

    public function test_bundle_has_summary_and_latest_accepted_academic_parts_only(): void
    {
        $zipPath = app(AcademicBundle::class)->build($this->instructor, $this->admin);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        sort($names);
        $this->assertSame(['00-summary.docx', 'civil_id-1-id.pdf', 'degree-1-new-degree.pdf', 'degree-2-new-degree-2.pdf'], $names);
        @unlink($zipPath);
    }

    public function test_summary_mentions_exemption_and_never_salary_or_iban(): void
    {
        $zipPath = app(AcademicBundle::class)->build($this->instructor, $this->admin);
        $zip = new \ZipArchive;
        $zip->open($zipPath);
        $docx = $zip->getFromName('00-summary.docx');
        $zip->close();
        $tmp = tempnam(sys_get_temp_dir(), 'sum').'.docx';
        file_put_contents($tmp, $docx);
        $inner = new \ZipArchive;
        $inner->open($tmp);
        $text = html_entity_decode(strip_tags($inner->getFromName('word/document.xml')));
        $inner->close();
        @unlink($tmp);
        @unlink($zipPath);
        $this->assertStringContainsString('محمد أحمد علي الفهد', $text);
        $this->assertStringContainsString($this->instructor->civil_id, $text);
        $this->assertStringContainsString(__('app.bundle.exempted', [], 'ar'), $text);
        $this->assertStringContainsString('2026-2027', $text);
        $this->assertStringNotContainsString('1200', $text);
        $this->assertStringNotContainsString($this->instructor->iban, $text);
    }

    public function test_route_streams_zip_for_admin_only_audits_and_cleans_up(): void
    {
        $this->actingAs($this->admin)->get(route('admin.instructors.bundle', $this->instructor))->assertOk()->assertHeader('content-type', 'application/zip');
        $this->assertDatabaseHas('audit_log', ['action' => 'export_academic_bundle', 'subject_id' => $this->instructor->id]);
        $this->assertSame([], Storage::disk('local')->files('generated/tmp'));
        $this->actingAs($this->instructor->user)->get(route('admin.instructors.bundle', $this->instructor))->assertForbidden();
    }

    public function test_instructor_page_shows_approvals_and_bundle_button(): void
    {
        $this->actingAs($this->admin)->get(route('admin.instructors.show', $this->instructor))->assertOk()
            ->assertSee('2026-2027')->assertSee(route('admin.instructors.bundle', $this->instructor));
    }
}

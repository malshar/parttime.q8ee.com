<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Attestation;
use App\Models\AuditLog;
use App\Models\Instructor;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use App\Services\Attestations\AttestationGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AttestationExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Term $term;

    private Attestation $a;

    protected function setUp(): void
    {
        parent::setUp();
        chmod(base_path('tests/Fixtures/fake-soffice.sh'), 0755);
        config(['services.soffice.path' => base_path('tests/Fixtures/fake-soffice.sh')]);
        $this->admin = User::factory()->admin()->create();
        $this->term = Term::factory()->open()->create(['type' => 'summer', 'academic_year' => '2025-2026', 'teaching_starts_on' => '2026-06-07', 'teaching_ends_on' => '2026-07-23']);
        $application = Application::factory()->approved()->for($this->term)->for(Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'أحمد سالم']))->create();
        Assignment::factory()->for($application)->for(Section::factory()->for($this->term)->withMeetings()->create())->create();
        $this->a = app(AttestationGenerator::class)->generate($application, 2026, 6, $this->admin);
    }

    private function tmpFiles(): array
    {
        return array_values(array_filter(glob(storage_path('app/private/generated/tmp/*')) ?: [], fn ($f) => is_file($f)));
    }

    public function test_word_download_marks_exported_audits_and_leaves_no_temp_file(): void
    {
        $r = $this->actingAs($this->admin)->get(route('admin.attestations.download', [$this->a, 'format' => 'docx']));

        $r->assertOk()->assertDownload("kh3-{$this->a->application_id}-2026-6.docx");
        $r->baseResponse->sendContent();
        $this->assertSame(Attestation::STATUS_EXPORTED, $this->a->fresh()->status);
        $this->assertNotNull($this->a->fresh()->exported_at);
        $this->assertDatabaseHas('audit_log', ['action' => 'export_attestation', 'subject_id' => $this->a->id, 'details' => 'docx', 'user_id' => $this->admin->id]);
        $this->assertSame(1, AuditLog::where('action', 'export_attestation')->count());
    }

    public function test_pdf_download_uses_the_converter(): void
    {
        $r = $this->actingAs($this->admin)->get(route('admin.attestations.download', [$this->a, 'format' => 'pdf']));

        $r->assertOk()->assertDownload("kh3-{$this->a->application_id}-2026-6.pdf");
        $r->baseResponse->sendContent();
        $this->assertDatabaseHas('audit_log', ['action' => 'export_attestation', 'subject_id' => $this->a->id, 'details' => 'pdf']);
        $this->assertSame([], array_filter($this->tmpFiles(), fn ($f) => str_ends_with($f, '.docx')));
    }

    public function test_pdf_failure_reports_unavailable_keeps_status_and_cleans_up(): void
    {
        // Symfony Process inherits only the intersection of getenv() and $_SERVER by default
        // (deliberately, so unrelated PHP-FPM request-context vars never leak to child processes),
        // so both need setting for the fake script to see the flag.
        putenv('FAKE_SOFFICE_FAIL=1');
        $_SERVER['FAKE_SOFFICE_FAIL'] = '1';
        try {
            $this->actingAs($this->admin)->from(route('admin.attestations.show', $this->a))
                ->get(route('admin.attestations.download', [$this->a, 'format' => 'pdf']))
                ->assertRedirect(route('admin.attestations.show', $this->a))
                ->assertSessionHasErrors(['export' => __('app.attestations.pdf_unavailable')]);
        } finally {
            putenv('FAKE_SOFFICE_FAIL');
            unset($_SERVER['FAKE_SOFFICE_FAIL']);
        }

        $this->assertSame(Attestation::STATUS_GENERATED, $this->a->fresh()->status);
        $this->assertDatabaseMissing('audit_log', ['action' => 'export_attestation']);
        $this->assertSame([], $this->tmpFiles());
        // Word still works afterwards
        $this->actingAs($this->admin)->get(route('admin.attestations.download', [$this->a, 'format' => 'docx']))->assertOk();
    }

    public function test_pdf_with_missing_binary_reports_unavailable(): void
    {
        config(['services.soffice.path' => '/nonexistent/soffice']);

        $this->actingAs($this->admin)->get(route('admin.attestations.download', [$this->a, 'format' => 'pdf']))
            ->assertSessionHasErrors('export');
    }

    public function test_unknown_format_is_404_and_downloads_work_on_closed_term(): void
    {
        $this->actingAs($this->admin)->get(route('admin.attestations.download', [$this->a, 'format' => 'xls']))->assertNotFound();
        $this->term->update(['status' => Term::STATUS_CLOSED]);
        $this->actingAs($this->admin)->get(route('admin.attestations.download', [$this->a, 'format' => 'docx']))->assertOk();
    }

    public function test_combined_pdf_marks_every_included_attestation_and_audits_each(): void
    {
        $second = Application::factory()->approved()->for($this->term)->for(Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'بدر ناصر']))->create();
        Assignment::factory()->for($second)->for(Section::factory()->for($this->term)->withMeetings()->create())->create();
        $b = app(AttestationGenerator::class)->generate($second, 2026, 6, $this->admin);

        $r = $this->actingAs($this->admin)->get(route('admin.attestations.combined', ['term' => $this->term->id, 'month' => 1]));

        $r->assertOk()->assertDownload("kh3-{$this->term->id}-2026-6.pdf");
        $r->baseResponse->sendContent();
        $this->assertSame(Attestation::STATUS_EXPORTED, $this->a->fresh()->status);
        $this->assertSame(Attestation::STATUS_EXPORTED, $b->fresh()->status);
        $this->assertSame(2, AuditLog::where('action', 'export_attestation')->where('details', 'combined_pdf')->count());
    }

    public function test_combined_pdf_includes_attestation_of_instructor_whose_assignments_were_removed(): void
    {
        $second = Application::factory()->approved()->for($this->term)->for(Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'بدر ناصر']))->create();
        Assignment::factory()->for($second)->for(Section::factory()->for($this->term)->withMeetings()->create())->create();
        $b = app(AttestationGenerator::class)->generate($second, 2026, 6, $this->admin);
        Assignment::where('application_id', $this->a->application_id)->delete();

        $r = $this->actingAs($this->admin)->get(route('admin.attestations.combined', ['term' => $this->term->id, 'month' => 1]));

        $r->assertOk()->assertDownload("kh3-{$this->term->id}-2026-6.pdf");
        ob_start();
        $r->baseResponse->sendContent();
        ob_end_clean();
        $this->assertSame(Attestation::STATUS_EXPORTED, $this->a->fresh()->status);
        $this->assertSame(Attestation::STATUS_EXPORTED, $b->fresh()->status);
        $this->assertDatabaseHas('audit_log', ['action' => 'export_attestation', 'subject_id' => $this->a->id, 'details' => 'combined_pdf']);
    }

    public function test_combined_pdf_failure_mid_export_leaves_nothing_exported_or_audited(): void
    {
        $second = Application::factory()->approved()->for($this->term)->for(Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'بدر ناصر']))->create();
        Assignment::factory()->for($second)->for(Section::factory()->for($this->term)->withMeetings()->create())->create();
        $b = app(AttestationGenerator::class)->generate($second, 2026, 6, $this->admin);
        // Force markExported's AuditLog::record() to fail so the transaction around the
        // combined-export loop has something to roll back.
        Schema::drop('audit_log');

        $this->actingAs($this->admin)->get(route('admin.attestations.combined', ['term' => $this->term->id, 'month' => 1]))
            ->assertServerError();

        $this->assertSame(Attestation::STATUS_GENERATED, $this->a->fresh()->status);
        $this->assertSame(Attestation::STATUS_GENERATED, $b->fresh()->status);
        $this->assertSame([], $this->tmpFiles());
    }

    public function test_combined_pdf_with_nothing_generated_is_refused(): void
    {
        $this->actingAs($this->admin)->from(route('admin.attestations.index', ['term' => $this->term->id, 'month' => 2]))
            ->get(route('admin.attestations.combined', ['term' => $this->term->id, 'month' => 2]))
            ->assertRedirect(route('admin.attestations.index', ['term' => $this->term->id, 'month' => 2]))
            ->assertSessionHasErrors('export');
    }

    public function test_instructor_forbidden_on_download_and_combined(): void
    {
        $user = User::factory()->instructor()->create();
        $this->actingAs($user)->get(route('admin.attestations.download', [$this->a, 'format' => 'docx']))->assertForbidden();
        $this->actingAs($user)->get(route('admin.attestations.combined', ['term' => $this->term->id, 'month' => 1]))->assertForbidden();
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles() as $f) {
            File::delete($f);
        }
        parent::tearDown();
    }
}

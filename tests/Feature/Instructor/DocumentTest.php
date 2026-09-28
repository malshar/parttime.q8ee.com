<?php

namespace Tests\Feature\Instructor;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ChecklistItemSeeder::class);
        $this->user = User::factory()->instructor()->create();
        $instructor = Instructor::factory()->for($this->user)->create();
        $this->application = Application::factory()->for(Term::factory()->open())->for($instructor)->create();
    }

    private function upload(string $code, UploadedFile $file, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->post(route('instructor.documents.store', [$this->application, $code]), ['file' => $file]);
    }

    public function test_upload_pdf_stores_privately_with_random_name_and_version_1(): void
    {
        $this->upload('civil_id', UploadedFile::fake()->create('البطاقة.pdf', 200, 'application/pdf'))
            ->assertRedirect(route('instructor.applications.show', $this->application));

        $doc = Document::firstOrFail();
        $this->assertSame(1, $doc->version);
        $this->assertSame('pending', $doc->status);
        $this->assertSame('البطاقة.pdf', $doc->original_name);
        $this->assertStringStartsWith("applications/{$this->application->id}/", $doc->path);
        $this->assertStringNotContainsString('البطاقة', $doc->path);
        Storage::disk('local')->assertExists($doc->path);
    }

    public function test_reupload_creates_version_2_and_keeps_version_1(): void
    {
        $this->upload('civil_id', UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'));
        $this->upload('civil_id', UploadedFile::fake()->image('b.jpg'));

        $this->assertSame([1, 2], Document::orderBy('version')->pluck('version')->all());
        $this->assertSame(2, $this->application->latestDocuments()->get('civil_id')->version);
    }

    public function test_zip_and_oversize_and_exe_rejected(): void
    {
        $this->upload('civil_id', UploadedFile::fake()->create('docs.zip', 10, 'application/zip'))->assertSessionHasErrors('file');
        $this->upload('civil_id', UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf'))->assertSessionHasErrors('file');
        $this->upload('civil_id', UploadedFile::fake()->create('x.exe', 10, 'application/octet-stream'))->assertSessionHasErrors('file');
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_cannot_upload_for_item_not_required(): void
    {
        $this->upload('equivalency', UploadedFile::fake()->create('e.pdf', 10, 'application/pdf'))->assertForbidden();
        $this->upload('schedule', UploadedFile::fake()->create('s.pdf', 10, 'application/pdf'))->assertForbidden();
    }

    public function test_cannot_upload_after_submission_or_on_closed_term(): void
    {
        $this->application->update(['status' => Application::STATUS_SUBMITTED]);
        $this->upload('civil_id', UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'))->assertForbidden();

        $this->application->update(['status' => Application::STATUS_DRAFT]);
        $this->application->term->update(['status' => 'closed']);
        $this->upload('civil_id', UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'))->assertForbidden();
    }

    public function test_owner_downloads_and_stranger_gets_403_without_audit_entry(): void
    {
        $this->upload('civil_id', UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'));
        $doc = Document::firstOrFail();

        $this->actingAs($this->user)->get(route('instructor.documents.download', $doc))->assertOk();

        $stranger = User::factory()->instructor()->create();
        Instructor::factory()->for($stranger)->create();
        $this->actingAs($stranger)->get(route('instructor.documents.download', $doc))->assertForbidden();
        $this->assertSame(0, AuditLog::where('action', 'download_document')->count());
    }
}

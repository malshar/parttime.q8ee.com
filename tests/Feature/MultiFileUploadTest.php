<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ChecklistItem;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use App\Services\DocumentStore;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MultiFileUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $admin;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ChecklistItemSeeder::class);
        $this->user = User::factory()->instructor()->create();
        $this->admin = User::factory()->admin()->create();
        $this->application = Application::factory()->for(Term::factory()->open())->for(Instructor::factory()->for($this->user))->create();
    }

    private function files(int $n): array
    {
        return array_map(fn ($i) => UploadedFile::fake()->create("p$i.pdf", 10, 'application/pdf'), range(1, $n));
    }

    private function upload(array $data)
    {
        return $this->actingAs($this->user)->post(route('instructor.documents.store', [$this->application, 'degree']), $data);
    }

    public function test_three_files_become_three_parts_of_one_version(): void
    {
        $this->upload(['files' => $this->files(3)])->assertRedirect();
        $docs = Document::where('application_id', $this->application->id)->orderBy('part')->get();
        $this->assertSame([1, 1, 1], $docs->pluck('version')->all());
        $this->assertSame([1, 2, 3], $docs->pluck('part')->all());
        $this->assertSame(['p1.pdf', 'p2.pdf', 'p3.pdf'], $docs->pluck('original_name')->all());
        foreach ($docs as $d) {
            Storage::disk('local')->assertExists($d->path);
        }

        $head = $this->application->latestDocuments()->get('degree');
        $this->assertSame(1, $head->part);
        $this->assertCount(3, $head->parts);

        $this->upload(['files' => $this->files(1)])->assertRedirect();
        $this->assertSame(2, $this->application->latestDocuments()->get('degree')->version);
    }

    public function test_legacy_single_file_field_still_works(): void
    {
        $this->upload(['file' => UploadedFile::fake()->create('one.pdf', 10, 'application/pdf')])->assertRedirect();
        $this->assertDatabaseHas('documents', ['original_name' => 'one.pdf', 'version' => 1, 'part' => 1]);
    }

    public function test_limits_and_zip_refused(): void
    {
        $this->upload(['files' => $this->files(11)])->assertSessionHasErrors('files');
        $this->upload(['files' => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'), UploadedFile::fake()->create('b.zip', 10, 'application/zip')]])->assertSessionHasErrors();
        $this->upload([])->assertSessionHasErrors('files');
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_review_of_the_head_updates_every_part(): void
    {
        $this->upload(['files' => $this->files(2)]);
        $this->application->update(['status' => Application::STATUS_UNDER_REVIEW]);
        $head = $this->application->latestDocuments()->get('degree');
        $this->actingAs($this->admin)->post(route('admin.documents.review', $head), ['status' => 'rejected', 'reason' => 'ناقص'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['rejected', 'rejected'], Document::orderBy('part')->pluck('status')->all());
        $this->assertSame(['ناقص', 'ناقص'], Document::orderBy('part')->pluck('rejection_reason')->all());
    }

    public function test_non_array_files_field_is_a_validation_error_not_a_500(): void
    {
        $this->upload(['files' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->assertSessionHasErrors('files');
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_store_cleans_up_files_when_a_part_fails_to_persist(): void
    {
        $item = ChecklistItem::where('code', 'degree')->first();

        // Simulate the second part's row failing to persist (a constraint violation, a lost
        // connection, …) after its file has already been written to disk.
        $created = 0;
        Document::creating(function () use (&$created) {
            if (++$created === 2) {
                throw new \RuntimeException('simulated failure persisting the second part');
            }
        });

        try {
            app(DocumentStore::class)->store($this->application, $item, $this->files(2));
            $this->fail('Expected an exception from the simulated failure.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertDatabaseCount('documents', 0);
        Storage::disk('local')->assertDirectoryEmpty("applications/{$this->application->id}");
    }

    public function test_pages_link_every_part_and_history_shows_the_count(): void
    {
        $this->upload(['files' => $this->files(2)]);
        $parts = Document::orderBy('part')->get();
        $this->actingAs($this->user)->get(route('instructor.applications.show', $this->application))->assertOk()
            ->assertSee(route('instructor.documents.download', $parts[0]))->assertSee(route('instructor.documents.download', $parts[1]));
        $this->actingAs($this->admin)->get(route('admin.applications.show', $this->application))->assertOk()
            ->assertSee(route('admin.documents.download', $parts[1]))->assertSee(__('app.documents.parts_count', ['n' => 2]));
    }
}

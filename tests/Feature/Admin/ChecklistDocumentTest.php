<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use App\Services\ChecklistDocument;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChecklistDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Generated .docx files go to a throwaway disk, never the real private disk.
        Storage::fake('local');
    }

    private function docxText(string $path): string
    {
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        return html_entity_decode(strip_tags(preg_replace('/<\/w:p>/', "\n", $xml)));
    }

    public function test_builds_docx_with_header_fields_and_item_states(): void
    {
        $this->seed(ChecklistItemSeeder::class);
        $admin = User::factory()->admin()->create(['name' => 'د. مشعل الشريده']);
        $instructor = Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'محمد أحمد علي الفهد']);
        $term = Term::factory()->open()->create(['academic_year' => '2026-2027', 'type' => 'first']);
        $app = Application::factory()->for($term)->for($instructor)->create();
        Document::factory()->for($app)->forItem('civil_id')->accepted()->create();

        $path = app(ChecklistDocument::class)->build($app, $admin);
        $text = $this->docxText($path);

        $this->assertStringContainsString('Check List', $text);
        $this->assertStringContainsString('محمد أحمد علي الفهد', $text);
        $this->assertStringContainsString($instructor->civil_id, $text);
        $this->assertStringContainsString('الفصل الأول', $text);
        $this->assertStringContainsString('2026-2027', $text);
        $this->assertStringContainsString('☑ صورة البطاقة المدنية سارية المفعول', $text);
        $this->assertStringContainsString('☐ صورة من المؤهل العلمي', $text);
        $this->assertStringContainsString('— صورة من معادلة المؤهل العلمي', $text);
        $this->assertStringContainsString('☐ الجدول الدراسي', $text);
        $this->assertStringContainsString('د. مشعل الشريده', $text);
    }

    public function test_special_characters_in_names_are_escaped(): void
    {
        $this->seed(ChecklistItemSeeder::class);
        $admin = User::factory()->admin()->create(['name' => "د. مشعل 'الشريده'"]);
        $instructor = Instructor::factory()->for(User::factory()->instructor())->create([
            'employer' => 'شركة الخليج & الشرق <للتجارة>',
        ]);
        $app = Application::factory()->for($instructor)->create();

        $path = app(ChecklistDocument::class)->build($app, $admin);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $documentXml = $zip->getFromName('word/document.xml');
        $zip->close();

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($documentXml);
        libxml_clear_errors();
        $this->assertTrue($loaded, 'word/document.xml must be well-formed XML');

        $text = $this->docxText($path);
        $this->assertStringContainsString('شركة الخليج & الشرق <للتجارة>', $text);
    }

    public function test_route_streams_docx_for_admin_only(): void
    {
        $this->seed(ChecklistItemSeeder::class);
        $app = Application::factory()->create();

        $this->actingAs(User::factory()->admin()->create())->get(route('admin.applications.checklist', $app))
            ->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $this->actingAs($app->instructor->user)->get(route('admin.applications.checklist', $app))->assertForbidden();
    }

    public function test_downloaded_checklist_file_is_deleted_after_send(): void
    {
        $this->seed(ChecklistItemSeeder::class);
        $app = Application::factory()->create();

        $response = $this->actingAs(User::factory()->admin()->create())->get(route('admin.applications.checklist', $app));
        $response->assertOk();
        $this->assertCount(1, Storage::disk('local')->files('generated'), 'the request must have generated one checklist file');

        // Laravel's HTTP test client never calls Response::send(), so BinaryFileResponse's
        // deleteFileAfterSend cleanup (which runs inside sendContent()) never fires on its own.
        // Trigger it explicitly to reproduce what a real request/response cycle does.
        ob_start();
        $response->baseResponse->sendContent();
        ob_end_clean();

        $this->assertSame([], Storage::disk('local')->files('generated'), 'generated checklist file must be deleted after being sent');
    }

    public function test_on_file_items_print_as_present(): void
    {
        $this->seed(ChecklistItemSeeder::class);
        $admin = User::factory()->admin()->create();
        $instructor = Instructor::factory()->for(User::factory()->instructor())->create();
        $old = Term::factory()->create(['academic_year' => '2025-2026', 'type' => 'second', 'teaching_starts_on' => '2026-01-11', 'teaching_ends_on' => '2026-05-14', 'status' => Term::STATUS_CLOSED]);
        $previous = Application::factory()->for($old)->for($instructor)->create(['status' => Application::STATUS_APPROVED]);
        Document::factory()->for($previous)->forItem('degree')->accepted()->create(['reviewed_at' => now()->subMonth()]);
        $app = Application::factory()->for(Term::factory()->open()->create(['academic_year' => '2026-2027', 'type' => 'first']))->for($instructor)->create();

        $text = $this->docxText(app(ChecklistDocument::class)->build($app, $admin));

        $this->assertStringContainsString('☑ صورة من المؤهل العلمي', $text);
        $this->assertStringContainsString('☐ صورة البطاقة المدنية سارية المفعول', $text);
    }
}

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
use Tests\TestCase;

class ChecklistDocumentTest extends TestCase
{
    use RefreshDatabase;

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
        $this->assertStringContainsString('☑ الجدول الدراسي', $text);
        $this->assertStringContainsString('د. مشعل الشريده', $text);
    }

    public function test_route_streams_docx_for_admin_only(): void
    {
        $this->seed(ChecklistItemSeeder::class);
        $app = Application::factory()->create();

        $this->actingAs(User::factory()->admin()->create())->get(route('admin.applications.checklist', $app))
            ->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $this->actingAs($app->instructor->user)->get(route('admin.applications.checklist', $app))->assertForbidden();
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class SectionImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Term $term;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChecklistItemSeeder::class);
        $this->admin = User::factory()->admin()->create();
        $this->term = Term::factory()->open()->create();
    }

    private function upload(string $name = 'jadawil-sample.csv'): UploadedFile
    {
        return new UploadedFile(base_path('tests/Fixtures/'.$name), $name, 'text/csv', null, true);
    }

    public function test_preview_shows_counts_and_errors_and_writes_nothing(): void
    {
        $r = $this->actingAs($this->admin)->post(route('admin.sections.import.preview'), ['file' => $this->upload()])->assertOk();
        $r->assertSee(__('app.sections.preview_insert', ['n' => 3]));
        $r->assertSee('الجمعة');                       // error row shown
        $r->assertSee(__('app.sections.confirm_blocked'));
        $r->assertDontSee(__('app.sections.confirm'));  // button hidden while errors exist
        $this->assertDatabaseCount('sections', 0);
    }

    public function test_confirm_imports_from_session_and_clears_it(): void
    {
        $csv = "\xEF\xBB\xBF\"رقم المقرر\",\"اسم المقرر\",\"النشاط\",\"من\",\"الى\",\"الأيام\",\"الشعبة\"\n\"7220220\",\"الإلكترونيات\",\"محاضرة\",\"8:00\",\"9:15\",\"الأحد\",\"1\"\n";
        $file = UploadedFile::fake()->createWithContent('t.csv', $csv);

        $this->actingAs($this->admin)->post(route('admin.sections.import.preview'), ['file' => $file])->assertOk()->assertSee(__('app.sections.confirm'));
        $this->actingAs($this->admin)->post(route('admin.sections.import.confirm'))->assertRedirect(route('admin.sections.index'));

        $this->assertDatabaseHas('sections', ['term_id' => $this->term->id, 'course_code' => '7220220']);
        $this->assertNull(session('sections_import'));
        $this->actingAs($this->admin)->post(route('admin.sections.import.confirm'))->assertStatus(419);
    }

    public function test_rejects_wrong_file_types_and_requires_open_term(): void
    {
        $this->actingAs($this->admin)->post(route('admin.sections.import.preview'), ['file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('file');

        $this->term->update(['status' => 'closed']);
        $this->actingAs($this->admin)->get(route('admin.sections.import.form'))->assertSee(__('app.terms.none_open'));
        $this->actingAs($this->admin)->post(route('admin.sections.import.preview'), ['file' => $this->upload()])->assertSessionHasErrors('file');
    }

    public function test_index_lists_sections_with_hours_and_summary(): void
    {
        Section::factory()->for($this->term)->withMeetings()->create(['course_code' => '7220220', 'course_name_ar' => 'الإلكترونيات']);
        $r = $this->actingAs($this->admin)->get(route('admin.sections.index'))->assertOk();
        $r->assertSee('7220220')->assertSee('2.5')->assertSee('1.7')->assertSee('محاضرة: الأحد/الثلاثاء 8:00-9:15');
    }

    public function test_instructor_gets_403_on_all_section_routes(): void
    {
        $u = User::factory()->instructor()->create();
        $this->actingAs($u)->get(route('admin.sections.index'))->assertForbidden();
        $this->actingAs($u)->get(route('admin.sections.import.form'))->assertForbidden();
        $this->actingAs($u)->post(route('admin.sections.import.preview'), ['file' => $this->upload()])->assertForbidden();
        $this->actingAs($u)->post(route('admin.sections.import.confirm'))->assertForbidden();
    }
}

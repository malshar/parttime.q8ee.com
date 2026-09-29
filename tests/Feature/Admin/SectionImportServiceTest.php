<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use App\Services\Sections\JadawilParser;
use App\Services\Sections\SectionImporter;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SectionImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private Term $term;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChecklistItemSeeder::class);
        $this->term = Term::factory()->open()->create();
        $this->admin = User::factory()->admin()->create();
    }

    private function timetable(string $csv)
    {
        return (new JadawilParser)->parseCsv($csv);
    }

    private const HEADER = "\"رقم المقرر\",\"اسم المقرر\",\"النشاط\",\"من\",\"الى\",\"الأيام\",\"المدرس\",\"الرقم المرجعي\",\"الشعبة\"\n";

    private function csvA(): string
    {
        return self::HEADER
            ."\"7220220\",\"الإلكترونيات\",\"محاضرة\",\"8:00\",\"9:15\",\"الأحد / الثلاثاء\",\"د. فلان\",\"10231\",\"1\"\n"
            ."\"7210110\",\"الدوائر\",\"محاضرة\",\"8:00\",\"9:15\",\"الإثنين\",\"\",\"10310\",\"1\"\n";
    }

    public function test_first_import_inserts_and_is_idempotent(): void
    {
        $importer = app(SectionImporter::class);
        $plan = $importer->apply($this->term, $this->timetable($this->csvA()), $this->admin);

        $this->assertSame(['insert' => 2, 'update' => 0, 'unchanged' => 0, 'delete' => 0, 'flag' => 0], $plan->counts());
        $this->assertDatabaseCount('sections', 2);
        $this->assertDatabaseCount('section_meetings', 3);
        $this->assertDatabaseHas('audit_log', ['action' => 'import_sections:+2/~0/=0/-0/!0']);

        $plan2 = $importer->apply($this->term, $this->timetable($this->csvA()), $this->admin);
        $this->assertSame(['insert' => 0, 'update' => 0, 'unchanged' => 2, 'delete' => 0, 'flag' => 0], $plan2->counts());
        $this->assertDatabaseCount('section_meetings', 3);
    }

    public function test_reimport_updates_meetings_deletes_unassigned_and_flags_assigned(): void
    {
        $importer = app(SectionImporter::class);
        $importer->apply($this->term, $this->timetable($this->csvA()), $this->admin);
        $assigned = Section::where('course_code', '7220220')->firstOrFail();
        $application = Application::factory()->approved()->for($this->term)->create();
        Assignment::create(['application_id' => $application->id, 'section_id' => $assigned->id]);

        // FK ruling: while the assignment exists, the assigned section cannot be destroyed directly (SQLite enforces FKs in tests).
        try {
            Section::destroy($assigned->id);
            $this->fail('Expected a QueryException from the section_id FK restrict.');
        } catch (QueryException $e) {
            $this->assertDatabaseHas('sections', ['id' => $assigned->id]);
        }

        // 7220220/1 now meets on Wednesday only (changed), 7210110/1 absent, new 7230330/1
        $csvB = self::HEADER
            ."\"7220220\",\"الإلكترونيات\",\"محاضرة\",\"8:00\",\"9:15\",\"الأربعاء\",\"د. فلان\",\"10231\",\"1\"\n"
            ."\"7230330\",\"ورشة\",\"ورشة\",\"8:00\",\"11:00\",\"الخميس\",\"\",\"10400\",\"1\"\n";
        $plan = $importer->plan($this->term, $this->timetable($csvB));
        $this->assertSame(['insert' => 1, 'update' => 1, 'unchanged' => 0, 'delete' => 1, 'flag' => 0], $plan->counts());
        $this->assertDatabaseCount('sections', 2); // plan() wrote nothing

        $importer->apply($this->term, $this->timetable($csvB), $this->admin);
        $this->assertDatabaseMissing('sections', ['course_code' => '7210110']);
        $this->assertSame([3], $assigned->fresh()->meetings->pluck('day_of_week')->all());
        $this->assertSame(75, $application->fresh()->weekly_minutes);

        // Assigned section disappears from the next file → kept + flagged; reappears → unflagged.
        $csvC = self::HEADER."\"7230330\",\"ورشة\",\"ورشة\",\"8:00\",\"11:00\",\"الخميس\",\"\",\"10400\",\"1\"\n";
        $plan = $importer->apply($this->term, $this->timetable($csvC), $this->admin);
        $this->assertSame(1, $plan->counts()['flag']);
        $this->assertTrue($assigned->fresh()->missing_since_import);
        $this->assertDatabaseHas('assignments', ['section_id' => $assigned->id]);

        $importer->apply($this->term, $this->timetable($csvB), $this->admin);
        $this->assertFalse($assigned->fresh()->missing_since_import);
    }

    public function test_apply_refuses_timetables_with_errors(): void
    {
        $importer = app(SectionImporter::class);
        $bad = $this->timetable(self::HEADER."\"7220220\",\"x\",\"محاضرة\",\"8:00\",\"9:15\",\"الجمعة\",\"\",\"1\",\"1\"\n");
        $this->expectException(\DomainException::class);
        $importer->apply($this->term, $bad, $this->admin);
    }

    public function test_apply_on_closed_term_is_refused(): void
    {
        $this->term->update(['status' => 'closed']);
        $this->expectException(\DomainException::class);
        app(SectionImporter::class)->apply($this->term, $this->timetable($this->csvA()), $this->admin);
    }
}

<?php

namespace Tests\Unit\Attestations;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Attestation;
use App\Models\Section;
use App\Models\Term;
use App\Models\TermHoliday;
use App\Models\User;
use App\Services\Attestations\AttestationGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Tests\TestCase;

class AttestationGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private Term $term;

    private Application $application;

    private Section $section;

    /** Spec §4 worked example: summer 2025-2026, one 10 h/week section. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->term = Term::factory()->open()->create(['type' => 'summer', 'academic_year' => '2025-2026', 'teaching_starts_on' => '2026-06-07', 'teaching_ends_on' => '2026-07-23']);
        TermHoliday::factory()->for($this->term)->create(['date' => '2026-06-16', 'name' => 'إجازة رأس السنة الهجرية']);
        $this->application = Application::factory()->approved()->for($this->term)->create();
        $this->section = Section::factory()->for($this->term)->create(['course_code' => '7230101', 'course_name_ar' => 'الدوائر الكهربائية', 'seats_registered' => 18]);
        foreach ([0, 2] as $day) {
            $this->section->meetings()->create(['day_of_week' => $day, 'type' => 'theory', 'starts_at' => '08:00', 'ends_at' => '10:00', 'minutes' => 120, 'activity_ar' => 'محاضرة']);
        }
        foreach ([1, 3, 4] as $day) {
            $this->section->meetings()->create(['day_of_week' => $day, 'type' => 'practical', 'starts_at' => '10:00', 'ends_at' => '12:00', 'minutes' => 120, 'activity_ar' => 'مختبر']);
        }
        Assignment::factory()->for($this->application)->for($this->section)->create();
    }

    private function generator(): AttestationGenerator
    {
        return app(AttestationGenerator::class);
    }

    public function test_june_rows_match_the_official_form(): void
    {
        $a = $this->generator()->generate($this->application, 2026, 6);

        $rows = $a->weeks->map(fn ($w) => [$w->week_number, $w->datesLabel(), $w->working_days, $w->theory_minutes, $w->practical_minutes, $w->field_minutes, $w->note_ar])->all();
        $this->assertSame([
            [1, '7-11', ['2026-06-07', '2026-06-08', '2026-06-09', '2026-06-10', '2026-06-11'], 240, 360, 0, 'أسبوع كامل'],
            [2, '14-18', ['2026-06-14', '2026-06-15', '2026-06-17', '2026-06-18'], 120, 360, 0, "الأحد- الاثنين- الأربعاء- الخميس (فقط)\nيوم الثلاثاء 16 يونيو 2026 إجازة رأس السنة الهجرية"],
            [3, '21-25', ['2026-06-21', '2026-06-22', '2026-06-23', '2026-06-24', '2026-06-25'], 240, 360, 0, 'أسبوع كامل'],
            [4, '28-30', ['2026-06-28', '2026-06-29', '2026-06-30'], 240, 120, 0, 'الأحد- الاثنين- الثلاثاء (فقط)'],
        ], $rows);
        $this->assertSame('الدوائر الكهربائية 7230101', $a->weeks[0]->courses_text);
        $this->assertSame(18, $a->weeks[0]->student_count);
        $this->assertSame(['student_count' => 72, 'theory_minutes' => 840, 'practical_minutes' => 1200, 'field_minutes' => 0, 'total_minutes' => 2040], $a->totals());
        $this->assertSame(Attestation::STATUS_GENERATED, $a->status);
        $this->assertSame(1, $a->monthIndex());
    }

    public function test_july_rows_including_last_teaching_day(): void
    {
        $a = $this->generator()->generate($this->application, 2026, 7);

        $rows = $a->weeks->map(fn ($w) => [$w->week_number, $w->datesLabel(), $w->theory_minutes, $w->practical_minutes, $w->note_ar])->all();
        $this->assertSame([
            [1, '1-2', 0, 240, 'الأربعاء- الخميس (فقط)'],
            [2, '5-9', 240, 360, 'أسبوع كامل'],
            [3, '12-16', 240, 360, 'أسبوع كامل'],
            [4, '19-23', 240, 360, "أسبوع كامل\nآخر يوم دراسي 23 يوليو 2026"],
        ], $rows);
        $this->assertSame(2, $a->monthIndex());
    }

    public function test_generated_twins_equal_values_and_regeneration_resets_edits(): void
    {
        $a = $this->generator()->generate($this->application, 2026, 6);
        $w = $a->weeks[0];
        $this->assertSame($w->theory_minutes, $w->generated_theory_minutes);
        $w->update(['theory_minutes' => 60, 'note_ar' => 'معدل']);

        $again = $this->generator()->generate($this->application, 2026, 6, User::factory()->admin()->create());

        $this->assertSame($a->id, $again->id);
        $this->assertSame(240, $again->weeks[0]->theory_minutes);
        $this->assertSame('أسبوع كامل', $again->weeks[0]->note_ar);
        $this->assertNotNull($again->generated_by);
        $this->assertCount(4, $again->weeks);
    }

    public function test_teaching_starting_midweek_and_friday_holiday(): void
    {
        $term = Term::factory()->open()->create(['teaching_starts_on' => '2026-09-16', 'teaching_ends_on' => '2026-12-24']); // a Wednesday
        TermHoliday::factory()->for($term)->create(['date' => '2026-09-18', 'name' => 'عطلة يوم الجمعة']);
        $app = Application::factory()->approved()->for($term)->create();
        $s = Section::factory()->for($term)->withMeetings()->create(); // theory Sun+Tue 75, practical Mon 100
        Assignment::factory()->for($app)->for($s)->create();

        $a = $this->generator()->generate($app, 2026, 9);

        $this->assertSame('16-17', $a->weeks[0]->datesLabel());
        $this->assertSame(['2026-09-16', '2026-09-17'], $a->weeks[0]->working_days);
        $this->assertSame(0, $a->weeks[0]->theory_minutes);
        $this->assertSame('الأربعاء- الخميس (فقط)', $a->weeks[0]->note_ar);
        $this->assertSame('20-24', $a->weeks[1]->datesLabel());
        $this->assertSame(150, $a->weeks[1]->theory_minutes);
        $this->assertSame(100, $a->weeks[1]->practical_minutes);
    }

    public function test_term_starting_on_a_friday_has_no_empty_first_row(): void
    {
        $term = Term::factory()->open()->create(['teaching_starts_on' => '2026-09-18', 'teaching_ends_on' => '2026-12-24']); // a Friday
        $app = Application::factory()->approved()->for($term)->create();
        Assignment::factory()->for($app)->for(Section::factory()->for($term)->withMeetings()->create())->create();

        $a = $this->generator()->generate($app, 2026, 9);

        $this->assertSame([[1, '20-24'], [2, '27-30']], $a->weeks->map(fn ($w) => [$w->week_number, $w->datesLabel()])->all());
    }

    public function test_unknown_meeting_type_counts_as_theory_and_is_reported_once(): void
    {
        Exceptions::fake();
        $this->section->meetings()->create(['day_of_week' => 0, 'type' => 'lab', 'starts_at' => '13:00', 'ends_at' => '14:00', 'minutes' => 60, 'activity_ar' => 'مختبر']);

        $a = $this->generator()->generate($this->application, 2026, 6);

        $this->assertSame(300, $a->weeks[0]->theory_minutes);      // 240 theory + 60 "lab"
        $this->assertSame(360, $a->weeks[0]->practical_minutes);
        Exceptions::assertReportedCount(1);
    }

    public function test_last_teaching_day_on_a_friday_is_noted_in_the_last_block(): void
    {
        $this->term->update(['teaching_ends_on' => '2026-07-24']); // a Friday

        $a = $this->generator()->generate($this->application->fresh(), 2026, 7);

        $this->assertSame('19-23', $a->weeks->last()->datesLabel());
        $this->assertSame("أسبوع كامل\nآخر يوم دراسي 24 يوليو 2026", $a->weeks->last()->note_ar);
        $this->assertSame(1, $a->weeks->filter(fn ($w) => str_contains($w->note_ar, 'آخر يوم دراسي'))->count());
    }

    public function test_last_teaching_day_early_in_a_month_is_not_noted_in_the_previous_month(): void
    {
        $this->term->update(['teaching_ends_on' => '2026-07-01']); // a Wednesday; its block starts Sunday 28 June

        $june = $this->generator()->generate($this->application->fresh(), 2026, 6);
        $july = $this->generator()->generate($this->application->fresh(), 2026, 7);

        $this->assertStringNotContainsString('آخر يوم دراسي', $june->weeks->last()->note_ar);
        $this->assertStringContainsString('آخر يوم دراسي 1 يوليو 2026', $july->weeks->last()->note_ar);
    }

    public function test_whole_holiday_week_is_a_zero_row_with_holiday_note(): void
    {
        foreach (['2026-06-21', '2026-06-22', '2026-06-23', '2026-06-24', '2026-06-25'] as $d) {
            TermHoliday::factory()->for($this->term)->create(['date' => $d, 'name' => 'إجازة عيد الأضحى']);
        }

        $a = $this->generator()->generate($this->application, 2026, 6);

        $w = $a->weeks[2];
        $this->assertSame('21-25', $w->datesLabel());
        $this->assertSame([], $w->working_days);
        $this->assertSame(0, $w->totalMinutes());
        $this->assertSame('', $w->courses_text);
        $this->assertSame(0, $w->student_count);
        $this->assertStringStartsWith('يوم الأحد 21 يونيو 2026 إجازة عيد الأضحى', $w->note_ar);
        $this->assertSame(5, substr_count($w->note_ar, 'إجازة عيد الأضحى'));
    }

    public function test_same_course_twice_gives_one_line_and_summed_seats(): void
    {
        $s2 = Section::factory()->for($this->term)->create(['course_code' => '7230101', 'course_name_ar' => 'الدوائر الكهربائية', 'section_number' => '2', 'seats_registered' => 7]);
        $s2->meetings()->create(['day_of_week' => 2, 'type' => 'practical', 'starts_at' => '12:00', 'ends_at' => '13:00', 'minutes' => 60, 'activity_ar' => 'مختبر']);
        Assignment::factory()->for($this->application)->for($s2)->create();

        $a = $this->generator()->generate($this->application, 2026, 6);

        $this->assertSame('الدوائر الكهربائية 7230101', $a->weeks[0]->courses_text);
        $this->assertSame(25, $a->weeks[0]->student_count);
        $this->assertSame(420, $a->weeks[0]->practical_minutes);
        // week 2: Tuesday is a holiday, so section 2 does not meet and its seats are not counted
        $this->assertSame(18, $a->weeks[1]->student_count);
    }

    public function test_courses_are_listed_in_course_code_order_one_per_line(): void
    {
        $s2 = Section::factory()->for($this->term)->create(['course_code' => '7210050', 'course_name_ar' => 'الرسم الهندسي', 'seats_registered' => 10]);
        $s2->meetings()->create(['day_of_week' => 0, 'type' => 'field', 'starts_at' => '12:00', 'ends_at' => '14:00', 'minutes' => 120, 'activity_ar' => 'ميداني']);
        Assignment::factory()->for($this->application)->for($s2)->create();

        $a = $this->generator()->generate($this->application, 2026, 6);

        $this->assertSame("الرسم الهندسي 7210050\nالدوائر الكهربائية 7230101", $a->weeks[0]->courses_text);
        $this->assertSame(120, $a->weeks[0]->field_minutes);
    }

    public function test_refusals(): void
    {
        $g = $this->generator();

        $this->expectExceptionMessage(__('app.attestations.month_outside_term'));
        $g->generate($this->application, 2026, 8);
    }

    public function test_refuses_exported_non_approved_closed_term_and_no_assignments(): void
    {
        $g = $this->generator();
        $a = $g->generate($this->application, 2026, 6);
        $a->update(['status' => Attestation::STATUS_EXPORTED]);
        try {
            $g->generate($this->application, 2026, 6);
            $this->fail('exported attestation was regenerated');
        } catch (\DomainException $e) {
            $this->assertSame(__('app.attestations.locked'), $e->getMessage());
        }

        $other = Application::factory()->approved()->for($this->term)->create();
        try {
            $g->generate($other, 2026, 6);
            $this->fail('application without assignments was generated');
        } catch (\DomainException $e) {
            $this->assertSame(__('app.attestations.no_assignments'), $e->getMessage());
        }

        $submitted = Application::factory()->submitted()->for($this->term)->create();
        try {
            $g->generate($submitted, 2026, 6);
            $this->fail('non-approved application was generated');
        } catch (\DomainException $e) {
            $this->assertSame(__('app.attestations.not_approved'), $e->getMessage());
        }

        $this->term->update(['status' => Term::STATUS_CLOSED]);
        $this->application->refresh();
        try {
            $g->generate($this->application, 2026, 7);
            $this->fail('closed term was generated');
        } catch (\DomainException $e) {
            $this->assertSame(__('app.attestations.term_closed'), $e->getMessage());
        }
    }

    public function test_last_day_line_on_a_saturday_first_of_month_goes_to_the_previous_month(): void
    {
        $term = Term::factory()->open()->create(['academic_year' => '2026-2027', 'type' => 'summer', 'teaching_starts_on' => '2026-06-07', 'teaching_ends_on' => '2026-08-01']); // Saturday
        $app = Application::factory()->approved()->for($term)->create();
        Assignment::factory()->for($app)->for(Section::factory()->for($term)->withMeetings()->create())->create();

        $july = $this->generator()->generate($app, 2026, 7);
        $this->assertStringContainsString('آخر يوم دراسي 1 أغسطس 2026', $july->weeks->last()->note_ar);

        $august = $this->generator()->generate($app, 2026, 8);
        $this->assertCount(0, $august->weeks);
    }
}

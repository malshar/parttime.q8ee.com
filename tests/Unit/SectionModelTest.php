<?php

namespace Tests\Unit;

use App\Models\Section;
use App\Models\Term;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SectionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_weekly_minutes_by_type_and_summary(): void
    {
        $section = Section::factory()->for(Term::factory()->open())->withMeetings()->create();

        $this->assertSame(['theory' => 150, 'practical' => 100, 'field' => 0], $section->weeklyMinutesByType());
        $this->assertSame(250, $section->weeklyMinutes());
        $this->assertSame('2.5', Section::hoursFromMinutes(150));
        $this->assertSame('4.2', Section::hoursFromMinutes(250));
        $this->assertStringContainsString('محاضرة: الأحد/الثلاثاء 8:00-9:15', $section->meetingSummary());
        $this->assertStringContainsString('مختبر: الاثنين 9:30-11:10', $section->meetingSummary());
    }

    public function test_unique_section_per_term_and_meeting_uniqueness(): void
    {
        $term = Term::factory()->open()->create();
        Section::factory()->for($term)->create(['course_code' => '7220220', 'section_number' => '1']);
        $this->expectException(UniqueConstraintViolationException::class);
        Section::factory()->for($term)->create(['course_code' => '7220220', 'section_number' => '1']);
    }
}

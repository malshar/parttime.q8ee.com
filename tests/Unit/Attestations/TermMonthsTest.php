<?php

namespace Tests\Unit\Attestations;

use App\Models\Term;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TermMonthsTest extends TestCase
{
    use RefreshDatabase;

    public function test_summer_term_has_two_indexed_months(): void
    {
        $term = Term::factory()->create(['type' => 'summer', 'academic_year' => '2025-2026', 'teaching_starts_on' => '2026-06-07', 'teaching_ends_on' => '2026-07-23']);

        $this->assertSame([
            ['year' => 2026, 'month' => 6, 'index' => 1, 'label' => 'الشهر الأول/ يونيو'],
            ['year' => 2026, 'month' => 7, 'index' => 2, 'label' => 'الشهر الثاني/ يوليو'],
        ], $term->months());
        $this->assertSame(2, $term->monthIndex(2026, 7));
        $this->assertNull($term->monthIndex(2026, 8));
    }

    public function test_regular_term_spanning_a_year_end(): void
    {
        $term = Term::factory()->create(['type' => 'second', 'teaching_starts_on' => '2026-12-20', 'teaching_ends_on' => '2027-04-15']);

        $months = $term->months();
        $this->assertCount(5, $months);
        $this->assertSame(['year' => 2026, 'month' => 12, 'index' => 1, 'label' => 'الشهر الأول/ ديسمبر'], $months[0]);
        $this->assertSame(['year' => 2027, 'month' => 4, 'index' => 5, 'label' => 'الشهر الخامس/ أبريل'], $months[4]);
    }
}

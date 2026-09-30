<?php

namespace Tests\Unit\Attestations;

use App\Support\ArabicDate;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class ArabicDateTest extends TestCase
{
    public function test_month_title_uses_ordinal_and_arabic_month(): void
    {
        $this->assertSame('الشهر الأول/ يونيو', ArabicDate::monthTitle(1, 6));
        $this->assertSame('الشهر الثاني/ يوليو', ArabicDate::monthTitle(2, 7));
        $this->assertSame('الشهر الخامس/ يناير', ArabicDate::monthTitle(5, 1));
    }

    public function test_long_date_and_day_name(): void
    {
        $d = Carbon::create(2026, 6, 16);
        $this->assertSame('16 يونيو 2026', ArabicDate::long($d));
        $this->assertSame('الثلاثاء', ArabicDate::dayName($d));
        $this->assertSame('الجمعة', ArabicDate::dayName(Carbon::create(2026, 6, 19)));
        $this->assertSame('السبت', ArabicDate::dayName(Carbon::create(2026, 6, 20)));
    }
}

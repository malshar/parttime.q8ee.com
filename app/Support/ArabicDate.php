<?php

namespace App\Support;

use Carbon\CarbonInterface;

/** Arabic calendar words as printed on PAAET forms (Kuwait month names, Sunday-first day names). */
final class ArabicDate
{
    public const MONTHS = [1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل', 5 => 'مايو', 6 => 'يونيو',
        7 => 'يوليو', 8 => 'أغسطس', 9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر'];

    public const ORDINALS = [1 => 'الأول', 2 => 'الثاني', 3 => 'الثالث', 4 => 'الرابع', 5 => 'الخامس',
        6 => 'السادس', 7 => 'السابع', 8 => 'الثامن', 9 => 'التاسع', 10 => 'العاشر'];

    /** Carbon dayOfWeek: 0 = Sunday … 6 = Saturday. */
    public const DAYS = [0 => 'الأحد', 1 => 'الاثنين', 2 => 'الثلاثاء', 3 => 'الأربعاء', 4 => 'الخميس', 5 => 'الجمعة', 6 => 'السبت'];

    /** "الشهر الأول/ يونيو" — the schedule table's first column heading. */
    public static function monthTitle(int $index, int $month): string
    {
        return 'الشهر '.self::ORDINALS[$index].'/ '.self::MONTHS[$month];
    }

    /** "16 يونيو 2026" */
    public static function long(CarbonInterface $d): string
    {
        return $d->day.' '.self::MONTHS[$d->month].' '.$d->year;
    }

    public static function dayName(CarbonInterface $d): string
    {
        return self::DAYS[$d->dayOfWeek];
    }
}

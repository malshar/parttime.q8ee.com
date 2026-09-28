<?php

use Illuminate\Support\Carbon;

if (! function_exists('format_date')) {
    function format_date(Carbon|string|null $date): string
    {
        if (! $date) {
            return '—';
        }
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);

        return $date->locale(app()->getLocale())->translatedFormat('d M Y');
    }
}

if (! function_exists('mask_middle')) {
    /** 287*****123 — first 3 + last 3; short strings are fully masked. */
    function mask_middle(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (mb_strlen($value) <= 6) {
            return str_repeat('*', mb_strlen($value));
        }

        return mb_substr($value, 0, 3).'*****'.mb_substr($value, -3);
    }
}

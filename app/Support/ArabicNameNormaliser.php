<?php

namespace App\Support;

class ArabicNameNormaliser
{
    public static function normalise(?string $value): string
    {
        $v = trim((string) $value);
        $v = preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u', '', $v); // tashkeel + tatweel
        $v = str_replace(['أ', 'إ', 'آ'], 'ا', $v);
        $v = str_replace('ة', 'ه', $v);
        $v = str_replace('ى', 'ي', $v);
        $v = preg_replace('/\s+/u', ' ', $v);

        return trim($v);
    }
}

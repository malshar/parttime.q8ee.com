<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Kuwaiti IBAN: KW + 2 check digits + 4-letter bank code + 22 alphanumerics (30 chars), mod-97 valid. */
class Iban implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $iban = self::normalize($value);

        if (! preg_match('/^KW\d{2}[A-Z]{4}[A-Z0-9]{22}$/', $iban) || ! self::mod97Ok($iban)) {
            $fail(__('app.profile.iban_invalid'));
        }
    }

    public static function normalize(mixed $value): string
    {
        return strtoupper(preg_replace('/\s+/', '', (string) $value));
    }

    private static function mod97Ok(string $iban): bool
    {
        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $numeric = '';
        foreach (str_split($rearranged) as $ch) {
            $numeric .= ctype_alpha($ch) ? (string) (ord($ch) - 55) : $ch;
        }
        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) (($remainder.$chunk) % 97);
        }

        return $remainder === 1;
    }
}

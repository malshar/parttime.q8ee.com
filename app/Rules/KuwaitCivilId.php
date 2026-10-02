<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class KuwaitCivilId implements ValidationRule
{
    /** PACI check-digit weights (powers of two mod 11): check = (11 - (sum mod 11)) mod 11. */
    private const WEIGHTS = [2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = is_string($value) ? preg_replace('/\s+/', '', $value) : '';

        if (! preg_match('/^[23]\d{11}$/', $value)) {
            $fail(__('app.profile.civil_id_format'));

            return;
        }

        if (config('app.civil_id_checksum') && ! self::checksumOk($value)) {
            $fail(__('app.profile.civil_id_checksum'));
        }
    }

    public static function checksumOk(string $id): bool
    {
        $sum = 0;
        foreach (self::WEIGHTS as $i => $w) {
            $sum += (int) $id[$i] * $w;
        }
        $check = (11 - ($sum % 11)) % 11;   // remainder 0 gives check digit 0; remainder 1 (digit 10) is never issued

        return $check < 10 && $check === (int) $id[11];
    }
}

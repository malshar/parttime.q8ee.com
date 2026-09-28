<?php

namespace Tests\Unit;

use App\Rules\KuwaitCivilId;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class CivilIdRuleTest extends TestCase
{
    /** Builds a syntactically valid ID: [2|3]YYMMDD + 4 serial + check digit. */
    public static function withCheckDigit(string $first11): string
    {
        $w = [2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 3];
        $sum = 0;
        foreach (str_split($first11) as $i => $d) {
            $sum += (int) $d * $w[$i];
        }
        $check = 11 - ($sum % 11);

        return $first11.$check;
    }

    private function passes(string $value): bool
    {
        return Validator::make(['civil_id' => $value], ['civil_id' => [new KuwaitCivilId]])->passes();
    }

    public function test_accepts_valid_checksum(): void
    {
        $this->assertTrue($this->passes(self::withCheckDigit('29001011234')));
    }

    public function test_rejects_wrong_length_or_letters(): void
    {
        $this->assertFalse($this->passes('12345'));
        $this->assertFalse($this->passes('2900101123A5'));
    }

    public function test_rejects_first_digit_not_2_or_3(): void
    {
        $this->assertFalse($this->passes(self::withCheckDigit('19001011234')));
    }

    public function test_rejects_bad_checksum(): void
    {
        $valid = self::withCheckDigit('29001011234');
        $bad = substr($valid, 0, 11).((int) substr($valid, -1) === 9 ? '0' : '9');
        $this->assertFalse($this->passes($bad));
    }

    public function test_checksum_can_be_disabled_by_config(): void
    {
        config(['app.civil_id_checksum' => false]);
        $valid = self::withCheckDigit('29001011234');
        $bad = substr($valid, 0, 11).((int) substr($valid, -1) === 9 ? '0' : '9');
        $this->assertTrue($this->passes($bad));
    }
}

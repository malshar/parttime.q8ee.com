<?php

namespace Tests\Unit;

use App\Rules\Iban;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class IbanRuleTest extends TestCase
{
    private function passes(string $value): bool
    {
        return Validator::make(['iban' => $value], ['iban' => [new Iban]])->passes();
    }

    public function test_accepts_valid_kuwaiti_iban_with_spaces_and_lowercase(): void
    {
        // Official example IBAN from the Central Bank of Kuwait format registry.
        $this->assertTrue($this->passes('kw81 cbku 0000 0000 0000 1234 5601 01'));
    }

    public function test_rejects_bad_check_digits(): void
    {
        $this->assertFalse($this->passes('KW82CBKU0000000000001234560101'));
    }

    public function test_rejects_non_kuwaiti_or_wrong_length(): void
    {
        $this->assertFalse($this->passes('GB82WEST12345698765432'));
        $this->assertFalse($this->passes('KW81CBKU00000000000012345601'));
    }
}

<?php

namespace Tests\Unit;

use App\Support\KuwaitLists;
use Tests\TestCase;

class KuwaitListsTest extends TestCase
{
    public function test_bank_for_iban_resolves_a_known_code(): void
    {
        $this->assertSame('البنك التجاري الكويتي', KuwaitLists::bankForIban('KW81CBKU0000000000001234560101'));
    }

    public function test_bank_for_iban_returns_null_for_an_unknown_code(): void
    {
        $this->assertNull(KuwaitLists::bankForIban('KW40ZZZZ0000000000001234560101'));
    }

    public function test_is_employer_matches_the_list_exactly(): void
    {
        $this->assertTrue(KuwaitLists::isEmployer('وزارة الصحة'));
        $this->assertFalse(KuwaitLists::isEmployer('شركة'));
    }

    public function test_bank_key_resolves_a_bank_name_to_its_code(): void
    {
        $this->assertSame('WRBA', KuwaitLists::bankKey('بنك وربة'));
    }

    public function test_employers_has_no_duplicates(): void
    {
        $this->assertSame(count(KuwaitLists::EMPLOYERS), count(array_unique(KuwaitLists::EMPLOYERS)));
    }
}

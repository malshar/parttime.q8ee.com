<?php

namespace Tests\Unit;

use App\Support\ArabicNameNormaliser;
use PHPUnit\Framework\TestCase;

class ArabicNameNormaliserTest extends TestCase
{
    public function test_folds_hamza_ta_marbuta_alef_maqsura_tashkeel_and_spaces(): void
    {
        $this->assertSame('احمد مصطفي عبدالله', ArabicNameNormaliser::normalise('  أحمد   مُصطفى عبدالله '));
        $this->assertSame('د. فاطمه', ArabicNameNormaliser::normalise('د. فاطمة'));
        $this->assertSame(ArabicNameNormaliser::normalise('الإثنين'), ArabicNameNormaliser::normalise('الاثنين'));
        $this->assertSame('', ArabicNameNormaliser::normalise(null));
    }
}

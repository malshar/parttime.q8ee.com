<?php

namespace Tests\Unit\Attestations;

use App\Services\Attestations\PdfConverter;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

class PdfConverterTest extends TestCase
{
    public function test_fake_binary_converts_and_cleans_its_profile_dir(): void
    {
        $fake = base_path('tests/Fixtures/fake-soffice.sh');
        chmod($fake, 0755);
        $dir = storage_path('app/private/generated/tmp');
        File::ensureDirectoryExists($dir);
        $docx = $dir.'/unit-'.uniqid().'.docx';
        file_put_contents($docx, 'not really a docx');

        $pdf = (new PdfConverter($fake))->convert($docx);

        $this->assertFileExists($pdf);
        $this->assertStringStartsWith('%PDF', file_get_contents($pdf));
        $this->assertSame([], glob($dir.'/lo-profile-*'));
        unlink($docx);
        unlink($pdf);
    }

    public function test_missing_binary_is_reported_as_unavailable(): void
    {
        $c = new PdfConverter('/nonexistent/soffice');
        $this->assertFalse($c->available());
        $this->expectException(\RuntimeException::class);
        $c->convert('/tmp/whatever.docx');
    }

    public function test_real_libreoffice_when_present(): void
    {
        $bin = (new ExecutableFinder)->find('soffice');
        if ($bin === null) {
            $this->markTestSkipped('soffice not installed');
        }
        $dir = storage_path('app/private/generated/tmp');
        File::ensureDirectoryExists($dir);
        $path = $dir.'/real-'.uniqid().'.docx';
        copy(resource_path('forms/kh3-template.docx'), $path);

        $pdf = (new PdfConverter($bin))->convert($path);

        $this->assertStringStartsWith('%PDF', file_get_contents($pdf));
        unlink($path);
        unlink($pdf);
    }
}

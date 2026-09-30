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

    public function test_failed_run_that_still_wrote_a_pdf_leaves_nothing_behind(): void
    {
        $fake = base_path('tests/Fixtures/fake-soffice.sh');
        chmod($fake, 0755);
        $dir = storage_path('app/private/generated/tmp');
        File::ensureDirectoryExists($dir);
        $docx = $dir.'/unit-'.uniqid().'.docx';
        file_put_contents($docx, 'x');
        putenv('FAKE_SOFFICE_WRITE_THEN_FAIL=1');
        $_SERVER['FAKE_SOFFICE_WRITE_THEN_FAIL'] = '1';
        try {
            $this->expectException(\RuntimeException::class);
            (new PdfConverter($fake))->convert($docx);
        } finally {
            putenv('FAKE_SOFFICE_WRITE_THEN_FAIL');
            unset($_SERVER['FAKE_SOFFICE_WRITE_THEN_FAIL']);
            $this->assertFileDoesNotExist(substr($docx, 0, -5).'.pdf');
            unlink($docx);
        }
    }

    public function test_process_runs_in_the_output_directory(): void
    {
        $fake = base_path('tests/Fixtures/fake-soffice.sh');
        chmod($fake, 0755);
        $dir = storage_path('app/private/generated/tmp');
        File::ensureDirectoryExists($dir);
        $docx = $dir.'/unit-'.uniqid().'.docx';
        file_put_contents($docx, 'x');
        putenv('FAKE_SOFFICE_RECORD_CWD=1');
        $_SERVER['FAKE_SOFFICE_RECORD_CWD'] = '1';
        try {
            $pdf = (new PdfConverter($fake))->convert($docx);
            $this->assertSame(realpath($dir), trim(file_get_contents($dir.'/cwd.txt')));
        } finally {
            putenv('FAKE_SOFFICE_RECORD_CWD');
            unset($_SERVER['FAKE_SOFFICE_RECORD_CWD']);
            @unlink($dir.'/cwd.txt');
            @unlink($docx);
            @unlink($pdf ?? '');
        }
    }
}

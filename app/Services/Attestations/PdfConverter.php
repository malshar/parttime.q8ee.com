<?php

namespace App\Services\Attestations;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/** LibreOffice headless docx → pdf (spec §6.2). One throw-away profile per call so concurrent runs never share a lock. */
final class PdfConverter
{
    private string $binary;

    public function __construct(?string $binary = null)
    {
        $this->binary = $binary ?? (string) config('services.soffice.path', 'soffice');
    }

    public function available(): bool
    {
        if (str_contains($this->binary, '/')) {
            return is_executable($this->binary);
        }

        return (new ExecutableFinder)->find($this->binary) !== null;
    }

    /** @return string path of the PDF, next to the input file */
    public function convert(string $docxPath): string
    {
        if (! $this->available()) {
            throw new RuntimeException("LibreOffice binary not available: {$this->binary}");
        }
        $dir = dirname($docxPath);
        $profile = $dir.'/lo-profile-'.Str::random(12);
        File::ensureDirectoryExists($profile);
        try {
            $process = new Process([$this->binary, '--headless', '--norestore', '-env:UserInstallation=file://'.$profile,
                '--convert-to', 'pdf', '--outdir', $dir, $docxPath]);
            $process->setTimeout(90);
            $process->run();
            $pdf = substr($docxPath, 0, -5).'.pdf';
            if (! $process->isSuccessful() || ! is_file($pdf)) {
                throw new RuntimeException('PDF conversion failed: '.trim($process->getErrorOutput().' '.$process->getOutput()));
            }

            return $pdf;
        } finally {
            File::deleteDirectory($profile);
        }
    }
}

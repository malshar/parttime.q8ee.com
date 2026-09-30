<?php

namespace App\Services\Attestations;

use PhpOffice\PhpWord\TemplateProcessor;

/** Adds the one thing the page-block cloning needs: no page break after the last page. */
final class Kh3TemplateProcessor extends TemplateProcessor
{
    /** The page-break paragraph scripts/build-kh3-template.py puts at the end of the ${page} block. */
    public const PAGE_BREAK = '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';

    public function stripLastPageBreak(): void
    {
        $at = strrpos($this->tempDocumentMainPart, self::PAGE_BREAK);
        if ($at !== false) {
            $this->tempDocumentMainPart = substr_replace($this->tempDocumentMainPart, '', $at, strlen(self::PAGE_BREAK));
        }
    }
}

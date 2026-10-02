<?php

namespace App\Services\Attestations;

use PhpOffice\PhpWord\TemplateProcessor;

/** Adds the one thing the page-block cloning needs: no page break after the last page. */
final class Kh3TemplateProcessor extends TemplateProcessor
{
    /** The page-break run scripts/build-kh3-template.py appends as the last run of the note paragraph
     * ("ملاحظة مهمة") at the end of the ${page} block. */
    public const PAGE_BREAK = '<w:r><w:br w:type="page"/></w:r>';

    public function stripLastPageBreak(): void
    {
        $at = strrpos($this->tempDocumentMainPart, self::PAGE_BREAK);
        if ($at !== false) {
            $this->tempDocumentMainPart = substr_replace($this->tempDocumentMainPart, '', $at, strlen(self::PAGE_BREAK));
        }
    }
}

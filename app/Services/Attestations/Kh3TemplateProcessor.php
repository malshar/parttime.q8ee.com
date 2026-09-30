<?php

namespace App\Services\Attestations;

use PhpOffice\PhpWord\TemplateProcessor;

/** Adds the one thing the page-block cloning needs: no page break before the first page. */
final class Kh3TemplateProcessor extends TemplateProcessor
{
    public function stripFirstPageBreak(): void
    {
        $this->tempDocumentMainPart = preg_replace('~<w:p><w:pPr><w:pageBreakBefore/></w:pPr></w:p>~', '', $this->tempDocumentMainPart, 1);
    }
}

<?php

namespace App\Services;

use App\Models\Application;
use App\Models\CommitteeApproval;
use App\Models\Instructor;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Language;

/** Spec M6 §5: the names list handed to the committee for the renewal batch. */
class RenewalListDocument
{
    public function build(string $year, Collection $candidates, User $by): string
    {
        Settings::setOutputEscapingEnabled(true);

        $previous = CommitteeApproval::previousYear($year);

        $word = new PhpWord;
        $word->setDefaultFontName('Arial');
        $word->setDefaultFontSize(11);
        $word->getSettings()->setThemeFontLang(new Language(null, null, 'ar-KW'));
        $section = $word->addSection(['marginTop' => Converter::cmToTwip(2), 'marginBottom' => Converter::cmToTwip(2)]);
        $rtl = ['bidi' => true, 'alignment' => Jc::START];
        $bold = ['bold' => true];

        $section->addText(__('app.dept_name', [], 'ar').' — '.now()->format('Y/m/d'), [], $rtl);
        $section->addText(__('app.renewals.list_title', ['year' => $year], 'ar'), ['bold' => true, 'size' => 14], ['bidi' => true, 'alignment' => Jc::CENTER]);
        $section->addTextBreak();

        $table = $section->addTable(['borderSize' => 6, 'borderColor' => '000000', 'cellMargin' => 80, 'bidiVisual' => true]);
        $headerCellStyle = ['bgColor' => 'D9D9D9'];
        $headers = array_values(__('app.renewals.list_headers', [], 'ar'));
        $table->addRow();
        foreach ($headers as $header) {
            $table->addCell(Converter::cmToTwip(3), $headerCellStyle)->addText($header, $bold, $rtl);
        }

        foreach ($candidates->values() as $index => $instructor) {
            $table->addRow();
            $table->addCell(Converter::cmToTwip(1.5))->addText((string) ($index + 1), [], $rtl);
            $table->addCell(Converter::cmToTwip(4))->addText($instructor->full_name, [], $rtl);
            $table->addCell(Converter::cmToTwip(3))->addText($instructor->civil_id, [], $rtl);
            $table->addCell(Converter::cmToTwip(3))->addText($instructor->employer, [], $rtl);
            $table->addCell(Converter::cmToTwip(3))->addText(
                __('app.profile.degrees.'.$instructor->highest_degree, [], 'ar').' — '.$instructor->degree_title, [], $rtl
            );
            $table->addCell(Converter::cmToTwip(3))->addText($this->previousYearTerms($instructor, $previous), [], $rtl);
        }

        $section->addTextBreak();
        $section->addText(__('app.renewals.list_footer', [], 'ar'), [], $rtl);
        $section->addTextBreak(2);
        // The signature line is the position, not whoever happened to click "export" (spec M6 §5).
        $section->addText(__('app.renewals.list_signature', [], 'ar'), $bold, $rtl);

        Storage::disk('local')->makeDirectory('generated');
        $path = Storage::disk('local')->path('generated/renewal-list-'.$year.'-'.Str::random(12).'.docx');
        IOFactory::createWriter($word, 'Word2007')->save($path);

        return $path;
    }

    /** Previous-year approved terms' labels for the instructor, joined by '، '. */
    private function previousYearTerms(Instructor $instructor, string $previousYear): string
    {
        return $instructor->applications
            ->filter(fn ($application) => $application->status === Application::STATUS_APPROVED
                && $application->term->academic_year === $previousYear)
            ->map(fn ($application) => $application->term->label())
            ->implode('، ');
    }
}

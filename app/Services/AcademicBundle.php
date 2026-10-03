<?php

namespace App\Services;

use App\Models\ChecklistExemption;
use App\Models\ChecklistItem;
use App\Models\Document;
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

/** The academic documents bundle handed to the committee for a year decision (spec M6 §6). */
class AcademicBundle
{
    public const ITEMS = ['civil_id', 'degree', 'transcript_bachelor', 'transcript_master', 'equivalency'];

    /** The head (part 1) of the latest accepted document per item, across all the instructor's applications, with `parts` loaded. */
    public function latestAccepted(Instructor $instructor): Collection
    {
        $heads = Document::query()
            ->where('status', Document::STATUS_ACCEPTED)
            ->where('part', 1)
            ->whereHas('application', fn ($q) => $q->where('instructor_id', $instructor->id))
            ->with('checklistItem')
            ->orderByDesc('reviewed_at')->orderByDesc('id')
            ->get()
            ->unique(fn (Document $d) => $d->checklistItem->code)
            ->keyBy(fn (Document $d) => $d->checklistItem->code);

        foreach ($heads as $head) {
            $head->setRelation('parts', $head->parts()->get());
        }

        return $heads;
    }

    /** Accepted exemption rows of the instructor's applications, keyed by item code. */
    public function acceptedExemptions(Instructor $instructor): Collection
    {
        return ChecklistExemption::query()
            ->where('status', ChecklistExemption::STATUS_ACCEPTED)
            ->whereHas('application', fn ($q) => $q->where('instructor_id', $instructor->id))
            ->with('item')
            ->get()
            ->keyBy(fn (ChecklistExemption $e) => $e->item->code);
    }

    /** Builds the ZIP (summary + latest accepted academic parts) under the local disk's generated/tmp and returns its path. */
    public function build(Instructor $instructor, User $by): string
    {
        $documents = $this->latestAccepted($instructor);
        $exemptions = $this->acceptedExemptions($instructor);

        $summaryPath = $this->buildSummary($instructor, $documents, $exemptions, $by);

        Storage::disk('local')->makeDirectory('generated/tmp');
        $zipPath = Storage::disk('local')->path('generated/tmp/bundle-'.$instructor->id.'-'.Str::random(12).'.zip');

        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFile($summaryPath, '00-summary.docx');

        foreach (self::ITEMS as $code) {
            $document = $documents->get($code);
            if (! $document) {
                continue;
            }
            foreach ($document->parts as $part) {
                $zip->addFile(Storage::disk('local')->path($part->path), "{$code}-{$part->part}-{$part->original_name}");
            }
        }

        $zip->close();
        @unlink($summaryPath);

        return $zipPath;
    }

    private function buildSummary(Instructor $instructor, Collection $documents, Collection $exemptions, User $by): string
    {
        Settings::setOutputEscapingEnabled(true);

        $word = new PhpWord;
        $word->setDefaultFontName('Arial');
        $word->setDefaultFontSize(11);
        $word->getSettings()->setThemeFontLang(new Language(null, null, 'ar-KW'));
        $section = $word->addSection(['marginTop' => Converter::cmToTwip(2), 'marginBottom' => Converter::cmToTwip(2)]);
        $rtl = ['bidi' => true, 'alignment' => Jc::START];
        $bold = ['bold' => true];

        $section->addText(__('app.bundle.title', [], 'ar'), ['bold' => true, 'size' => 14], ['bidi' => true, 'alignment' => Jc::CENTER]);
        $section->addTextBreak();

        $lines = [
            $instructor->full_name,
            $instructor->civil_id.' — '.__('app.profile.civil_id_expires_on', [], 'ar').' '.$instructor->civil_id_expires_on->format('Y-m-d'),
            $instructor->nationalityLabel(),
            __('app.profile.degrees.'.$instructor->highest_degree, [], 'ar').' — '.$instructor->degree_title,
            __('app.countries.'.$instructor->degree_country, [], 'ar'),
            $instructor->degree_obtained_on->format('Y-m-d'),
        ];
        foreach ($lines as $line) {
            $section->addText($line, [], $rtl);
        }

        $labels = ChecklistItem::whereIn('code', ['equivalency', 'transcript_bachelor', 'transcript_master'])->pluck('label_ar', 'code');

        $section->addText($labels['equivalency'].' : '.$this->equivalencyLine($instructor, $documents), [], $rtl);

        foreach (['transcript_bachelor', 'transcript_master'] as $code) {
            $section->addText($labels[$code].' : '.$this->transcriptLine($code, $documents, $exemptions), [], $rtl);
        }

        $section->addText($instructor->employer.' — '.$instructor->job_title, [], $rtl);

        $section->addTextBreak();
        $section->addText(__('app.review.approvals', [], 'ar'), $bold, $rtl);
        foreach ($instructor->approvals()->orderBy('academic_year')->get() as $approval) {
            $section->addText(__('app.review.year_approval', [
                'year' => $approval->academic_year,
                'kind' => __('app.review.approval_kinds.'.$approval->kind, [], 'ar'),
                'date' => $approval->committee_met_on->format('Y-m-d'),
                'ref' => $approval->committee_reference,
            ], 'ar'), [], $rtl);
        }

        $section->addTextBreak();
        $section->addText(__('app.bundle.generated', ['date' => now()->format('Y-m-d'), 'name' => $by->name], 'ar'), [], $rtl);

        Storage::disk('local')->makeDirectory('generated/tmp');
        $path = Storage::disk('local')->path('generated/tmp/bundle-summary-'.$instructor->id.'-'.Str::random(12).'.docx');
        IOFactory::createWriter($word, 'Word2007')->save($path);

        return $path;
    }

    private function equivalencyLine(Instructor $instructor, Collection $documents): string
    {
        if (! $instructor->isForeignDegree()) {
            return __('app.bundle.not_applicable', [], 'ar');
        }
        if ($document = $documents->get('equivalency')) {
            return __('app.bundle.on_file', ['date' => $document->reviewed_at->format('Y-m-d')], 'ar');
        }

        return __('app.bundle.missing', [], 'ar');
    }

    private function transcriptLine(string $code, Collection $documents, Collection $exemptions): string
    {
        if ($document = $documents->get($code)) {
            return __('app.bundle.on_file', ['date' => $document->reviewed_at->format('Y-m-d')], 'ar');
        }
        if ($exemptions->has($code)) {
            return __('app.bundle.exempted', [], 'ar');
        }

        return __('app.bundle.missing', [], 'ar');
    }
}

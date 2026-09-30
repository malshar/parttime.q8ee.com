<?php

namespace App\Services\Attestations;

use App\Models\Attestation;
use App\Models\AttestationWeek;
use App\Models\Section;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\Settings;

/**
 * Fills resources/forms/kh3-template.docx (spec §6.1). The only place that decrypts civil ID, IBAN and salaries.
 * Returns the path of a temp .docx under storage/app/private/generated/tmp; the caller deletes it after send.
 */
final class AttestationDocument
{
    public function __construct(private ?string $template = null)
    {
        $this->template ??= resource_path('forms/kh3-template.docx');
    }

    public function docx(Attestation $attestation): string
    {
        return $this->build(collect([$attestation]));
    }

    /** @param  Collection<int, Attestation>  $attestations */
    public function combinedDocx(Collection $attestations): string
    {
        return $this->build($attestations->sortBy(fn (Attestation $a) => $a->application->instructor->full_name)->values());
    }

    /** @param  Collection<int, Attestation>  $attestations */
    private function build(Collection $attestations): string
    {
        Settings::setOutputEscapingEnabled(true);
        $tp = new Kh3TemplateProcessor($this->template);
        $tp->cloneBlock('page', $attestations->count(), true, true);
        foreach ($attestations->values() as $i => $attestation) {
            $p = '#'.($i + 1);
            $attestation->loadMissing('weeks', 'application.instructor', 'application.term');
            $tp->setValues($this->headerValues($attestation, $p));
            $weeks = $attestation->weeks->values();
            $tp->cloneRow('week_no'.$p, max(1, $weeks->count()));
            foreach ($weeks as $k => $week) {
                $tp->setValues($this->weekValues($week, $p.'#'.($k + 1)));
            }
            $tp->setValues($this->totalValues($attestation, $p));
        }
        $tp->stripFirstPageBreak();

        $dir = Storage::disk('local')->path('generated/tmp');
        File::ensureDirectoryExists($dir);
        $path = $dir.'/'.Str::random(32).'.docx';
        $tp->saveAs($path);

        return $path;
    }

    /** @return array<string, string> */
    private function headerValues(Attestation $a, string $suffix): array
    {
        $app = $a->application;
        $i = $app->instructor;
        $term = $app->term;
        $courses = $app->sections()->orderBy('course_code')->get()->unique('course_code')->map(fn ($s) => $s->course_name_ar)->values();
        $v = [
            'term_label' => __('app.attestations.term_labels.'.$term->type, [], 'ar'),
            'academic_year' => (string) $term->academic_year,
            'category' => __('app.attestations.category', [], 'ar'),
            'dept_name' => __('app.dept_name', [], 'ar'),
            'decision_number' => (string) ($app->assignment_decision_number ?? ''),
            'decision_date' => $app->assignment_decision_date?->format('j/n/Y') ?? '',
            'full_name' => (string) $i->full_name,
            'job_title' => (string) $i->job_title,
            'employer' => (string) $i->employer,
            'course1' => (string) ($courses[0] ?? ''),
            'course2' => (string) ($courses[1] ?? ''),
            'course3' => $courses->slice(2)->implode('، '),
            'account_number' => (string) $i->iban,
            'bank_name' => (string) $i->bank_name,
            'bank_branch' => (string) $i->bank_branch,
            'basic_salary' => (string) $i->basic_salary,
            'total_salary' => (string) $i->total_salary,
            'phone_work' => (string) $i->work_phone,
            'phone_home' => (string) $i->home_phone,
            'phone_mobile' => (string) $i->mobile,
            'weekly_hours' => Section::hoursForForm((int) $app->weekly_minutes),
            'month_title' => $a->monthTitle(),
        ];
        $digits = str_split(str_pad(preg_replace('/\D/', '', (string) $i->civil_id), 12, ' ', STR_PAD_RIGHT));
        for ($n = 1; $n <= 12; $n++) {
            $v['cid'.$n] = trim($digits[$n - 1]);
        }

        return $this->suffixed($v, $suffix);
    }

    /** @return array<string, string> */
    private function weekValues(AttestationWeek $w, string $suffix): array
    {
        $hours = fn (int $minutes) => $minutes > 0 ? Section::hoursForForm($minutes) : '';

        return $this->suffixed([
            'week_no' => (string) $w->week_number,
            'week_dates' => $w->datesLabel(),
            'week_courses' => (string) $w->courses_text,
            'week_students' => $w->student_count > 0 ? (string) $w->student_count : '',
            'week_theory' => $hours((int) $w->theory_minutes),
            'week_practical' => $hours((int) $w->practical_minutes),
            'week_field' => $hours((int) $w->field_minutes),
            'week_total' => $hours($w->totalMinutes()),
            'week_note' => (string) $w->note_ar,
        ], $suffix);
    }

    /** @return array<string, string> */
    private function totalValues(Attestation $a, string $suffix): array
    {
        $t = $a->totals();

        return $this->suffixed([
            'sum_students' => (string) $t['student_count'],
            'sum_theory' => Section::hoursForForm($t['theory_minutes']),
            'sum_practical' => Section::hoursForForm($t['practical_minutes']),
            'sum_field' => Section::hoursForForm($t['field_minutes']),
            'sum_total' => Section::hoursForForm($t['total_minutes']),
        ], $suffix);
    }

    /** @param  array<string, string>  $values  @return array<string, string> */
    private function suffixed(array $values, string $suffix): array
    {
        $out = [];
        foreach ($values as $k => $v) {
            $out[$k.$suffix] = $v;
        }

        return $out;
    }
}

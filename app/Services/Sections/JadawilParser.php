<?php

namespace App\Services\Sections;

use App\Support\ArabicNameNormaliser;

class JadawilParser
{
    public const REQUIRED = ['رقم المقرر', 'اسم المقرر', 'الشعبة', 'النشاط', 'من', 'الى', 'الأيام'];

    private const ACTIVITY = ['محاضرة' => 'theory', 'مختبر' => 'practical', 'ورشة' => 'practical', 'عملي' => 'practical', 'ميداني' => 'field'];

    /** Normalised day name → 0..4 */
    private const DAYS = ['الاحد' => 0, 'الاثنين' => 1, 'الثلاثاء' => 2, 'الاربعاء' => 3, 'الخميس' => 4];

    public function parse(string $contents, string $extension): ParsedTimetable
    {
        return match (strtolower($extension)) {
            'csv' => $this->parseCsv($contents),
            'xlsx' => $this->parseXlsx($contents), // Task 8
            default => new ParsedTimetable(errors: [__('app.sections.unsupported_file')]),
        };
    }

    public function parseCsv(string $contents): ParsedTimetable
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        $lines = preg_split('/\r\n|\r|\n/', trim($contents)) ?: [];
        $rows = [];
        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $rows[] = ['n' => $i + 1, 'cells' => array_map('trim', str_getcsv($line, ',', '"', ''))];
        }

        return $this->fromRows($rows);
    }

    /**
     * @param  array<int, array{n:int, cells:string[]}>  $rows  first row = header
     */
    protected function fromRows(array $rows): ParsedTimetable
    {
        $t = new ParsedTimetable;
        if ($rows === []) {
            $t->errors[] = __('app.sections.no_header');

            return $t;
        }
        $header = array_map(fn ($h) => trim((string) $h), array_shift($rows)['cells']);
        $idx = array_flip($header);
        foreach (self::REQUIRED as $req) {
            if (! isset($idx[$req])) {
                $t->errors[] = __('app.sections.missing_column', ['column' => $req]);
            }
        }
        if ($t->hasErrors()) {
            return $t;
        }
        $cell = fn (array $cells, string $name) => isset($idx[$name]) ? trim((string) ($cells[$idx[$name]] ?? '')) : '';

        foreach ($rows as $row) {
            $c = $row['cells'];
            $n = $row['n'];
            $code = $cell($c, 'رقم المقرر');
            if ($code === '' || str_contains($code, 'Powered by')) {
                continue; // footer / blank
            }
            $section = $cell($c, 'الشعبة');
            if ($section === '') {
                $t->errors[] = __('app.sections.row_error', ['row' => $n, 'message' => __('app.sections.missing_section')]);
                continue;
            }
            $from = $this->time($cell($c, 'من'));
            $to = $this->time($cell($c, 'الى'));
            if ($from === null || $to === null || $to <= $from) {
                $t->errors[] = __('app.sections.row_error', ['row' => $n, 'message' => __('app.sections.bad_time', ['from' => $cell($c, 'من'), 'to' => $cell($c, 'الى')])]);
                continue;
            }
            $dayCell = $cell($c, 'الأيام');
            $days = [];
            $badDay = null;
            foreach (preg_split('/\s*\/\s*/u', $dayCell) as $d) {
                $k = ArabicNameNormaliser::normalise($d);
                if ($k === '') {
                    continue;
                }
                if (! isset(self::DAYS[$k])) {
                    $badDay = $d;
                    break;
                }
                $days[] = self::DAYS[$k];
            }
            if ($badDay !== null || $days === []) {
                $t->errors[] = __('app.sections.row_error', ['row' => $n, 'message' => __('app.sections.bad_day', ['day' => $badDay ?? '—'])]);
                continue;
            }
            $activity = $cell($c, 'النشاط');
            $type = self::ACTIVITY[$activity] ?? null;
            if ($type === null) {
                $type = 'theory';
                $t->warnings[] = __('app.sections.unknown_activity', ['row' => $n, 'activity' => $activity]);
            }

            $key = $code.'|'.$section;
            $ps = $t->sections[$key] ??= new ParsedSection($code, $cell($c, 'اسم المقرر'), $section);
            $ps->referenceNumber ??= $cell($c, 'الرقم المرجعي') ?: null;
            $ps->scheduledInstructor ??= $cell($c, 'المدرس') ?: null;
            $ps->seatsCapacity ??= $this->int($cell($c, 'الحد الأقصى'));
            $ps->seatsRegistered ??= $this->int($cell($c, 'مسجلة'));
            $ps->seatsRemaining ??= $this->int($cell($c, 'متبقية'));

            $minutes = $this->minutes($to) - $this->minutes($from);
            foreach ($days as $day) {
                $m = new ParsedMeeting($day, $type, $from, $to, $minutes, $activity, $cell($c, 'المبنى') ?: null, $cell($c, 'القاعة') ?: null);
                $dup = array_filter($ps->meetings, fn ($x) => $x->key() === $m->key());
                if ($dup !== []) {
                    $t->warnings[] = __('app.sections.duplicate_meeting', ['row' => $n, 'course' => $code, 'section' => $section]);
                    continue;
                }
                $ps->meetings[] = $m;
            }
        }

        ksort($t->sections);
        $t->warnings = array_values(array_unique($t->warnings));

        return $t;
    }

    /** "8:00" / "13:05" → "08:00" / "13:05"; null when malformed. */
    private function time(string $v): ?string
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $v, $m) || (int) $m[1] > 23 || (int) $m[2] > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $m[1], $m[2]);
    }

    private function minutes(string $hhmm): int
    {
        [$h, $m] = explode(':', $hhmm);

        return (int) $h * 60 + (int) $m;
    }

    private function int(string $v): ?int
    {
        return preg_match('/^\d+$/', $v) ? (int) $v : null;
    }

    protected function parseXlsx(string $contents): ParsedTimetable
    {
        return new ParsedTimetable(errors: [__('app.sections.unsupported_file')]); // replaced in Task 8
    }
}

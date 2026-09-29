<?php

namespace Tests\Unit;

use App\Services\Sections\JadawilParser;
use Tests\TestCase;

class JadawilParserTest extends TestCase
{
    private function csv(): string
    {
        return file_get_contents(base_path('tests/Fixtures/jadawil-sample.csv'));
    }

    public function test_groups_rows_into_sections_and_meetings(): void
    {
        $t = (new JadawilParser)->parseCsv($this->csv());

        $this->assertCount(3, $t->sections); // 7230330/1 is excluded by its error row
        $s = $t->sections['7220220|1'];
        $this->assertSame('الإلكترونيات الصناعية', $s->courseName);
        $this->assertSame('10231', $s->referenceNumber);
        $this->assertSame('د. فلان الفلاني', $s->scheduledInstructor);
        $this->assertCount(3, $s->meetings); // Sun+Tue lecture, Mon lab
        $this->assertSame(['theory' => 150, 'practical' => 100, 'field' => 0], $s->minutesByType());
        $lab = $s->meetings[2];
        $this->assertSame(1, $lab->dayOfWeek);
        $this->assertSame('practical', $lab->type);
        $this->assertSame('09:30', $lab->startsAt);
        $this->assertSame(100, $lab->minutes);
        $this->assertSame('L-12', $lab->room);
    }

    public function test_duplicate_meeting_rows_are_collapsed_with_a_warning(): void
    {
        $t = (new JadawilParser)->parseCsv($this->csv());
        $s = $t->sections['7210110|1'];

        $this->assertCount(3, $s->meetings); // Mon+Wed lecture once, Thu field
        $this->assertSame(['theory' => 150, 'practical' => 0, 'field' => 120], $s->minutesByType());
        $this->assertNotEmpty(array_filter($t->warnings, fn ($w) => str_contains($w, '7210110') && str_contains($w, 'مكرر')));
    }

    public function test_unknown_day_is_an_error_with_row_number_and_blocks(): void
    {
        $t = (new JadawilParser)->parseCsv($this->csv());

        $this->assertTrue($t->hasErrors());
        $this->assertNotEmpty(array_filter($t->errors, fn ($e) => str_contains($e, 'الجمعة') && str_contains($e, '8')));
        $this->assertArrayNotHasKey('7230330|1', $t->sections);
    }

    public function test_unknown_activity_maps_to_theory_with_warning_and_empty_day_is_error(): void
    {
        $csv = "\xEF\xBB\xBF\"رقم المقرر\",\"اسم المقرر\",\"النشاط\",\"من\",\"الى\",\"الأيام\",\"الشعبة\"\n"
            ."\"7200100\",\"مقرر\",\"سمنار\",\"8:00\",\"9:00\",\"الأحد\",\"1\"\n"
            ."\"7200101\",\"مقرر\",\"محاضرة\",\"8:00\",\"9:00\",\"\",\"1\"\n";
        $t = (new JadawilParser)->parseCsv($csv);

        $this->assertSame('theory', $t->sections['7200100|1']->meetings[0]->type);
        $this->assertNotEmpty(array_filter($t->warnings, fn ($w) => str_contains($w, 'سمنار')));
        $this->assertNotEmpty(array_filter($t->errors, fn ($e) => str_contains($e, '3')));
    }

    public function test_missing_required_header_is_an_error(): void
    {
        $t = (new JadawilParser)->parseCsv("\"رقم المقرر\",\"اسم المقرر\"\n\"1\",\"x\"\n");
        $this->assertTrue($t->hasErrors());
        $this->assertSame([], $t->sections);
    }

    public function test_malformed_time_and_end_before_start_are_errors(): void
    {
        $csv = "\"رقم المقرر\",\"اسم المقرر\",\"النشاط\",\"من\",\"الى\",\"الأيام\",\"الشعبة\"\n"
            ."\"7200100\",\"مقرر\",\"محاضرة\",\"8h\",\"9:00\",\"الأحد\",\"1\"\n"
            ."\"7200101\",\"مقرر\",\"محاضرة\",\"9:00\",\"8:00\",\"الأحد\",\"1\"\n";
        $t = (new JadawilParser)->parseCsv($csv);
        $this->assertCount(2, $t->errors);
    }

    public function test_quoted_cell_with_comma_and_crlf_is_parsed(): void
    {
        $csv = "\"رقم المقرر\",\"اسم المقرر\",\"النشاط\",\"من\",\"الى\",\"الأيام\",\"الشعبة\"\r\n"
            ."\"7200100\",\"الدوائر, المستوى 1\",\"محاضرة\",\"8:00\",\"9:00\",\"الأحد\",\"1\"\r\n";
        $t = (new JadawilParser)->parseCsv($csv);

        $s = $t->sections['7200100|1'];
        $this->assertSame('الدوائر, المستوى 1', $s->courseName);
        $this->assertCount(1, $s->meetings);
    }

    public function test_parses_xlsx_with_numeric_cells_and_seats(): void
    {
        $sheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $ws = $sheet->getActiveSheet();
        $ws->fromArray(['#', 'رقم المقرر', 'الرقم المرجعي', 'الشعبة', 'اسم المقرر', 'الحالة', 'الرابط', 'الحد الأقصى', 'مسجلة', 'متبقية', 'الوحدات', 'النشاط', 'من', 'الى', 'المبنى', 'القاعة', 'الأيام', 'المدرس', 'الفرع'], null, 'A1');
        $ws->fromArray([1, 7220220, 10231, 1, 'الإلكترونيات الصناعية', 'مفتوحة', 'A', 25, 20, 5, 3, 'محاضرة', '8:00', '9:15', '04A', 'D-101', 'الأحد / الثلاثاء', 'د. فلان', 'ش'], null, 'A2');
        $ws->fromArray([2, 7220220, 10231, 1, 'الإلكترونيات الصناعية', 'مفتوحة', 'A', 25, 20, 5, 3, 'مختبر', 9.5 / 24, 11.0 / 24, '04A', 'L-12', 'الإثنين', 'م. علان', 'ش'], null, 'A3');
        $path = tempnam(sys_get_temp_dir(), 'jad').'.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sheet))->save($path);

        $t = (new JadawilParser)->parse(file_get_contents($path), 'xlsx');
        unlink($path);

        $this->assertFalse($t->hasErrors(), implode("\n", $t->errors));
        $s = $t->sections['7220220|1'];
        $this->assertSame(25, $s->seatsCapacity);
        $this->assertSame(5, $s->seatsRemaining);
        $this->assertCount(3, $s->meetings);
        $this->assertSame('09:30', $s->meetings[2]->startsAt);
        $this->assertSame(90, $s->meetings[2]->minutes);
    }
}

<?php

namespace Tests\Unit\Attestations;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Attestation;
use App\Models\AttestationWeek;
use App\Models\Instructor;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use App\Services\Attestations\AttestationDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpWord\TemplateProcessor;
use Tests\TestCase;

class AttestationDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_exposes_the_expected_placeholders(): void
    {
        $vars = (new TemplateProcessor(resource_path('forms/kh3-template.docx')))->getVariables();

        foreach (['page', '/page', 'term_label', 'academic_year', 'category', 'dept_name', 'decision_number', 'decision_date', 'full_name', 'job_title',
            'cid1', 'cid12', 'employer', 'course1', 'course2', 'course3', 'account_number', 'bank_name', 'bank_branch', 'basic_salary', 'total_salary',
            'phone_work', 'phone_home', 'phone_mobile', 'weekly_hours', 'month_title', 'week_no', 'week_dates', 'week_courses', 'week_students',
            'week_theory', 'week_practical', 'week_field', 'week_total', 'week_note', 'sum_students', 'sum_theory', 'sum_practical', 'sum_field', 'sum_total'] as $v) {
            $this->assertContains($v, $vars, $v);
        }
    }

    private function attestation(string $name = 'أحمد سالم', string $civilId = '290010112345'): Attestation
    {
        $term = Term::where(['type' => 'summer', 'academic_year' => '2025-2026'])->first()
            ?? Term::factory()->open()->create(['type' => 'summer', 'academic_year' => '2025-2026', 'teaching_starts_on' => '2026-06-07', 'teaching_ends_on' => '2026-07-23']);
        $instructor = Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => $name, 'civil_id' => $civilId, 'job_title' => 'مهندس <أول>', 'work_phone' => '22334455', 'home_phone' => '25556677', 'mobile' => '99887766']);
        $application = Application::factory()->approved()->for($term)->for($instructor)->create(['assignment_decision_number' => '1234', 'assignment_decision_date' => '2026-05-20', 'weekly_minutes' => 600]);
        $a = Attestation::factory()->for($application)->create(['year' => 2026, 'month' => 6]);
        AttestationWeek::factory()->for($a)->create(['week_number' => 1, 'date_from' => '2026-06-07', 'date_to' => '2026-06-11', 'theory_minutes' => 240, 'practical_minutes' => 360, 'student_count' => 18, 'courses_text' => "الدوائر الكهربائية (7230101)\nالرسم الهندسي (7210050)", 'note_ar' => 'أسبوع كامل']);
        AttestationWeek::factory()->for($a)->create(['week_number' => 2, 'date_from' => '2026-06-14', 'date_to' => '2026-06-18', 'theory_minutes' => 120, 'practical_minutes' => 360, 'student_count' => 18, 'note_ar' => "الأحد- الاثنين- الأربعاء- الخميس (فقط)\nيوم الثلاثاء 16 يونيو 2026 إجازة رأس السنة الهجرية"]);

        return $a->load('weeks', 'application.instructor', 'application.term');
    }

    private function documentXml(string $path): string
    {
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($path);

        return $xml;
    }

    public function test_single_document_contains_header_weeks_and_totals(): void
    {
        $a = $this->attestation();

        $xml = $this->documentXml(app(AttestationDocument::class)->docx($a));

        $this->assertStringContainsString('أحمد سالم', $xml);
        $this->assertStringContainsString('مهندس &lt;أول&gt;', $xml);
        $this->assertStringContainsString('للفصل الصيفي', $xml);
        $this->assertStringContainsString('2025-2026', $xml);
        $this->assertStringContainsString(__('app.attestations.category', [], 'ar'), $xml);
        $this->assertStringContainsString(__('app.dept_name', [], 'ar'), $xml);
        $this->assertStringContainsString('( 1234 )', $xml);
        $this->assertStringContainsString('20/05/2026', $xml);
        $this->assertStringContainsString('KW81CBKU0000000000001234560101', $xml);
        $this->assertStringContainsString('1200', $xml);
        $this->assertStringContainsString('الشهر الأول/ يونيو', $xml);
        $this->assertStringContainsString('7-11', $xml);
        $this->assertStringContainsString('14-18', $xml);
        $this->assertStringContainsString('إجازة رأس السنة الهجرية', $xml);
        $this->assertStringContainsString('<w:br/>', $xml);
        $this->assertStringContainsString('>10<', $xml);      // weekly_hours 600 min
        $this->assertStringContainsString('>36<', $xml);      // sum_students
        $this->assertStringContainsString('>6<', $xml);       // sum_theory 360 min
        $this->assertStringContainsString('>12<', $xml);      // sum_practical 720 min
        $this->assertStringContainsString('>18<', $xml);      // sum_total
        $this->assertStringNotContainsString('${', $xml);
        $this->assertSame(1, substr_count($xml, 'استمارة مزاولة فعلية'));
        $this->assertSame(0, substr_count($xml, '<w:pageBreakBefore/>'));
        $this->assertSame(0, substr_count($xml, '<w:br w:type="page"/>'));   // single page: the block's trailing break is stripped
        // twelve civil-ID boxes, in order, each its own cell
        $this->assertMatchesRegularExpression('~'.implode('.*?', array_map(fn ($d) => preg_quote("<w:t xml:space=\"preserve\">$d</w:t>", '~'), str_split('290010112345'))).'~s', $xml);
        $this->assertStringContainsString('الدوائر الكهربائية', $xml);
        $this->assertStringContainsString('الرسم الهندسي', $xml);
    }

    public function test_empty_hours_print_blank_and_course_overflow_joins_into_course3(): void
    {
        $a = $this->attestation();
        $a->weeks[0]->update(['field_minutes' => 0]);
        $courses = [];
        foreach (['7210050' => 'الرسم الهندسي', '7230101' => 'الدوائر الكهربائية', '7230202' => 'الإلكترونيات', '7230303' => 'الآلات الكهربائية'] as $code => $name) {
            $s = Section::factory()->for($a->application->term)->create(['course_code' => $code, 'course_name_ar' => $name]);
            Assignment::factory()->for($a->application)->for($s)->create();
        }

        $xml = $this->documentXml(app(AttestationDocument::class)->docx($a->fresh()->load('weeks', 'application.instructor', 'application.term')));

        $this->assertStringContainsString('الإلكترونيات، الآلات الكهربائية', $xml);
        $row = $this->rowXml($xml, '7-11');     // week 1 row of page 1
        $this->assertSame('4', $this->cellTexts($row)[4]);    // theory 240 min
        $this->assertSame('', $this->cellTexts($row)[6]);     // field 0 min prints blank
        $this->assertStringContainsString('<w:t xml:space="preserve"></w:t>', $this->cellsXml($row)[6]);
    }

    public function test_free_text_cannot_expand_placeholders(): void
    {
        $a = $this->attestation();
        $a->weeks[0]->update(['note_ar' => 'ملاحظة ${cid1#1} ${sum_students#1}', 'courses_text' => 'مقرر ${full_name#1}']);

        $xml = $this->documentXml(app(AttestationDocument::class)->docx($a->fresh()->load('weeks', 'application.instructor', 'application.term')));

        $row = $this->rowXml($xml, '7-11');
        $this->assertSame('ملاحظة cid1#1} sum_students#1}', $this->cellTexts($row)[8]);
        $this->assertSame('مقرر full_name#1}', $this->cellTexts($row)[2]);
        $this->assertStringNotContainsString('${', $xml);
    }

    public function test_attestation_without_weeks_prints_one_blank_week_row(): void
    {
        $a = $this->attestation();
        $a->weeks()->delete();

        $xml = $this->documentXml(app(AttestationDocument::class)->docx($a->fresh()->load('weeks', 'application.instructor', 'application.term')));

        $this->assertStringNotContainsString('${', $xml);
        $table = collect($this->tables($xml))->last();
        $rows = $this->rows($table);
        $this->assertCount(4, $rows);           // heading, sub-heading, one week row, totals
        $this->assertSame(array_fill(0, 9, ''), $this->cellTexts($rows[2]));
    }

    /** @return list<string> */
    private function tables(string $xml): array
    {
        preg_match_all('~<w:tbl>.*?</w:tbl>~s', $xml, $m);

        return $m[0];
    }

    /** @return list<string> */
    private function rows(string $xml): array
    {
        preg_match_all('~<w:tr\b.*?</w:tr>~s', $xml, $m);

        return $m[0];
    }

    /** @return list<string> */
    private function cellsXml(string $row): array
    {
        preg_match_all('~<w:tc>.*?</w:tc>~s', $row, $m);

        return $m[0];
    }

    /** @return list<string> */
    private function cellTexts(string $row): array
    {
        return array_map(function (string $tc) {
            preg_match_all('~<w:t(?:\s[^>]*)?>([^<]*)</w:t>~', $tc, $t);

            return implode('', $t[1]);
        }, $this->cellsXml($row));
    }

    /** The first table row (document order, so page 1) whose cells include the exact text $needle. */
    private function rowXml(string $xml, string $needle): string
    {
        foreach ($this->rows($xml) as $row) {
            if (in_array($needle, $this->cellTexts($row), true)) {
                return $row;
            }
        }
        $this->fail("no row with a cell reading {$needle}");
    }

    public function test_combined_document_has_one_page_block_per_attestation_in_name_order(): void
    {
        $b = $this->attestation('يوسف كامل', '290010154321');
        $a = $this->attestation('أحمد سالم', '290010112345');

        $xml = $this->documentXml(app(AttestationDocument::class)->combinedDocx(collect([$b, $a])));

        $this->assertSame(2, substr_count($xml, 'استمارة مزاولة فعلية'));
        $this->assertSame(0, substr_count($xml, '<w:pageBreakBefore/>'));
        $this->assertSame(1, substr_count($xml, '<w:br w:type="page"/>'));   // between the two pages, none after the last
        $this->assertLessThan(mb_strpos($xml, 'يوسف كامل'), mb_strpos($xml, 'أحمد سالم'));
        $this->assertLessThan(mb_strpos($xml, 'يوسف كامل'), mb_strpos($xml, '<w:br w:type="page"/>'));
        $this->assertGreaterThan(mb_strpos($xml, 'أحمد سالم'), mb_strpos($xml, '<w:br w:type="page"/>'));
        $this->assertStringNotContainsString('${', $xml);
    }

    public function test_nested_placeholder_markers_in_free_text_print_literally(): void
    {
        $a = $this->attestation();
        $a->weeks[0]->update(['note_ar' => 'ملاحظة $${{cid1#1} ونهاية']);

        $xml = $this->documentXml(app(AttestationDocument::class)->docx($a->fresh()->load('weeks', 'application.instructor', 'application.term')));

        // "$${{" -> "${" after one pass -> gone after the second: no marker can re-form.
        $this->assertStringContainsString('ملاحظة cid1#1} ونهاية', $xml);
        $this->assertStringNotContainsString('ملاحظة 2 ونهاية', $xml);
    }

    public function test_template_note_cell_has_no_underline(): void
    {
        $zip = new \ZipArchive;
        $zip->open(resource_path('forms/kh3-template.docx'));
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        preg_match('~<w:tc>(?:(?!<w:tc>).)*?\$\{week_note\}.*?</w:tc>~s', $xml, $m);
        $this->assertNotEmpty($m, 'week_note cell not found');
        $this->assertStringNotContainsString('<w:u ', $m[0]);
        $this->assertStringNotContainsString('<w:u/>', $m[0]);
    }

    public function test_footer_captions_are_single_runs(): void
    {
        $zip = new \ZipArchive;
        $zip->open(resource_path('forms/kh3-template.docx'));
        $footer = $zip->getFromName('word/footer1.xml');
        $zip->close();
        preg_match_all('~<w:t[^>]*>([^<]*)</w:t>~', $footer, $m);
        $this->assertContains('توقيع عضو هيئة التدريس المنتدب', $m[1]);
        $this->assertNotContains('المنتد', $m[1]);
        $this->assertContains('رئيس القسم', array_map('trim', $m[1]));          // leading spaces kept: original spacing
        $this->assertContains('يعتمد/ عميد الكلية', array_map('trim', $m[1]));
        // the captions paragraph is one run; its tab stops are kept
        preg_match('~<w:p\b(?:(?!<w:p\b).)*?رئيس القسم.*?</w:p>~s', $footer, $p);
        $this->assertSame(1, substr_count($p[0], '<w:r>') + substr_count($p[0], '<w:r '));
        $this->assertGreaterThanOrEqual(3, substr_count($p[0], '<w:tab/>'));
    }

    public function test_schedule_table_rows_use_nine_point_font_and_fixed_widths(): void
    {
        $zip = new \ZipArchive;
        $zip->open(resource_path('forms/kh3-template.docx'));
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        $table = substr($xml, strrpos($xml, '<w:tbl>'));
        foreach (['week_no', 'sum_students'] as $marker) {
            preg_match('~<w:tr\b(?:(?!<w:tr\b).)*?\$\{'.$marker.'\}.*?</w:tr>~s', $table, $row);
            $this->assertNotEmpty($row, $marker);
            $this->assertStringContainsString('<w:sz w:val="18"/>', $row[0]);
            $this->assertStringContainsString('<w:szCs w:val="18"/>', $row[0]);
            $this->assertDoesNotMatchRegularExpression('~<w:sz(Cs)? w:val="(?!18")~', $row[0], "$marker row keeps another font size");
        }
        preg_match('~<w:tr\b(?:(?!<w:tr\b).)*?\$\{week_no\}.*?</w:tr>~s', $table, $week);
        $this->assertStringNotContainsString('<w:trHeight', $week[0]);
        $this->assertStringContainsString('<w:tblLayout w:type="fixed"/>', $table);
        $this->assertMatchesRegularExpression('~<w:tblCellMar>.*<w:top w:w="20" w:type="dxa"/>.*<w:bottom w:w="20" w:type="dxa"/>.*</w:tblCellMar>~s', $table);

        preg_match('~<w:tblGrid>.*?</w:tblGrid>~s', $table, $grid);
        preg_match_all('~<w:gridCol w:w="(\d+)"/>~', $grid[0], $cols);
        $cols = array_map('intval', $cols[1]);
        $this->assertCount(9, $cols);
        // XML order = logical order: week no, dates, course, students, theory, practical, field, total, notes
        foreach ([4 => 'theory', 5 => 'practical', 6 => 'field', 7 => 'total'] as $i => $label) {
            $this->assertGreaterThanOrEqual(850, $cols[$i], "$label column too narrow");
        }
        $this->assertGreaterThanOrEqual(2600, $cols[2], 'course column too narrow');
        $this->assertLessThanOrEqual(2600, $cols[8], 'notes column too wide');
        preg_match('~<w:tblW w:w="(\d+)" w:type="dxa"/>~', $table, $w);
        $this->assertSame((int) $w[1], array_sum($cols));
        // the week row's cells carry the grid widths
        preg_match_all('~<w:tcW w:w="(\d+)"~', $week[0], $tcw);
        $this->assertSame($cols, array_map('intval', $tcw[1]));
    }

    public function test_writes_sample_filled_document_for_visual_check(): void
    {
        $sample = function (string $name, string $civilId): Attestation {
            $a = $this->attestation($name, $civilId);
            $a->weeks()->delete();
            $courses = "الدوائر الكهربائية 7230101\nالرسم الهندسي 7210050";
            foreach ([[1, '2026-06-07', '2026-06-11', 'أسبوع كامل'],
                [2, '2026-06-14', '2026-06-18', "الأحد- الاثنين- الأربعاء- الخميس (فقط)\nيوم الثلاثاء 16 يونيو 2026 إجازة رأس السنة الهجرية"],
                [3, '2026-06-21', '2026-06-25', 'أسبوع كامل'],
                [4, '2026-06-28', '2026-07-02', 'أسبوع كامل'],
                [5, '2026-07-05', '2026-07-09', 'أسبوع كامل']] as [$n, $from, $to, $note]) {
                AttestationWeek::factory()->for($a)->create(['week_number' => $n, 'date_from' => $from, 'date_to' => $to, 'theory_minutes' => 110, 'practical_minutes' => 220, 'field_minutes' => 0,
                    'student_count' => 31, 'courses_text' => $courses, 'note_ar' => $note]);
            }

            return $a->fresh()->load('weeks', 'application.instructor', 'application.term');
        };
        $b = $sample('يوسف عبدالله كامل العنزي', '290010154321');
        $a = $sample('أحمد سالم محمد الشمري', '290010112345');

        $path = app(AttestationDocument::class)->combinedDocx(collect([$b, $a]));
        $target = storage_path('app/private/generated/sample-kh3.docx');
        File::ensureDirectoryExists(dirname($target));
        File::move($path, $target);

        $this->assertFileExists($target);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($target));
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        $this->assertSame(2, substr_count($xml, 'استمارة مزاولة فعلية'));
        $this->assertSame(1, substr_count($xml, '<w:br w:type="page"/>'));
        $this->assertStringContainsString('>1.83<', $xml);
        $this->assertStringNotContainsString('${', $xml);
    }
}

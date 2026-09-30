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
        $this->assertStringContainsString('20/5/2026', $xml);
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
        $this->assertStringContainsString('<w:t xml:space="preserve"></w:t>', $xml);   // a blank field cell
    }

    public function test_combined_document_has_one_page_block_per_attestation_in_name_order(): void
    {
        $b = $this->attestation('يوسف كامل', '290010154321');
        $a = $this->attestation('أحمد سالم', '290010112345');

        $xml = $this->documentXml(app(AttestationDocument::class)->combinedDocx(collect([$b, $a])));

        $this->assertSame(2, substr_count($xml, 'استمارة مزاولة فعلية'));
        $this->assertSame(1, substr_count($xml, '<w:pageBreakBefore/>'));
        $this->assertLessThan(mb_strpos($xml, 'يوسف كامل'), mb_strpos($xml, 'أحمد سالم'));
        $this->assertStringNotContainsString('${', $xml);
    }
}

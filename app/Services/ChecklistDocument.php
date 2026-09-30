<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ChecklistItem;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Language;

class ChecklistDocument
{
    public function __construct(private ApplicationWorkflow $workflow) {}

    public function build(Application $application, User $checker): string
    {
        Settings::setOutputEscapingEnabled(true);

        $i = $application->instructor;
        $checklist = $this->workflow->checklist($application);

        $word = new PhpWord;
        $word->setDefaultFontName('Arial');
        $word->setDefaultFontSize(11);
        $word->getSettings()->setThemeFontLang(new Language(null, null, 'ar-KW'));
        $section = $word->addSection(['marginTop' => Converter::cmToTwip(2), 'marginBottom' => Converter::cmToTwip(2)]);
        $rtl = ['bidi' => true, 'alignment' => Jc::START];
        $bold = ['bold' => true];

        $logo = public_path('img/paaet-logo.png');
        if (is_file($logo)) {
            $section->addImage($logo, ['width' => 60, 'alignment' => Jc::END]);
        }
        $section->addText('التاريخ : '.now()->format('Y/m/d'), [], $rtl);
        $section->addText('Check List', ['bold' => true, 'size' => 16], ['alignment' => Jc::CENTER]);
        $section->addText('ما يخص المستعان به للتدريس بكليات الهيئة', ['bold' => true, 'underline' => 'single', 'size' => 13], ['bidi' => true, 'alignment' => Jc::CENTER]);
        $section->addTextBreak();

        $rows = [
            ['الاسم الثلاثي للمستعان به (المنتدب)', $i->full_name],
            ['الرقم المدني', $i->civil_id, 'تاريخ انتهاء البطاقة المدنية', $i->civil_id_expires_on->format('Y/m/d')],
            ['المؤهل العلمي', $i->degree_title, 'تاريخ الحصول على المؤهل', $i->degree_obtained_on->format('Y/m/d')],
            ['جهة العمل', $i->employer, 'المسمى الوظيفي', $i->job_title],
            ['الكلية المنتدب إليها', __('app.college_name', [], 'ar'), 'القسم العلمي', __('app.dept_name', [], 'ar')],
            ['الفصل الدراسي', __('app.terms.types.'.$application->term->type, [], 'ar'), 'العام الدراسي', $application->term->academic_year],
        ];
        foreach ($rows as $r) {
            $line = $r[0].' : '.$r[1].(isset($r[2]) ? '        '.$r[2].' : '.$r[3] : '');
            $section->addText($line, [], $rtl);
        }
        $section->addTextBreak();
        $section->addText('❖ قائمة المستندات المطلوبة :', $bold + ['underline' => 'single'], $rtl);

        foreach (ChecklistItem::orderBy('sort_order')->get() as $item) {
            if ($item->isDepartment()) {
                $mark = '☐';
            } elseif (isset($checklist[$item->code])) {
                $mark = in_array($checklist[$item->code]['state'], ['accepted', ApplicationWorkflow::STATE_ON_FILE], true) ? '☑' : '☐';
            } else {
                $mark = '—';
            }
            $label = $item->label_ar.($item->note_ar ? ' ( '.$item->note_ar.' )' : '');
            if ($mark === '—') {
                $label .= ' — لا ينطبق';
            }
            $run = $section->addTextRun($rtl + ['indentation' => ['left' => Converter::cmToTwip(0.5)]]);
            $run->addText($mark, ['name' => 'Arial Unicode MS']);
            $run->addText(' '.$label.' .');
        }

        $section->addTextBreak();
        $section->addText('❖ تم التدقيق بواسطة :', $bold, $rtl);
        $section->addText('الاسم : '.$checker->name, [], $rtl);

        Storage::disk('local')->makeDirectory('generated');
        $path = Storage::disk('local')->path('generated/checklist-'.$application->id.'-'.Str::random(12).'.docx');
        IOFactory::createWriter($word, 'Word2007')->save($path);

        return $path;
    }
}

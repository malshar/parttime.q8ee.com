<?php

namespace Database\Seeders;

use App\Models\ChecklistItem;
use Illuminate\Database\Seeder;

class ChecklistItemSeeder extends Seeder
{
    public function run(): void
    {
        // [code, label, note, provided_by, condition, renews_each_term, stage, exemptable, official]
        $items = [
            ['schedule', 'الجدول الدراسي', null, 'department', 'always', false, 0, false, true],
            ['assignment_letter', 'كشف التكليف للمنتدب', null, 'department', 'always', false, 0, false, true],
            ['attestation', 'كشف المزاولة للمنتدب', null, 'department', 'always', false, 0, false, true],
            ['civil_id', 'صورة البطاقة المدنية سارية المفعول', null, 'applicant', 'always', false, 1, false, true],
            ['degree', 'صورة من المؤهل العلمي', null, 'applicant', 'always', false, 1, false, true],
            ['transcript_bachelor', 'كشف درجات البكالوريوس', null, 'applicant', 'always', false, 1, true, false],
            ['transcript_master', 'كشف درجات الماجستير', null, 'applicant', 'master_or_above', false, 1, true, false],
            ['equivalency', 'صورة من معادلة المؤهل العلمي', 'للمؤهلات الصادرة من خارج دولة الكويت', 'applicant', 'foreign_degree', false, 1, false, true],
            ['social_insurance', 'شهادة من المؤسسة العامة للتأمينات الاجتماعية', 'للعاملين في القطاع الخاص فقط', 'applicant', 'private_sector', true, 2, false, true],
            ['experience', 'صورة من شهادة الخبرة', 'لحملة شهادة البكالوريوس، لا تقل عن 10 سنوات', 'applicant', 'bachelor_only', false, 1, false, true],
            ['salary_cert', 'شهادة راتب حديثة', null, 'applicant', 'always', true, 2, false, true],
            ['iban', 'كشف الآيبان IBAN معتمد من البنك', null, 'applicant', 'always', false, 2, false, true],
            ['employer_approval', 'موافقة جهة العمل', 'موجهة لمدير عام الهيئة ومدون بها الفصل الدراسي والعام الدراسي', 'applicant', 'always', true, 2, false, true],
            ['undertaking', 'نموذج إقرار وتعهد', 'موقع من قبل المنتدب', 'applicant', 'always', true, 2, false, true],
        ];

        foreach ($items as $i => [$code, $label, $note, $by, $cond, $renews, $stage, $exemptable, $official]) {
            ChecklistItem::updateOrCreate(['code' => $code], [
                'label_ar' => $label, 'note_ar' => $note, 'sort_order' => $i + 1,
                'provided_by' => $by, 'condition' => $cond, 'renews_each_term' => $renews,
                'stage' => $stage, 'exemptable' => $exemptable, 'official' => $official,
                'optional' => false,   // 5b: optional rows are no longer seeded; the column stays for future use
            ]);
        }
    }
}

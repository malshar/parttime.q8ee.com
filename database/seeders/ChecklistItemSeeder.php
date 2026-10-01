<?php

namespace Database\Seeders;

use App\Models\ChecklistItem;
use Illuminate\Database\Seeder;

class ChecklistItemSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['schedule', 'الجدول الدراسي', null, 'department', 'always', false],
            ['assignment_letter', 'كشف التكليف للمنتدب', null, 'department', 'always', false],
            ['attestation', 'كشف المزاولة للمنتدب', null, 'department', 'always', false],
            ['civil_id', 'صورة البطاقة المدنية سارية المفعول', null, 'applicant', 'always', false],
            ['degree', 'صورة من المؤهل العلمي', null, 'applicant', 'always', false],
            ['equivalency', 'صورة من معادلة المؤهل العلمي', 'للمؤهلات الصادرة من خارج دولة الكويت', 'applicant', 'foreign_degree', false],
            ['social_insurance', 'شهادة من المؤسسة العامة للتأمينات الاجتماعية', 'للعاملين في القطاع الخاص فقط', 'applicant', 'private_sector', true],
            ['experience', 'صورة من شهادة الخبرة', 'لحملة شهادة البكالوريوس، لا تقل عن 10 سنوات', 'applicant', 'bachelor_only', false],
            ['salary_cert', 'شهادة راتب حديثة', null, 'applicant', 'always', true],
            ['iban', 'كشف الآيبان IBAN معتمد من البنك', null, 'applicant', 'always', false],
            ['employer_approval', 'موافقة جهة العمل', 'موجهة لمدير عام الهيئة ومدون بها الفصل الدراسي والعام الدراسي', 'applicant', 'always', true],
            ['undertaking', 'نموذج إقرار وتعهد', 'موقع من قبل المنتدب', 'applicant', 'always', true],
        ];

        foreach ($items as $i => [$code, $label, $note, $by, $cond, $renews]) {
            ChecklistItem::updateOrCreate(['code' => $code], [
                'label_ar' => $label, 'note_ar' => $note, 'sort_order' => $i + 1,
                'provided_by' => $by, 'condition' => $cond, 'renews_each_term' => $renews,
            ]);
        }
    }
}

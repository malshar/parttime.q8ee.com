<?php

namespace App\Support;

/** Reference lists for the profile forms (Arabic only: these are institution names, not UI copy). */
final class KuwaitLists
{
    /** Government agencies as listed in the Sahel app (2026-10-02 screenshots). */
    public const EMPLOYERS = [
        'وزارة الأشغال العامة', 'وزارة الإعلام', 'وزارة التجارة والصناعة', 'وزارة التربية', 'وزارة التعليم العالي',
        'وزارة الخارجية', 'وزارة الداخلية', 'وزارة الدفاع', 'وزارة الشؤون الإسلامية', 'وزارة الشؤون الاجتماعية',
        'وزارة الصحة', 'وزارة العدل', 'وزارة الكهرباء والماء والطاقة المتجددة', 'وزارة المالية', 'وزارة المواصلات',
        'الأمانة العامة للأوقاف', 'الإدارة العامة للجمارك', 'الديوان الوطني لحقوق الإنسان',
        'المؤسسة العامة للتأمينات الاجتماعية', 'المؤسسة العامة للرعاية السكنية', 'المركز الوطني للأمن السيبراني',
        'الهيئة العامة لشؤون القصر', 'الهيئة العامة لشؤون ذوي الإعاقة', 'الهيئة العامة للاتصالات وتقنية المعلومات',
        'الهيئة العامة للبيئة', 'الهيئة العامة للتعليم التطبيقي والتدريب', 'الهيئة العامة للشباب والرياضة',
        'الهيئة العامة للصناعة', 'الهيئة العامة للطيران المدني', 'الهيئة العامة للقوى العاملة',
        'الهيئة العامة لمكافحة الفساد (نزاهة)', 'الهيئة العامة للمعلومات المدنية', 'بلدية الكويت',
        'بنك الائتمان الكويتي', 'بيت الزكاة', 'جامعة الكويت', 'ديوان الخدمة المدنية', 'قوة الإطفاء العام',
    ];

    /** Banks operating in Kuwait, keyed by the 4-letter IBAN bank code where known ('fab' is a slug: its code is unconfirmed). */
    public const BANKS = [
        'NBOK' => 'بنك الكويت الوطني', 'CBKU' => 'البنك التجاري الكويتي', 'GULB' => 'بنك الخليج',
        'ABKK' => 'البنك الأهلي الكويتي', 'BRGN' => 'بنك برقان', 'KFHO' => 'بيت التمويل الكويتي',
        'BBYN' => 'بنك بوبيان', 'KWIB' => 'بنك الكويت الدولي', 'WRBA' => 'بنك وربة', 'IBKK' => 'بنك الكويت الصناعي',
        'BBKU' => 'بنك البحرين والكويت', 'fab' => 'بنك أبوظبي الأول', 'BBME' => 'بنك HSBC الشرق الأوسط',
        'CITI' => 'سيتي بنك', 'QNBA' => 'بنك قطر الوطني',
    ];

    public static function isEmployer(string $name): bool
    {
        return in_array($name, self::EMPLOYERS, true);
    }

    /** The bank name for a Kuwaiti IBAN (KWkk BBBB …), or null when the code is unknown. */
    public static function bankForIban(string $iban): ?string
    {
        $code = strtoupper(substr(preg_replace('/\s+/', '', $iban), 4, 4));

        return self::BANKS[$code] ?? null;
    }

    public static function bankKey(string $name): ?string
    {
        $key = array_search($name, self::BANKS, true);

        return $key === false ? null : (string) $key;
    }
}

# Milestone 5a — Feedback Round: PDF Fit, Lists, Session, Header, Sections — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Land the twelve bounded items from Dr. Mishal's 2026-10-02 feedback: the (خ-3) PDF fits one page per instructor with an unbroken footer; nationality, employer and bank become lists; forms keep their values after errors; an expired session goes to the login page; the header names the user; the admin sees the applicant's email and views documents in a pop-up; the section tables show and sort by the reference number and can be filtered; and the jadawil export gains the registered-seats columns so the student count fills in.

**Architecture:** Same Laravel 12 monolith. Reference lists live in one PHP class (`App\Support\KuwaitLists`); the two profile forms (instructor and admin) share a Blade partial for the employer and bank fields; a `Section::scopeFilter()` serves both the sections and the assignments pages; the template script gains table-fit and footer-merge steps; the session-expiry change is in the exception handler. The jadawil change is a separate repository with its own conventions.

**Tech Stack:** PHP ≥ 8.2, Laravel 12, Bootstrap 5.3 RTL (CDN, bundle JS already loaded by the layout — verify), PhpWord template, Python 3 build script, LibreOffice on the server. Jadawil: single-file `client/tools.html`, Node tests, `deploy/deploy.sh`.

**Design (binding):** the 5a design approved in conversation on 2026-10-02 (sections A–D), recorded in `docs/superpowers/reviews/` after the final review. No separate spec.

## Global Constraints

- Laravel 12; SQLite `:memory:` in tests; PHPUnit; TDD per task; Pint only on touched PHP files (never the whole `lang/` directory).
- Arabic-first RTL: every new UI string in `lang/ar/app.php` with an English twin in `lang/en/app.php`; formal undiacritized Arabic; no hard-coded UI strings. Institution and bank names are data, kept once in `App\Support\KuwaitLists` (Arabic only).
- No schema migration in this milestone: `nationality` keeps its `string(60)` column (now a 2-letter country code for new saves, legacy text tolerated), `employer`, `employer_sector`, `bank_name` keep their columns and meanings.
- Sensitive fields (`civil_id`, `iban`, `basic_salary`, `total_salary`) are never flashed back after a validation error (existing `dontFlash`); the forms say so.
- Admin-only routes stay behind the existing policies; the document pop-up uses the existing policy-checked `admin.documents.view` route (inline) — no new exposure.
- The generated (خ-3) must keep passing every existing `AttestationDocumentTest` and `AttestationGeneratorTest` assertion except those the plan changes explicitly (course line format).
- Commit after every task with the `Co-Authored-By:` trailer the environment specifies. Never read `../part-time/`.

## Review Focus

1. **A legacy profile whose `nationality` is free text ("كويتي") and whose `employer` is not in the agency list.** Expected: the edit forms open without error, pre-select "أخرى" with the stored text in the free-text field, and saving without touching those fields keeps the stored values. Pinned in Task 2.
2. **An IBAN whose bank code is unknown to the list.** Expected: no auto-selection, the bank select stays as the user set it, saving works. Pinned in Task 2.
3. **A five-week month with two courses per week and a two-line note.** Expected: still one page per instructor. Pinned in Task 1 (fixture) and verified on the server by the controller after deploy.
4. **A section filter that matches nothing, or a term with no sections.** Expected: an empty-state message, no error, filters preserved in the form. Pinned in Task 4.
5. **A document that is a `.docx`.** Expected: "View" is not offered as a pop-up; only download. Pinned in Task 3.

## File Structure

```
app/Support/KuwaitLists.php                                   (new: EMPLOYERS, BANKS with IBAN codes, helpers)
app/Http/Requests/ProfileRequest.php, AdminProfileRequest.php (employer/bank choice fields, nationality code)
app/Http/Controllers/Instructor/ProfileController.php, Admin/ProfileController.php (map choice → employer/sector/bank)
app/Models/Section.php                                        (scopeFilter)
app/Http/Controllers/Admin/SectionController.php, AssignmentController.php (filters + ordering)
app/Services/Attestations/AttestationGenerator.php           (course line "name code")
bootstrap/app.php                                             (TokenMismatch → login when guest)
scripts/build-kh3-template.py, resources/forms/kh3-template.docx (footer run merge, table fit)
resources/views/instructor/profile.blade.php, admin/applications/profile.blade.php
resources/views/instructor/_employer_bank_fields.blade.php    (new partial)
resources/views/layouts/app.blade.php, admin/layout.blade.php, instructor/layout.blade.php
resources/views/admin/applications/show.blade.php             (email, document modal)
resources/views/admin/sections/index.blade.php, admin/assignments/index.blade.php, admin/sections/_filters.blade.php (new)
lang/ar/app.php, lang/en/app.php
tests/Unit/KuwaitListsTest.php, tests/Unit/Attestations/* (updated), tests/Feature/Instructor/ProfileTest.php, tests/Feature/Admin/{AdminProfileEditTest,ReviewTest,SectionScreensTest}.php, tests/Feature/Auth/SessionExpiryTest.php
../../jadawil.q8ee.com/dev/client/tools.html                  (export seat columns; its own repo)
CLAUDE.md, PROGRESS.md, deploy/DEPLOY.md
```

---

### Task 1: (خ-3) template — footer word, one page per instructor, course lines

**Files:**
- Modify: `scripts/build-kh3-template.py`, `resources/forms/kh3-template.docx` (rebuilt), `app/Services/Attestations/AttestationGenerator.php`
- Test: `tests/Unit/Attestations/AttestationGeneratorTest.php` (course line assertions), `tests/Unit/Attestations/AttestationDocumentTest.php` (new assertions)

**Interfaces:**
- Consumes: the template pipeline from milestones 3–4 (`${page}` block, `${week_no}` row, `set_cell()`/`set_para()` helpers, `strip_underline`).
- Produces: `courses_text` lines formatted `"{course_name_ar} {course_code}"` (no parentheses); a template whose footer captions are single runs and whose schedule table uses 9 pt in the week and totals rows with fixed column widths.

- [ ] **Step 1: Write the failing tests**

In `AttestationGeneratorTest`, change every expectation of the form `'الدوائر الكهربائية (7230101)'` to `'الدوائر الكهربائية 7230101'` (and the two-course case to `"الرسم الهندسي 7210050\nالدوائر الكهربائية 7230101"`). Add to `AttestationDocumentTest`:

```php
    public function test_footer_captions_are_single_runs(): void
    {
        $zip = new \ZipArchive;
        $zip->open(resource_path('forms/kh3-template.docx'));
        $footer = $zip->getFromName('word/footer1.xml');
        $zip->close();
        $texts = [];
        preg_match_all('~<w:t[^>]*>([^<]*)</w:t>~', $footer, $m);
        $this->assertContains('توقيع عضو هيئة التدريس المنتدب', $m[1]);
        $this->assertNotContains('المنتد', $m[1]);
    }

    public function test_schedule_table_rows_use_nine_point_font_and_fixed_widths(): void
    {
        $zip = new \ZipArchive;
        $zip->open(resource_path('forms/kh3-template.docx'));
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        preg_match('~<w:tr\b[^>]*>(?:(?!<w:tr\b).)*?\$\{week_no\}.*?</w:tr>~s', $xml, $row);
        $this->assertNotEmpty($row);
        $this->assertStringContainsString('<w:sz w:val="18"/>', $row[0]);
        $this->assertStringNotContainsString('<w:sz w:val="2', $row[0]);   // no 10pt+ left in the row
        preg_match('~<w:tblGrid>.*?</w:tblGrid>~s', substr($xml, strrpos($xml, '<w:tbl>')), $grid);
        preg_match_all('~<w:gridCol w:w="(\d+)"/>~', $grid[0], $cols);
        $this->assertCount(9, $cols[1]);
        foreach ([4, 5, 6] as $hoursCol) {           // theory, practical, field columns (RTL order as in the XML)
            $this->assertGreaterThanOrEqual(850, (int) $cols[1][$hoursCol], "hours column $hoursCol too narrow");
        }
    }
```

(If the grid's column order in the XML differs from `names`, read it once with the script's `print` and adjust the indices; the intent is: the three hour columns and the total column are each at least 850 twips wide, the course column at least 2600, the notes column at most 2600.)

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Unit/Attestations`
Expected: FAIL on the course format and on the two new template tests.

- [ ] **Step 3: Generator**

In `AttestationGenerator::weeks()` the course line becomes `$s->course_name_ar.' '.$s->course_code`.

- [ ] **Step 4: Build script**

Add to `scripts/build-kh3-template.py`:

1. **Footer merge.** Read `word/footer1.xml`; for each paragraph, collect the texts of all runs, keep the paragraph's `pPr` and the first run's `rPr`, and write one run with the concatenated text (preserving the spaces between the three captions). Write the file back into the zip. The result must contain the single text `توقيع عضو هيئة التدريس المنتدب` followed by the spacing and the other two captions.
2. **Table fit.** In the schedule table (`tables[2]`): set `<w:tblGrid>` to nine `gridCol` values that sum to the current table width (read `<w:tblW>`), with at least: week number 500, dates 800, course 2800, students 900, theory 900, practical 900, field 900, total 900, notes 2400 (scale to the table width; keep the order the XML uses, which is the RTL visual order reversed — check with a print); set every cell's `<w:tcW>` in the week row and the totals row to its grid value; in the week row's and totals row's run properties set `<w:sz w:val="18"/>` and `<w:szCs w:val="18"/>`, replacing any existing `w:sz`/`w:szCs`; add `<w:tblCellMar>` with top and bottom margins of 20 twips if absent; remove any `<w:trHeight>` from the week row so rows shrink to content.
3. Keep every earlier transformation (placeholders, `${page}` block, the page-break paragraph at the end, note-cell underline removal).

Rebuild: `python3 scripts/build-kh3-template.py "<official blank docx path from the milestone 3 plan Task 5 Step 1>" resources/forms/kh3-template.docx`. Then print the placeholder list (unchanged, 50) and the grid widths.

- [ ] **Step 5: Sample for the server check**

Add a PHPUnit test `test_writes_sample_filled_document_for_visual_check` in `AttestationDocumentTest` that builds a two-instructor combined document for a five-week month with two courses each and a two-line note, and saves it to `storage/app/private/generated/sample-kh3.docx` (not deleted). The controller converts it on the server after deploy with `soffice` and checks `pdfinfo` reports 2 pages.

- [ ] **Step 6: Run the tests, commit**

Run: `php artisan test --compact` → PASS.

```bash
vendor/bin/pint app/Services/Attestations/AttestationGenerator.php tests/Unit/Attestations
git add scripts/build-kh3-template.py resources/forms/kh3-template.docx app/Services/Attestations/AttestationGenerator.php tests/Unit/Attestations
git commit -m "fix(attestations): one-page table fit, unbroken footer captions, course lines without parentheses"
```

---

### Task 2: Nationality, employer and bank lists; retained form values

**Files:**
- Create: `app/Support/KuwaitLists.php`, `resources/views/instructor/_employer_bank_fields.blade.php`, `tests/Unit/KuwaitListsTest.php`
- Modify: `app/Http/Requests/ProfileRequest.php`, `app/Http/Requests/AdminProfileRequest.php`, `app/Http/Controllers/Instructor/ProfileController.php`, `app/Http/Controllers/Admin/ProfileController.php`, `resources/views/instructor/profile.blade.php`, `resources/views/admin/applications/profile.blade.php`, `resources/views/auth/register.blade.php`, `app/Models/Instructor.php` (`nationalityLabel()`), views that print nationality (grep `->nationality`), `lang/ar/app.php`, `lang/en/app.php`
- Test: `tests/Feature/Instructor/ProfileTest.php`, `tests/Feature/Admin/AdminProfileEditTest.php` (additions)

**Interfaces:**
- Produces: `KuwaitLists::EMPLOYERS` (list of Arabic agency names, in the order below), `KuwaitLists::BANKS` (`['NBOK' => 'بنك الكويت الوطني', …]` keyed by IBAN bank code where known, otherwise by a slug), `KuwaitLists::bankForIban(string $iban): ?string`, `KuwaitLists::isEmployer(string $name): bool`; request fields `employer_choice` (`agency index | private | other`), `employer_other`, `bank_choice` (`bank key | other`), `bank_other`; `Instructor::nationalityLabel(): string`.

- [ ] **Step 1: Lists**

`app/Support/KuwaitLists.php`:

```php
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

    /** Banks operating in Kuwait, keyed by the 4-letter IBAN bank code where known. */
    public const BANKS = [
        'NBOK' => 'بنك الكويت الوطني', 'CBKU' => 'البنك التجاري الكويتي', 'GULB' => 'بنك الخليج',
        'ABKK' => 'البنك الأهلي الكويتي', 'BRGN' => 'بنك برقان', 'KFHO' => 'بيت التمويل الكويتي',
        'BBYN' => 'بنك بوبيان', 'KWIB' => 'بنك الكويت الدولي', 'WRBA' => 'بنك وربة', 'IBKK' => 'بنك الكويت الصناعي',
        'bbk' => 'بنك البحرين والكويت', 'fab' => 'بنك أبوظبي الأول', 'hsbc' => 'بنك HSBC الشرق الأوسط',
        'citi' => 'سيتي بنك', 'qnb' => 'بنك قطر الوطني',
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
```

`tests/Unit/KuwaitListsTest.php`: `bankForIban('KW81CBKU0000000000001234560101')` → `'البنك التجاري الكويتي'`; an unknown code → null; `isEmployer('وزارة الصحة')` true, `isEmployer('شركة')` false; `bankKey('بنك وربة')` → `'WRBA'`; `EMPLOYERS` has no duplicates.

- [ ] **Step 2: Write the failing feature tests**

Append to `tests/Feature/Instructor/ProfileTest.php` (use its existing helpers for a logged-in instructor and a valid payload; the current valid payload posts `employer`, `employer_sector`, `bank_name`, `nationality` — replace them in a new `$payload` builder):

```php
    public function test_agency_choice_sets_employer_and_government_sector(): void
    {
        $r = $this->actingAs($this->user)->put(route('instructor.profile.update'), $this->payload([
            'employer_choice' => 'وزارة الصحة', 'employer_other' => '', 'nationality' => 'KW',
            'bank_choice' => 'NBOK', 'bank_other' => '',
        ]));
        $r->assertSessionHasNoErrors();
        $i = $this->user->instructor->fresh();
        $this->assertSame('وزارة الصحة', $i->employer);
        $this->assertSame('government', $i->employer_sector);
        $this->assertSame('بنك الكويت الوطني', $i->bank_name);
        $this->assertSame('KW', $i->nationality);
        $this->assertSame(__('app.countries.KW'), $i->nationalityLabel());
    }

    public function test_private_and_other_choices_require_a_name_and_keep_the_sector(): void
    {
        $this->actingAs($this->user)->put(route('instructor.profile.update'), $this->payload([
            'employer_choice' => 'private', 'employer_other' => '', 'bank_choice' => 'other', 'bank_other' => '',
        ]))->assertSessionHasErrors(['employer_other', 'bank_other']);

        $this->actingAs($this->user)->put(route('instructor.profile.update'), $this->payload([
            'employer_choice' => 'private', 'employer_other' => 'شركة الخليج للكابلات', 'bank_choice' => 'other', 'bank_other' => 'بنك آخر',
        ]))->assertSessionHasNoErrors();
        $i = $this->user->instructor->fresh();
        $this->assertSame('شركة الخليج للكابلات', $i->employer);
        $this->assertSame('private', $i->employer_sector);
        $this->assertSame('بنك آخر', $i->bank_name);

        $this->actingAs($this->user)->put(route('instructor.profile.update'), $this->payload([
            'employer_choice' => 'other', 'employer_other' => 'جمعية تعاونية', 'employer_sector' => 'government',
        ]))->assertSessionHasNoErrors();
        $this->assertSame('government', $this->user->instructor->fresh()->employer_sector);
    }

    public function test_legacy_values_preselect_other_and_survive_an_untouched_save(): void
    {
        $this->user->instructor->update(['nationality' => 'كويتي', 'employer' => 'شركة قديمة', 'employer_sector' => 'private', 'bank_name' => 'بنك قديم']);

        $r = $this->actingAs($this->user)->get(route('instructor.profile.edit'))->assertOk();
        $r->assertSee('<option value="other" selected', false);
        $r->assertSee('value="شركة قديمة"', false);
        $r->assertSee('value="بنك قديم"', false);
        $this->assertSame('كويتي', $this->user->instructor->fresh()->nationalityLabel());
    }

    public function test_non_sensitive_values_are_retained_after_a_validation_error(): void
    {
        $r = $this->actingAs($this->user)->from(route('instructor.profile.edit'))->put(route('instructor.profile.update'), $this->payload([
            'mobile' => '12', 'full_name' => 'اسم للاختبار', 'employer_choice' => 'وزارة العدل', 'bank_choice' => 'WRBA', 'nationality' => 'SA',
        ]))->assertRedirect(route('instructor.profile.edit'))->assertSessionHasErrors('mobile');

        $page = $this->actingAs($this->user)->get(route('instructor.profile.edit'));
        $page->assertSee('value="اسم للاختبار"', false);
        $page->assertSee('<option value="وزارة العدل" selected', false);
        $page->assertSee('<option value="WRBA" selected', false);
        $page->assertSee('<option value="SA" selected', false);
        $page->assertDontSee($this->user->instructor->iban);
        $page->assertSee(__('app.profile.sensitive_reenter'));
    }

    public function test_unknown_iban_bank_code_does_not_block_saving(): void
    {
        $this->actingAs($this->user)->put(route('instructor.profile.update'), $this->payload([
            'iban' => 'KW50ZZZZ0000000000001234560101', 'bank_choice' => 'GULB',
        ]))->assertSessionDoesntHaveErrors(['bank_choice', 'iban']);
    }
```

(The IBAN in the last test must pass the `Iban` rule's checksum: compute a valid one with bank code `ZZZZ` the way the earlier controller message did, or pick any IBAN with an unknown code that satisfies mod-97; if `KW50ZZZZ…` fails the checksum, change the two check digits until it passes.)

Append to `tests/Feature/Admin/AdminProfileEditTest.php` one test that the admin form shows the same selects and that an agency choice maps the same way.

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Unit/KuwaitListsTest.php tests/Feature/Instructor/ProfileTest.php tests/Feature/Admin/AdminProfileEditTest.php`
Expected: FAIL.

- [ ] **Step 4: Requests and controllers**

`ProfileRequest::rules()` (and `AdminProfileRequest`, which extends or mirrors it — keep the override of `ownerUserId()`):

```php
            'nationality' => ['required', 'string', Rule::in(array_keys(__('app.countries')))],
            'employer_choice' => ['required', 'string', 'max:150'],
            'employer_other' => ['nullable', 'string', 'max:150', Rule::requiredIf(fn () => in_array($this->employer_choice, ['private', 'other'], true))],
            'employer_sector' => ['nullable', Rule::in(Instructor::SECTORS), Rule::requiredIf(fn () => $this->employer_choice === 'other')],
            'bank_choice' => ['required', 'string', 'max:120'],
            'bank_other' => ['nullable', 'string', 'max:120', Rule::requiredIf(fn () => $this->bank_choice === 'other')],
```

(remove the old `employer` and `bank_name` rules). Add to the request:

```php
    /** The attributes to store, derived from the choice fields (spec of the 5a design, section B). */
    public function profileAttributes(): array
    {
        $v = $this->validated();
        $choice = $v['employer_choice'];
        if ($choice === 'private') {
            $employer = $v['employer_other']; $sector = 'private';
        } elseif ($choice === 'other') {
            $employer = $v['employer_other']; $sector = $v['employer_sector'];
        } elseif (KuwaitLists::isEmployer($choice)) {
            $employer = $choice; $sector = 'government';
        } else {
            throw ValidationException::withMessages(['employer_choice' => __('app.profile.employer_choice_invalid')]);
        }
        $bank = $v['bank_choice'] === 'other' ? $v['bank_other'] : (KuwaitLists::BANKS[$v['bank_choice']] ?? null);
        if ($bank === null) {
            throw ValidationException::withMessages(['bank_choice' => __('app.profile.bank_choice_invalid')]);
        }
        unset($v['employer_choice'], $v['employer_other'], $v['bank_choice'], $v['bank_other']);

        return $v + ['employer' => $employer, 'employer_sector' => $sector, 'bank_name' => $bank];
    }
```

Both controllers' `update()` use `$request->profileAttributes()` where they used `$request->validated()` (the admin controller's changed-field diff then compares these attributes). `Instructor::nationalityLabel()`: `return strlen($this->nationality) === 2 ? __('app.countries.'.$this->nationality) : (string) $this->nationality;` — use it wherever nationality is displayed (grep `->nationality`).

- [ ] **Step 5: Views**

`resources/views/instructor/_employer_bank_fields.blade.php` (receives `$instructor`):

```blade
@php
    $employerChoice = old('employer_choice', \App\Support\KuwaitLists::isEmployer((string) $instructor->employer) ? $instructor->employer : ($instructor->employer ? ($instructor->employer_sector === 'private' ? 'private' : 'other') : ''));
    $bankChoice = old('bank_choice', \App\Support\KuwaitLists::bankKey((string) $instructor->bank_name) ?? ($instructor->bank_name ? 'other' : ''));
@endphp
<div class="row">
    <div class="col-md-6 mb-3"><label class="form-label" for="employer_choice">{{ __('app.profile.employer') }}</label>
        <select id="employer_choice" name="employer_choice" class="form-select" required data-toggle-other="employer_other_wrap" data-toggle-sector="employer_sector_wrap">
            <option value="">{{ __('app.common.choose') }}</option>
            @foreach (\App\Support\KuwaitLists::EMPLOYERS as $name)
                <option value="{{ $name }}" @selected($employerChoice === $name)>{{ $name }}</option>
            @endforeach
            <option value="private" @selected($employerChoice === 'private')>{{ __('app.profile.employer_private') }}</option>
            <option value="other" @selected($employerChoice === 'other')>{{ __('app.profile.employer_other') }}</option>
        </select></div>
    <div class="col-md-6 mb-3" id="employer_other_wrap"><label class="form-label">{{ __('app.profile.employer_name') }}</label>
        <input name="employer_other" value="{{ old('employer_other', in_array($employerChoice, ['private', 'other'], true) ? $instructor->employer : '') }}" class="form-control"></div>
    <div class="col-md-6 mb-3" id="employer_sector_wrap"><label class="form-label">{{ __('app.profile.employer_sector') }}</label>
        <select name="employer_sector" class="form-select">
            @foreach (\App\Models\Instructor::SECTORS as $sector)
                <option value="{{ $sector }}" @selected(old('employer_sector', $instructor->employer_sector) === $sector)>{{ __('app.profile.sectors.'.$sector) }}</option>
            @endforeach
        </select></div>
</div>
<div class="row">
    <div class="col-md-6 mb-3"><label class="form-label" for="bank_choice">{{ __('app.profile.bank_name') }}</label>
        <select id="bank_choice" name="bank_choice" class="form-select" required data-toggle-other="bank_other_wrap">
            <option value="">{{ __('app.common.choose') }}</option>
            @foreach (\App\Support\KuwaitLists::BANKS as $key => $name)
                <option value="{{ $key }}" @selected($bankChoice === (string) $key)>{{ $name }}</option>
            @endforeach
            <option value="other" @selected($bankChoice === 'other')>{{ __('app.profile.bank_other') }}</option>
        </select></div>
    <div class="col-md-6 mb-3" id="bank_other_wrap"><label class="form-label">{{ __('app.profile.bank_other_name') }}</label>
        <input name="bank_other" value="{{ old('bank_other', $bankChoice === 'other' ? $instructor->bank_name : '') }}" class="form-control"></div>
</div>
<script>
(function () {
    function toggle(select) {
        var other = document.getElementById(select.dataset.toggleOther);
        if (other) other.classList.toggle('d-none', !['private', 'other'].includes(select.value));
        var sector = select.dataset.toggleSector ? document.getElementById(select.dataset.toggleSector) : null;
        if (sector) sector.classList.toggle('d-none', select.value !== 'other');
    }
    document.querySelectorAll('select[data-toggle-other]').forEach(function (s) { toggle(s); s.addEventListener('change', function () { toggle(s); }); });
    var iban = document.querySelector('input[name="iban"]'), bank = document.getElementById('bank_choice');
    var codes = @json(array_keys(\App\Support\KuwaitLists::BANKS));
    if (iban && bank) iban.addEventListener('input', function () {
        var code = iban.value.replace(/\s+/g, '').substring(4, 8).toUpperCase();
        if (codes.includes(code)) bank.value = code;
    });
})();
</script>
```

Replace the employer, sector and bank fields in both profile forms with `@include('instructor._employer_bank_fields', ['instructor' => $instructor])`; the bank branch field stays. Nationality becomes a select over `__('app.countries')` with `@selected(old('nationality', strlen($instructor->nationality) === 2 ? $instructor->nationality : 'ZZ') === $code)`; when the stored value is legacy text, show it in a small muted line under the select (`app.profile.nationality_legacy` with `:value`). Under each sensitive input add `<div class="form-text">{{ __('app.profile.sensitive_reenter') }}</div>` shown only when `old()` has any input (`@if (old())`). Audit every other field on both forms and the registration form for `old()` (name, email) and add it where missing.

Lang (ar / en): `common.choose` "اختر…" / "Choose…" (if a `choose` key already exists under another block, reuse it); `profile.employer_private` "القطاع الخاص" / "Private sector"; `profile.employer_other` "أخرى" / "Other"; `profile.employer_name` "اسم جهة العمل" / "Employer name"; `profile.bank_other` "بنك آخر" / "Other bank"; `profile.bank_other_name` "اسم البنك" / "Bank name"; `profile.employer_choice_invalid` "اختر جهة العمل من القائمة." / "Choose the employer from the list."; `profile.bank_choice_invalid` "اختر البنك من القائمة." / "Choose the bank from the list."; `profile.sensitive_reenter` "لأسباب أمنية لا تحفظ هذه الحقول عند حدوث خطأ؛ أعد إدخالها." / "For security these fields are not kept after an error; enter them again."; `profile.nationality_legacy` "القيمة المحفوظة: :value" / "Stored value: :value".

- [ ] **Step 6: Run the tests, commit**

Run: `php artisan test --compact` → PASS (the milestone 1–4 profile tests that post `employer`/`bank_name` must be updated to the choice fields — do that in the same commit, keeping their assertions).

```bash
vendor/bin/pint app tests/Unit/KuwaitListsTest.php tests/Feature/Instructor/ProfileTest.php tests/Feature/Admin/AdminProfileEditTest.php
git add app resources lang tests
git commit -m "feat(profile): nationality, employer and bank lists; values retained after errors"
```

---

### Task 3: Session expiry to login, header user, applicant email, document pop-up

**Files:**
- Modify: `bootstrap/app.php`, `resources/views/layouts/app.blade.php`, `resources/views/instructor/layout.blade.php`, `resources/views/admin/applications/show.blade.php`, `lang/ar/app.php`, `lang/en/app.php`
- Test: `tests/Feature/Auth/SessionExpiryTest.php` (new), `tests/Feature/Admin/ReviewTest.php` (additions), `tests/Feature/Instructor/ApplicationTest.php` (header test)

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Auth/SessionExpiryTest.php`:

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionExpiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_session_post_redirects_guest_to_login_with_message(): void
    {
        $this->withMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $r = $this->post(route('logout'), ['_token' => 'stale']);

        $r->assertRedirect(route('login'))->assertSessionHasErrors(['email' => __('app.auth.session_expired')]);
    }

    public function test_token_mismatch_while_logged_in_still_returns_to_the_form(): void
    {
        $this->withMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $user = User::factory()->instructor()->create();
        $r = $this->actingAs($user)->from(route('instructor.home'))->post(route('logout'), ['_token' => 'stale']);

        $r->assertRedirect(route('instructor.home'))->assertSessionHasErrors('page_expired');
    }
}
```

(The CSRF middleware is disabled in feature tests by default; `withMiddleware` re-enables it for these two. If `logout` is not a POST route in this app, use any POST route a guest can hit, e.g. `password.email`.)

Header tests: in `tests/Feature/Instructor/ApplicationTest.php` assert the instructor's home page shows the user's name and `__('app.auth.roles.instructor')` and a link to `route('instructor.profile.edit')`; in `tests/Feature/Admin/ReviewTest.php` assert the admin application page shows the applicant's email inside a `mailto:` link, shows `__('app.auth.roles.admin')` in the header, and that a PDF document row has a `data-doc-url` attribute while a `.docx` document row (create one with `mime` `application/vnd.openxmlformats-officedocument.wordprocessingml.document`) does not.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Auth/SessionExpiryTest.php tests/Feature/Admin/ReviewTest.php tests/Feature/Instructor/ApplicationTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

`bootstrap/app.php` — the `TokenMismatchException` renderer:

```php
        $exceptions->render(function (\Illuminate\Session\TokenMismatchException $e, \Illuminate\Http\Request $request) {
            if (! $request->user()) {
                return redirect()->route('login')->withErrors(['email' => __('app.auth.session_expired')]);
            }

            return redirect()->back()
                ->withInput($request->except(['_token', 'password', 'password_confirmation', 'civil_id', 'iban', 'basic_salary', 'total_salary']))
                ->withErrors(['page_expired' => __('app.common.page_expired')]);
        });
```

`resources/views/layouts/app.blade.php` — inside the navbar, after `@yield('nav')`:

```blade
            @auth
                <span class="navbar-text text-white small ms-auto">
                    {{ auth()->user()->name }}
                    <span class="badge bg-light text-dark">{{ __('app.auth.roles.'.auth()->user()->role) }}</span>
                </span>
            @endauth
```

`resources/views/instructor/layout.blade.php` — add a nav link to `route('instructor.profile.edit')` labelled `__('app.profile.title')` (reuse the existing key for the profile page title).

`resources/views/admin/applications/show.blade.php` — in the instructor details block add `<div class="col-md-4 mb-2"><strong>{{ __('app.auth.email') }}:</strong> <a href="mailto:{{ $instructor->user->email }}" dir="ltr">{{ $instructor->user->email }}</a></div>` (reuse the existing email label key if one exists). Document rows: when `$file` is a PDF or image (`$file->mime === 'application/pdf' || $file->isImage()`), render the view link as `<a href="{{ route('admin.documents.view', $file) }}" data-doc-url="{{ route('admin.documents.view', $file) }}" data-bs-toggle="modal" data-bs-target="#docModal">{{ __('app.review.view') }}</a>`; otherwise omit the view link (download only). Add once at the bottom of the page a Bootstrap modal `#docModal` (`modal-xl`, body with `<iframe id="docFrame" class="w-100" style="height:80vh" title="{{ __('app.review.view') }}"></iframe>`) and a small script that on `show.bs.modal` sets the iframe `src` from the trigger's `data-doc-url` and clears it on hide. Confirm `layouts/app.blade.php` loads the Bootstrap bundle JS; add the CDN script if it does not.

Lang (ar / en): `auth.session_expired` "انتهت الجلسة، يرجى تسجيل الدخول من جديد." / "Your session expired; please sign in again."; `auth.roles.admin` "مسؤول" / "Admin"; `auth.roles.instructor` "منتدب" / "Instructor".

- [ ] **Step 4: Run the tests, commit**

Run: `php artisan test --compact` → PASS.

```bash
vendor/bin/pint bootstrap/app.php tests/Feature/Auth/SessionExpiryTest.php tests/Feature/Admin/ReviewTest.php tests/Feature/Instructor/ApplicationTest.php
git add bootstrap resources lang tests
git commit -m "feat(ux): expired session goes to login, header shows the user, applicant email, document pop-up"
```

---

### Task 4: Sections and assignments — reference number, ordering, filters

**Files:**
- Create: `resources/views/admin/sections/_filters.blade.php`
- Modify: `app/Models/Section.php` (`scopeFilter`, `scopeOrderedByReference`), `app/Http/Controllers/Admin/SectionController.php`, `app/Http/Controllers/Admin/AssignmentController.php`, `resources/views/admin/sections/index.blade.php`, `resources/views/admin/assignments/index.blade.php`, `lang/ar/app.php`, `lang/en/app.php`
- Test: `tests/Feature/Admin/SectionScreensTest.php` (new or existing section screen test class)

- [ ] **Step 1: Write the failing tests**

```php
    public function test_sections_are_ordered_by_reference_number_with_missing_last_and_show_it(): void
    {
        $term = Term::factory()->open()->create();
        $b = Section::factory()->for($term)->create(['course_code' => '7230999', 'reference_number' => '20002']);
        $a = Section::factory()->for($term)->create(['course_code' => '7230001', 'reference_number' => '20001']);
        $none = Section::factory()->for($term)->create(['course_code' => '7230000', 'reference_number' => null]);

        $html = $this->actingAs($this->admin)->get(route('admin.sections.index', ['term' => $term->id]))->assertOk()->getContent();

        $this->assertLessThan(strpos($html, '20002'), strpos($html, '20001'));
        $this->assertLessThan(strpos($html, '7230000'), strpos($html, '20002'));
        $this->assertStringContainsString(__('app.sections.reference'), $html);
    }

    public function test_filters_by_reference_course_name_and_instructor_on_both_pages(): void
    {
        $term = Term::factory()->open()->create();
        $s1 = Section::factory()->for($term)->create(['course_code' => '7230101', 'course_name_ar' => 'الدوائر الكهربائية', 'reference_number' => '30001', 'scheduled_instructor' => 'سعد فهد']);
        $s2 = Section::factory()->for($term)->create(['course_code' => '7240202', 'course_name_ar' => 'الآلات الكهربائية', 'reference_number' => '30002']);
        $app = Application::factory()->approved()->for($term)->for(Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'نورة علي']))->create();
        Assignment::factory()->for($app)->for($s2)->create();

        foreach (['admin.sections.index', 'admin.assignments.index'] as $route) {
            $this->actingAs($this->admin)->get(route($route, ['term' => $term->id, 'reference' => '30001']))->assertSee('7230101')->assertDontSee('7240202');
            $this->actingAs($this->admin)->get(route($route, ['term' => $term->id, 'course' => '724']))->assertSee('7240202')->assertDontSee('7230101');
            $this->actingAs($this->admin)->get(route($route, ['term' => $term->id, 'name' => 'الآلات']))->assertSee('7240202')->assertDontSee('7230101');
            $this->actingAs($this->admin)->get(route($route, ['term' => $term->id, 'instructor' => 'سعد']))->assertSee('7230101')->assertDontSee('7240202');
            $this->actingAs($this->admin)->get(route($route, ['term' => $term->id, 'instructor' => 'نورة']))->assertSee('7240202')->assertDontSee('7230101');
            $this->actingAs($this->admin)->get(route($route, ['term' => $term->id, 'reference' => '99999']))->assertOk()->assertSee(__('app.sections.no_matches'))->assertSee('value="99999"', false);
        }
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Admin/SectionScreensTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

`app/Models/Section.php`:

```php
    /** @param  array{reference?: ?string, course?: ?string, name?: ?string, instructor?: ?string}  $f */
    public function scopeFilter(Builder $q, array $f): Builder
    {
        return $q
            ->when($f['reference'] ?? null, fn ($q, $v) => $q->where('reference_number', 'like', $v.'%'))
            ->when($f['course'] ?? null, fn ($q, $v) => $q->where('course_code', 'like', $v.'%'))
            ->when($f['name'] ?? null, fn ($q, $v) => $q->where('course_name_ar', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $v).'%'))
            ->when($f['instructor'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w
                ->where('scheduled_instructor', 'like', '%'.$v.'%')
                ->orWhereHas('assignment.application.instructor', fn ($i) => $i->where('full_name', 'like', '%'.$v.'%'))));
    }

    public function scopeOrderedByReference(Builder $q): Builder
    {
        return $q->orderByRaw('reference_number is null')->orderBy('reference_number')->orderBy('course_code')->orderBy('section_number');
    }
```

Both controllers: `$filters = $request->only(['reference', 'course', 'name', 'instructor']);` → `->filter($filters)->orderedByReference()` (replace the current `course`/`unassigned` handling in `SectionController`; keep the `unassigned` checkbox if the view has it), pass `$filters` to the view. `_filters.blade.php`: a GET form with the term select (existing), four text inputs (`reference`, `course`, `name`, `instructor`) prefilled from `$filters`, a submit button and a "clear" link; include it in both index views in place of their current term form. Add a `reference` column (first data column) to both tables. Empty state: `app.sections.no_matches` when filters are set and the result is empty.

Lang (ar / en): `sections.reference` "الرقم المرجعي" / "Reference"; `sections.filter_name` "اسم المقرر" (reuse `sections.course_name`), `sections.filter_instructor` "المدرس" / "Instructor"; `sections.filter` "تصفية" / "Filter"; `sections.clear` "مسح" / "Clear"; `sections.no_matches` "لا توجد شعب مطابقة." / "No matching sections."

- [ ] **Step 4: Run the tests, commit**

Run: `php artisan test --compact` → PASS (existing assignment/section screen tests must still pass; adjust only their setup if the new ordering changes an index-based assertion).

```bash
vendor/bin/pint app/Models/Section.php app/Http/Controllers/Admin/SectionController.php app/Http/Controllers/Admin/AssignmentController.php tests/Feature/Admin/SectionScreensTest.php
git add app resources lang tests
git commit -m "feat(sections): reference number column and ordering; filters on sections and assignments"
```

---

### Task 5: jadawil export — registered-seats columns (separate repository)

**Files (in `/Users/malshar/Library/Mobile Documents/com~apple~CloudDocs/projects/jadawil.q8ee.com/dev`):**
- Modify: `client/tools.html` (the timetable CSV/XLSX export whose headers are `['رقم المقرر','اسم المقرر','النوع','النشاط','من','الى','المبنى','القاعة','الأيام','المدرس','الرقم المرجعي','الشعبة']`, around line 4261), `CLAUDE.md` version line if the project bumps versions in it
- Test: `test/` (run the project's existing `npm test`)

**Interfaces:**
- Produces: three more columns at the end of that export, headers exactly `الحد الأقصى`, `مسجلة`, `متبقية`, values from the section's registrar seat fields (the table render around line 1821 uses `e.regSeats`; find the sibling fields for maximum and remaining), empty when unknown. `parttime.q8ee.com`'s importer already reads these headers.

- [ ] **Step 1: Read that repository's `CLAUDE.md` first** (conventions, version bump in the commit message, deploy via `deploy/deploy.sh`). Do not change anything else in the file.
- [ ] **Step 2:** Add the three headers and the three values per exported row; keep the other export (the attendance/instructor list with `الرقم المدني`) untouched.
- [ ] **Step 3:** Run `npm test` in `test/` (or the project's documented test command); open the tool locally and export once to confirm the header row.
- [ ] **Step 4:** Commit in that repository with its version convention (patch bump), e.g. `feat(export): seat columns (الحد الأقصى، مسجلة، متبقية) in the timetable export — v2.4.13`, with the `Co-Authored-By:` trailer. Do not deploy; the controller deploys with the project's `deploy/deploy.sh` after review.

---

### Task 6: Docs

**Files:** `CLAUDE.md`, `PROGRESS.md`, `deploy/DEPLOY.md`

- [ ] CLAUDE.md status header: milestone 5a (feedback round) on branch `milestone-5a-feedback`; mention the lists class and the jadawil export dependency for the student count.
- [ ] PROGRESS.md: status, "Next" (deploy 5a + jadawil; re-export and re-import the term; verify the one-page PDF on the server; then 5b: two-stage documents, transcript and exemptions, salary timing, multi-file upload), log line 2026-10-02 listing the eighteen feedback items and which ones 5a covers.
- [ ] DEPLOY.md "Routine per-term setup": the term import needs a jadawil export that includes the seat columns (v2.4.13 or later) for the student count on (خ-3).
- [ ] Commit: `docs: milestone 5a status and deploy notes`.

---

## Self-review notes (done while writing)

- Design coverage: A (items 16–18) → Task 1 + Task 5; B (1, 2, 3, 5) → Task 2; C (8, 9, 10, 11) → Task 3; D (14, 15) → Task 4; docs → Task 6.
- Type consistency: `employer_choice`/`employer_other`/`bank_choice`/`bank_other` in Task 2's request, partial and tests; `KuwaitLists::BANKS` keys used by the partial's JS and `bankKey()`; `scopeFilter` filter keys match the form field names and the tests in Task 4.
- Review Focus pins: #1 → Task 2 legacy test; #2 → Task 2 unknown-code test; #3 → Task 1 sample + controller's server check; #4 → Task 4 empty-state assertion; #5 → Task 3 docx-row assertion.

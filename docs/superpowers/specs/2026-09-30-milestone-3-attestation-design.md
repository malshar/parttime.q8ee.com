# parttime.q8ee.com — Milestone 3 Design: Monthly (خ-3) Attestation

Date: 2026-09-30
Owner: Dr. Mishal E. AlSharidah
Status: approved in conversation; awaiting review of this written spec
Extends: `2026-09-28-parttime-system-design.md` (system spec) and
`2026-09-29-milestone-2-assignment-design.md` (milestone 2). Where this
document and the system spec differ, this document wins for milestone 3.

## 1. Purpose

Milestones 1 and 2 are live at parttime.q8ee.com: an approved application
carries the instructor's verified profile, the committee's appointment
decision, and the sections assigned from the term's timetable. Milestone 3
turns that into the monthly **استمارة مزاولة فعلية لعضو هيئة تدريس منتدب
(نموذج خ-3)** — the official PAAET form transcribed in
`docs/forms/actual-practice-form.md` — generated per instructor per month
from the timetable and the term calendar, editable by the admin, and
exported as Word and PDF for signatures.

The form is produced by the department and signed on paper by the
instructor, the head of department and the dean. It is not a self-service
document: the instructor has no screen in this milestone.

## 2. Decisions (locked 2026-09-30)

1. **Admin generates; instructor signs on paper.** No instructor
   confirmation step, no instructor email, no scheduler. The system-spec
   items "instructor is emailed … confirms" and "not confirmed within 7 days"
   are dropped. Attestations are generated on request from the admin area.
2. **One instructor category.** The title line "(من خارج الهيئة - كادر عام)"
   is a single lang key, not a per-instructor field.
3. **Exports:** one Word and one PDF per instructor per month, plus one
   combined PDF per month with every instructor's page, for printing in one
   go. PDF via LibreOffice on the server (present: 7.3, Amiri fonts).
4. **Scope:** attestation only. Term close and the returning-instructor
   "على الملف" logic move to milestone 4.
5. **Stored, not computed on the fly.** Each generated month is stored with
   its week rows so what was printed is on record, can be reprinted
   identically, and can be adjusted by the admin (student counts, hours,
   notes) with the generated values kept beside the edits.
6. Weekly load stays `weekly_minutes` (milestone 2); the form derives hours.

## 3. Data model

### attestations (مزاولة) — one per application per month

- `application_id` (FK, `restrictOnDelete`), `year` (int), `month` (1–12);
  unique `(application_id, year, month)`
- `status` enum: `generated` | `exported`
- `generated_at`, `generated_by` (users FK, nullable), `exported_at`
- `admin_note` (text, nullable; printed nowhere, admin memo)
- timestamps

### attestation_weeks

- `attestation_id` (FK, `cascadeOnDelete`), `week_number` (1–6)
- `date_from`, `date_to` (dates: first and last calendar day of the
  Sunday–Thursday block, clipped to the month and to the term's teaching
  window)
- `working_days` (JSON array of `Y-m-d`: the block's days that are teaching
  days, i.e. Sunday–Thursday, inside the window, not a holiday)
- editable columns, each with a `generated_` twin holding the generator's
  value: `courses_text` (text), `student_count` (int), `theory_minutes`,
  `practical_minutes`, `field_minutes` (int), `note_ar` (text)
- unique `(attestation_id, week_number)`

Derived, never stored: total minutes per week, monthly totals, hour labels.

No new instructor or application columns. The header fields come from:
`Instructor` (`full_name`, `civil_id`, `job_title`, `employer`, `iban`,
`bank_name`, `bank_branch`, `basic_salary`, `total_salary`, `work_phone`,
`home_phone`, `mobile`), `Application` (`assignment_decision_number`,
`assignment_decision_date`, `weekly_minutes`), `Term` (`type`,
`academic_year`, teaching window, holidays), and lang keys
(`app.dept_name`, `app.college_name`, new `app.kh3.category`).

### Months of a term

The term's months are the calendar months from `teaching_starts_on` to
`teaching_ends_on` inclusive, indexed 1..n. Month title = "الشهر
{الأول|الثاني|الثالث|الرابع|الخامس|السادس}/ {اسم الشهر}" with the Arabic
month names used in Kuwait (يناير … ديسمبر). Helper `Term::months()` returns
`[['year' => 2026, 'month' => 6, 'index' => 1, 'label' => 'الشهر الأول/ يونيو'], …]`.

## 4. Generator

`App\Services\Attestations\AttestationGenerator::generate(Application, int $year, int $month, ?User $by): Attestation`

Preconditions (guarded, `DomainException` with a lang key on failure):
application status `approved`; term open; `(year, month)` is one of the
term's months; the application has at least one assignment. An existing
attestation in status `generated` is replaced (rows deleted and rebuilt,
edits discarded); one in status `exported` is refused (`attestation_locked`).

Algorithm (system spec §5, made precise):

1. **Window** = `[max(teaching_starts_on, first of month), min(teaching_ends_on, last of month)]`.
2. **Blocks**: split the window into Sunday-start weeks. Each block's
   `date_from`/`date_to` are its first and last days inside the window that
   fall on Sunday–Thursday (Friday and Saturday are never inside a block, so
   "7-11", "28-30", "1-2" come out as on the printed form). A block whose
   Sunday–Thursday days all fall outside the window is not a row.
3. **working_days** = the block's Sunday–Thursday days minus the term's
   holidays. A block may have zero working days (a full holiday week); it is
   still a row with zero hours and a note listing the holidays.
4. **Sections of the week** = the application's assigned sections that have
   at least one meeting whose `day_of_week` (0 = Sunday … 4 = Thursday)
   matches a working day of that block.
5. **Minutes per type** = sum over the week's sections of the minutes of
   their meetings whose weekday is a working day, by meeting type. No
   scaling, no rounding: a meeting either happens that week or it does not.
6. **courses_text** = the week's sections, de-duplicated by course code,
   ordered by course code, one line each: `"{course_name_ar} ({course_code})"`.
7. **student_count** = sum of `seats_registered` of the week's sections
   (null counts as 0), de-duplicated by section.
8. **note_ar**:
   - five working days → `أسبوع كامل`;
   - otherwise the Arabic day names of the working days joined by `- `
     followed by ` (فقط)` (e.g. `الأحد- الاثنين- الأربعاء- الخميس (فقط)`), or
     nothing if there are none;
   - then, one line per holiday inside the block:
     `يوم {الثلاثاء} {16 يونيو 2026} {holiday name}`;
   - then, on the block containing `teaching_ends_on`:
     `آخر يوم دراسي {23 يوليو 2026}`.
   Lines are joined with a newline.
9. The generated values are written to both the editable column and its
   `generated_` twin. `status = generated`, `generated_at = now()`,
   `generated_by = $by`, `exported_at = null`.

Hours on screen and on paper: minutes → hours, whole number when whole,
otherwise up to two decimals trimmed (`2`, `2.5`, `1.25`). New helper
`Section::hoursForForm(int $minutes): string`; `hoursFromMinutes` (one
decimal, milestone 2) stays for the assignment screens.

### Worked example (the summer 2025-2026 form, used as the test fixture)

Term `summer 2025-2026`, teaching 2026-06-07 → 2026-07-23, holiday
2026-06-16 "إجازة رأس السنة الهجرية". One assigned section, 10 h/week:
theory Sunday and Tuesday 120 min each, practical Monday, Wednesday and
Thursday 120 min each.

| Month | Week | Dates | Working days | Theory | Practical | Note |
|---|---|---|---|---|---|---|
| June (1) | 1 | 7–11 | 5 | 4 | 6 | أسبوع كامل |
| | 2 | 14–18 | Sun Mon Wed Thu | 2 | 6 | الأحد- الاثنين- الأربعاء- الخميس (فقط) ⏎ يوم الثلاثاء 16 يونيو 2026 إجازة رأس السنة الهجرية |
| | 3 | 21–25 | 5 | 4 | 6 | أسبوع كامل |
| | 4 | 28–30 | Sun Mon Tue | 4 | 2 | الأحد- الاثنين- الثلاثاء (فقط) |
| July (2) | 1 | 1–2 | Wed Thu | 0 | 4 | الأربعاء- الخميس (فقط) |
| | 2 | 5–9 | 5 | 4 | 6 | أسبوع كامل |
| | 3 | 12–16 | 5 | 4 | 6 | أسبوع كامل |
| | 4 | 19–23 | 5 | 4 | 6 | أسبوع كامل ⏎ آخر يوم دراسي 23 يوليو 2026 |

June totals 14 / 20, July totals 12 / 22. (The system spec's illustrative
"2/4, 4/4, 0/2" figures were inconsistent with their own meeting pattern;
the values above are the ones the tests assert.)

## 5. Admin screens

All under `/admin`, `role:admin` middleware, `AttestationPolicy`.

### 5.1 Month page — `GET /admin/attestations?term={id}&month={index}`

Term selector (default: the open term) and month selector (the term's
months, default: the current month if inside the term, else the first). A
table of every application in the term with status `approved` and at least
one assignment: instructor name, weekly hours, attestation status for that
month (`—` not generated / `generated` + date / `exported` + date), and
actions: open, Word, PDF. Above the table: **"توليد الناقص"** generates an
attestation for every listed application that has none for the month
(`POST /admin/attestations/generate`, form fields `term`, `month`), and
**"ملف PDF مجمع"** (`GET /admin/attestations/combined?term&month`) builds one
PDF of all attestations of the month that exist, in instructor-name order,
and marks each of them `exported`. Both buttons are disabled on a closed
term (generate) or when nothing exists (combined).

### 5.2 Attestation page — `GET /admin/attestations/{attestation}`

Header block (masked civil ID and IBAN, as elsewhere; the print reveals
them), the month title, and the week table with the editable cells
(`courses_text`, `student_count`, hours per type as decimals, `note_ar`).
Where an edited value differs from its `generated_` twin the generated value
is shown under it in muted text ("المولد: 6"). A totals row. Buttons:
**حفظ** (`PUT …/{attestation}`; hours are submitted as decimals and stored as
minutes, `round(h * 60)`), **إعادة التوليد** (`POST …/regenerate`, confirm
dialog: edits are lost), **Word**, **PDF** (`GET …/download?format=docx|pdf`),
and on an exported attestation **فتح للتعديل** (`POST …/unlock`).

Rules: save and regenerate require term open and status `generated`;
unlock requires term open and status `exported` (it sets `generated` and
leaves `exported_at` in place as history); every download and every
combined export sets `status = exported` and `exported_at = now()`, so the
column always holds the latest export.

### 5.3 Dashboard

Two alert kinds for the open term, both linking to the month page:

- for each term month whose first day is ≤ today: the number of listed
  applications without an attestation ("{n} مزاولة غير مولدة لشهر {label}");
- for each term month whose last day is < today: the number of attestations
  still `generated` ("{n} مزاولة غير مصدرة لشهر {label}").

## 6. Documents

### 6.1 Template

`resources/forms/kh3-template.docx`, made from the official blank form
(the summer 2025-2026 .docx in OneDrive; it holds no personal data). One
page block kept; the title's term and year, every header field, the twelve
civil-ID cells, the three course-name cells, the week rows and the totals
row become PhpWord `${placeholders}`; the fixed Arabic text of the form
(labels, the important note, the three signature captions) stays as is.
The template is versioned in the repo; changing it is a code change.

Placeholders (all filled by `App\Services\Attestations\AttestationDocument`):
`term_label` ("للفصل الصيفي" / "للفصل الأول" / "للفصل الثاني"),
`academic_year`, `category`, `dept_name`, `decision_number`,
`decision_date` (d/m/Y or blank), `full_name`, `job_title`, `cid1`…`cid12`,
`employer`, `course1`, `course2`, `course3` (distinct course names of the
assigned sections in course-code order; a fourth and later are appended to
`course3` joined by "، "), `account_number` (IBAN), `bank_name`,
`bank_branch`, `basic_salary`, `total_salary`, `phone_work`, `phone_home`,
`phone_mobile`, `weekly_hours`, `month_title`; per week row (cloneRow on
`week_no`): `week_no`, `week_dates` ("7-11"), `week_courses`,
`week_students`, `week_theory`, `week_practical`, `week_field`,
`week_total`, `week_note`; totals: `sum_students`, `sum_theory`,
`sum_practical`, `sum_field`, `sum_total`. Empty numeric cells print blank,
not 0, except the totals.

The combined PDF is one .docx built by cloning the page block once per
attestation (`cloneBlock('page', n)` with per-index values), then converted
once. Page order: instructor `full_name`.

### 6.2 Building and converting

- `AttestationDocument::docx(Attestation): string` and
  `::combinedDocx(Collection<Attestation>): string` write to
  `storage/app/private/generated/tmp/` under random names
  (`Str::random(32).docx`), output escaping enabled, returned path served
  with `response()->download(...)->deleteFileAfterSend(true)`.
- `App\Services\Attestations\PdfConverter::convert(string $docxPath): string`
  runs `soffice --headless --norestore -env:UserInstallation=file://{tmp
  profile dir} --convert-to pdf --outdir {dir} {file}` through Symfony
  Process, 90 s timeout, one temporary profile directory per call (deleted
  afterwards) so concurrent conversions do not fight over the profile lock.
  Binary path from `config('services.soffice.path', 'soffice')`. If the
  binary is missing or the run fails, the controller reports
  `app.attestations.pdf_unavailable` and the Word download stays available;
  the failure is `report()`ed.
- Download file names: `kh3-{application_id}-{year}-{month}.docx|pdf`,
  combined `kh3-{term}-{year}-{month}.pdf`. No names or civil IDs in file
  names.

### 6.3 Deployment notes

LibreOffice 7.3 and the Amiri Arabic fonts are already on the server. The
template must use a font available there (Arial/Times map to Liberation;
Arabic shaping falls back to Amiri/DejaVu); the plan includes one manual
visual check of a converted PDF on the server after the first deploy.
`storage/app/private/generated/tmp` is created by `deploy.sh`'s `mkdir`
list (extend it). `.env` gains nothing unless `soffice` is not on PATH
(`SOFFICE_PATH`).

## 7. Security

- `AttestationPolicy`: `viewAny`, `view`, `generate`, `update`,
  `regenerate`, `export`, `unlock` → `isAdmin()`; controllers call
  `authorize()`; the term-open and status rules live in the service and are
  tested there. Registered next to the existing policies.
- Every export decrypts civil ID, IBAN and salaries into a file: one audit
  row per download, action `export_attestation`, subject the attestation,
  details `docx` or `pdf`; the combined export writes one row per included
  attestation with details `combined_pdf`. Generation, save, regenerate and
  unlock are audited (`generate_attestation` with details `new` or
  `regenerated`, `update_attestation` with the sorted list of changed
  columns, `unlock_attestation`). No sensitive values anywhere in audit
  details, logs, session or flash (the week form has no sensitive fields).
- Decrypted values are read only inside `AttestationDocument`. Screens keep
  the milestone 1 masking; the existing `reveal_sensitive` path is not
  changed.
- Temporary files: random names, private disk, deleted after send; a
  scheduled-free cleanup is not needed because every path deletes what it
  creates, including on conversion failure (`finally`).
- Closed term: downloads and the combined PDF still work (the department
  reprints signed forms); generate, save, regenerate and unlock are refused
  with `app.terms.closed`.

## 8. Testing

Unit (`tests/Unit/Attestations/`):

- `AttestationGeneratorTest` with the §4 fixture: every row of the worked
  example (dates, working days, minutes per type, courses_text,
  student_count, note_ar), both months' totals, and: a month where teaching
  starts on a Wednesday; a holiday on a Friday (no effect); a whole holiday
  week (row with zero hours and holiday note); two sections sharing a course
  code (one course line, seats summed per section); a section with only
  practical meetings; regeneration replaces edited values and resets the
  twins; refusal on `exported`, on a non-approved application, on a closed
  term, on a month outside the term, on an application without assignments.
- `Term::months()` for a two-month summer term and a five-month regular term
  spanning a year end (December → April).
- `Section::hoursForForm`: 120 → "2", 150 → "2.5", 75 → "1.25", 0 → "0".
- `AttestationDocumentTest`: the generated .docx unzips and
  `word/document.xml` contains the full name, the twelve civil-ID digits in
  order, the decision number, every week's dates and note, and the totals;
  a value with `<` or `&` is escaped; the combined document contains one
  page block per attestation in name order.
- `PdfConverterTest`: skipped unless `soffice` is found; otherwise converts
  a small .docx and asserts a PDF file with `%PDF` magic.

Feature (`tests/Feature/Admin/AttestationsTest`):

- Instructor role gets 403 on every attestation route; admin gets 200.
- Month page lists only approved applications with assignments and shows the
  three status states; "توليد الناقص" creates only the missing ones and
  audits each; the dashboard alerts count correctly for a started month and
  for a finished month.
- Save stores minutes from decimal hours, keeps the twins, audits the changed
  columns, and is refused on an exported attestation and on a closed term.
- Download sets `exported` and refreshes `exported_at`, writes the audit
  row, returns a .docx with the expected file name; PDF download when `soffice` is absent returns the
  unavailable message and leaves the status untouched.
- Unlock returns to `generated` and audits; regenerate after unlock works.
- Combined PDF (with `soffice` faked through a config path to a stub script
  that copies the input to `.pdf`) marks every included attestation
  exported and audits each.

Lang parity (every `ar` key has an `en` twin, no tashkeel) and Pint on
touched files, as in milestones 1 and 2.

## 9. Delivery

One milestone, one plan, executed subagent-driven like milestones 1 and 2,
in this order: schema + models + `Term::months()`; generator with the
fixture; policy + routes + month page + generate action; attestation page +
save + regenerate + unlock; template + `AttestationDocument`; `PdfConverter`
+ downloads + combined PDF; dashboard alerts; docs and deploy notes. Ends
deployed with `./deploy/deploy.sh` and a visual check of one PDF.

## 10. Out of scope / deferred

- Instructor-facing attestation screens, confirmation and emails (decision 1).
- Term close semantics beyond the existing status, and the returning-
  instructor "على الملف" checklist logic (milestone 4).
- Per-instructor category on the form title (decision 2).
- Editing holidays after generation does not touch existing attestations;
  the admin regenerates the affected months (the form's own "ملاحظة مهمة"
  covers later holiday changes).
- Milestone 2 deferred minors remain as listed in
  `docs/superpowers/reviews/2026-09-29-milestone-2-final-review.md`; only
  those touching hours (`weekly_minutes` as the single source) are relied on
  here.

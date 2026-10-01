# parttime.q8ee.com — Design Spec

Date: 2026-09-28
Owner: Dr. Mishal E. AlSharidah (EET department, College of Technological Studies, PAAET)
Status: approved in conversation; awaiting review of this written spec

Arabic name: نظام المنتدبين — قسم تكنولوجيا الهندسة الكهربائية
English name: Part-time Instructors System — Electrical Engineering Technology Department

## 1. Purpose

The department hires part-time instructors (منتدبون, "seconded instructors from
outside PAAET") every term. Today each applicant sends a loose bundle of
documents by WhatsApp or email, the department checks them by hand against the
official PAAET Check List, and every month each instructor's teaching hours are
retyped into the official attestation form (خ-3) for three signatures.

The system replaces that with:

1. Online applicant registration and document upload against the official
   checklist, with the required items derived automatically per applicant.
2. Admin review, approval, and a printable Check List identical to the official one.
3. Section assignment per term from an imported course list.
4. Monthly (خ-3) attestation pages generated from assignments and the term
   calendar, confirmed online by the instructor, exported as Word/PDF for signature.

Success: for any instructor, the admin sees at a glance what is missing, and can
print a completed Check List and a pre-filled (خ-3) without retyping anything.

Reference transcriptions of the two official forms: `docs/forms/checklist.md`
and `docs/forms/actual-practice-form.md`.

## 2. Decisions (locked in brainstorming, 2026-09-28)

1. **Applicants self-register** online with email + password. Registration and
   login are protected by **Cloudflare Turnstile** (the site is already behind
   Cloudflare). Email verification is required before any upload.
2. **(خ-3) is pre-filled by the system** from assignments and the term calendar;
   the **instructor confirms** it online, adjusting actual hours/notes if needed;
   the admin exports it for the three wet signatures.
3. **Courses/sections are imported per term from Excel.** Never free text.
4. **Approach A**: one Laravel app, three modules delivered in order:
   intake → assignment → attestation. Everything belongs to a term.
5. **Stack**: Laravel 12, PHP 8.x, MySQL, Bootstrap 5 RTL, Blade, minimal
   vanilla JS, no build-step frontend. Same as help.q8ee.com. Deploy to the
   alsharidah.shop server (Ubuntu + Apache, vhost parttime.q8ee.com) behind
   Cloudflare, with a `deploy/deploy.sh` like the siblings.
6. **Roles**: `admin` (Dr. Mishal) and `instructor`. One admin in V1; the role
   system must allow a second admin later without schema change. Enforced by
   Laravel policies, not by hiding links.
7. **Sensitive data** (civil ID, IBAN, basic and total salary): encrypted at
   rest with Laravel encrypted casts, masked on every screen by default
   (`287*****123` style), full reveal only for admin via an explicit action that
   is audit-logged. Same pattern as help.q8ee.com.
8. **Language**: Arabic-first, RTL, formal undiacritized Arabic in all UI copy.
   Complete translation coverage from day one (lang files, no hard-coded strings).
9. **Notifications**: email via Laravel Mail, Arabic templates with English
   footer. No WhatsApp/SMS integration in V1.
10. **The checklist is fixed** (the 12 official items with their conditions
    coded in a seeder). Not editable by users in V1.

## 3. Data model

All tables carry `created_at`/`updated_at`. Foreign keys are explicit.

### terms (فصل دراسي)
- `id`, `academic_year` (e.g. `2026-2027`), `type` enum `first|second|summer`
- `teaching_starts_on`, `teaching_ends_on` (dates)
- `status` enum `open|closed|archived`
- Unique on (`academic_year`, `type`).
- Has many `holidays`: `date`, `name` (e.g. "إجازة رأس السنة الهجرية"). A
  holiday may span several rows (one per day).
- Closed/archived terms are read-only for everyone.

### users
- Standard Laravel users: `name`, `email`, `password`, `email_verified_at`,
  `role` enum `admin|instructor`.
- An instructor user has exactly one `instructor` profile.

### instructors (منتدب) — one profile per person, reused across terms
- `user_id` (unique)
- `full_name` (الاسم الثلاثي, Arabic)
- `civil_id` (encrypted, 12 digits, unique via a separate blind index/hash column)
- `civil_id_expires_on`
- `nationality`
- `mobile`, `work_phone` (nullable), `home_phone` (nullable)
- `employer` (جهة العمل), `employer_sector` enum `government|private`
- `job_title`
- `highest_degree` enum `bachelor|master|phd`
- `degree_title` (المؤهل العلمي, free text e.g. "ماجستير هندسة كهربائية")
- `degree_country` (ISO code; `KW` means local), `degree_obtained_on`
- `bank_name`, `bank_branch`, `iban` (encrypted)
- `basic_salary`, `total_salary` (encrypted, KWD)
- `experience_years` (integer, nullable; needed only when highest_degree = bachelor)

### applications (طلب انتداب) — one per instructor per term
- `term_id`, `instructor_id`, unique together
- `status` enum `draft|submitted|under_review|incomplete|approved|rejected|withdrawn`
- `submitted_at`, `reviewed_at`, `decided_at`
- `assignment_decision_number`, `assignment_decision_date` (رقم وتاريخ قرار التكليف, nullable, entered by admin once PAAET issues it)
- `weekly_hours` (integer, derived from assignments; cached for the (خ-3) header)
- `admin_note` (internal)

Status transitions:
- `draft → submitted` by instructor when every required document has a file.
- `submitted → under_review` automatically on first admin view.
- `under_review → incomplete` when admin rejects any document (instructor notified).
- `incomplete → submitted` when instructor re-uploads all rejected items.
- `under_review → approved` only when all required items are `accepted`.
- `under_review|incomplete → rejected` by admin with a reason.
- Any non-final → `withdrawn` by instructor.

### checklist_items (fixed, seeded)
- `code` (e.g. `civil_id`, `degree`, `equivalency`, `social_insurance`,
  `experience`, `salary_cert`, `iban`, `employer_approval`, `undertaking`,
  `schedule`, `assignment_letter`, `attestation`)
- `label_ar`, `sort_order`
- `provided_by` enum `applicant|department`
- `condition` enum `always|foreign_degree|private_sector|bachelor_only`
- `renews_each_term` boolean (salary_cert, employer_approval, undertaking, social_insurance = true (social_insurance since 2026-10-01: it attests current employment);
  civil_id re-required only if expired)

Derivation rule — item is required for an application when:
- `condition = always`, or
- `foreign_degree` and `instructor.degree_country != KW`, or
- `private_sector` and `instructor.employer_sector = private`, or
- `bachelor_only` and `instructor.highest_degree = bachelor`
  (the form additionally demands ≥ 10 years experience; the system records
  `experience_years` and warns the admin if < 10, it does not block).
Items with `provided_by = department` are displayed as "يصدرها القسم" and never
requested from the applicant.

### documents (مستند)
- `application_id`, `checklist_item_id`
- `path` (outside web root, random filename), `original_name`, `mime`, `size`
- `status` enum `pending|accepted|rejected`
- `rejection_reason` (nullable), `reviewed_by`, `reviewed_at`
- `version` (integer; re-uploads create a new row, old rows kept for history;
  only the latest version counts toward status)
- Accepted: PDF, JPG, PNG, DOCX; max 10 MB. ZIP is rejected with a message
  telling the applicant to upload each item separately.

### sections (شعبة) — imported per term
- `term_id`, `course_code`, `course_name_ar`, `section_number`
- `student_count` (nullable)
- Unique on (`term_id`, `course_code`, `section_number`).
- Has many `section_meetings`: `day_of_week` (0=Sunday … 4=Thursday),
  `type` enum `theory|practical|field`, `hours` (integer). A section's weekly
  hours per type = sum of its meetings of that type. Meeting days are needed
  because (خ-3) hours in a short week depend on **which** days were taught,
  not on the number of days (see the sample: a Sun/Mon/Wed/Thu week gives
  2 theory + 4 practical, a Sun/Mon/Tue week gives 4 + 4).
- Import: admin uploads an .xlsx with one row per meeting, fixed header row
  (course code, course name, section, day, type, hours, students). Rows for
  the same section are grouped. Import is idempotent: sections matched by the
  unique key are updated and their meetings replaced, new sections inserted,
  and a summary (inserted/updated/unchanged/errors) is shown. Rows with errors
  are listed with the row number and skipped; nothing is partially written
  (single transaction).

### assignments (تكليف)
- `application_id`, `section_id`, unique together.
- Only allowed when `application.status = approved` and term is `open`.
- `application.weekly_hours` = sum over assigned sections of all their
  meetings' hours.

### attestations (مزاولة) — one per application per month
- `application_id`, `year`, `month`, unique together
- `status` enum `generated|confirmed|exported`
- `confirmed_at`, `exported_at`, `instructor_note`
- Has many `attestation_weeks`:
  - `week_number` (1-5 within the month), `date_from`, `date_to`
  - `working_days` (JSON list of dates actually taught), `note_ar`
  - `courses_text` (Arabic list of course name + code lines)
  - `student_count`, `theory_hours`, `practical_hours`, `field_hours`, `total_hours`
  - Generated values are stored; the instructor may edit hours/student_count/
    note per week before confirming. Original generated values are kept in
    `generated_*` columns so edits are visible to the admin.

### audit_log
- `user_id`, `action` (e.g. `reveal_civil_id`, `download_document`,
  `approve_application`, `import_sections`), `subject_type`, `subject_id`,
  `ip`, `created_at`. Append-only.

## 4. Workflow

### Before the term (admin)
1. Create the term: year, type, teaching start/end, holidays.
2. Import the section list from Excel.
3. Share the registration link.

### Intake (instructor)
4. Register (email, password, Turnstile), verify email.
5. Fill the profile. Fields are validated (civil ID 12 digits and checksum
   format, IBAN format for KW, dates). The profile is reusable across terms.
6. Start an application for the open term. The checklist is derived from the
   profile and shown with three groups: required from you, provided by the
   department, not applicable to you.
7. Upload one file per required item. Submit when all required items have a file.

### Review (admin)
8. "يحتاج انتباهي" (needs my attention) list: submitted applications, resubmissions
   after rejection, unconfirmed attestations past their due date.
9. Open an application: profile summary (sensitive fields masked), documents with
   preview/download, accept/reject each with a reason. Rejection sends an email
   listing exactly what to fix.
10. When all required items are accepted: approve. Enter the PAAET assignment
    decision number/date when available. Print the official Check List (filled;
    checker name = admin name; date = today).

### During the term
11. Assign sections to each approved instructor.
12. On the 1st of each teaching month (scheduled command, also runnable manually),
    generate that month's (خ-3) for every approved application with at least one
    assignment. Instructor is emailed. They review, adjust if needed, confirm.
13. Admin exports confirmed attestations as Word (and PDF) for signatures. An
    attestation not confirmed within 7 days of the month end appears in the
    attention list.

### After the term
14. Admin closes the term. Everything under it becomes read-only.
15. Next term: a returning instructor starts a new application; the checklist
    pre-marks items already accepted in a previous term as "على الملف" (on file)
    unless `renews_each_term` is true or the civil ID has expired. On-file items
    still appear on the printed Check List as present.

### Role scoping
- Instructor: own user, own profile, own applications/documents/attestations only.
- Admin: everything, plus reveal/export/import/term management.
- Policies on every model; tests assert cross-instructor access returns 403.

## 5. Form generation

### (خ-3) week generator
Input: application, year, month, term (dates + holidays), assignments.
Algorithm:
1. Compute the teaching days of the month: every Sunday–Thursday between
   `teaching_starts_on` and `teaching_ends_on` that falls in the month and is
   not a holiday.
2. Group by ISO week (Sunday-start). Each group becomes a week row with
   `date_from`/`date_to` = first and last calendar day of that group inside the
   month (matching the college's "7-11", "28-30" style).
3. `working_days` = the teaching days in that group.
4. Hours per week = for each assigned section, the sum of its meetings whose
   `day_of_week` is in `working_days`, per type. No proportional scaling and
   no rounding: a meeting either happened that week or it did not. This
   reproduces the sample exactly (10 h/week section meeting theory Sun+Tue
   2 h each and practical Mon+Wed+Thu 2 h each gives 4/6 in a full week, 2/4
   when Tuesday is a holiday, 4/4 for Sun–Tue, 0/2 for Wed–Thu).
5. `note_ar`: "أسبوع كامل" when 5 working days; otherwise the Arabic day names
   of the working days followed by "(فقط)", plus a line per holiday in that
   week ("يوم الثلاثاء 16 يونيو 2026 إجازة رأس السنة الهجرية"), plus
   "آخر يوم دراسي <date>" on the last teaching week of the term.
6. Monthly totals row = column sums.

The month title uses the term's month index ("الشهر الأول/ يونيو",
"الشهر الثاني/ يوليو", ...).

### Templates
- `resources/forms/kh3-template.docx`: the official (خ-3) rebuilt with
  placeholders (header fields, week rows, totals, signature footer). One page
  per month is produced by cloning the page block. Header text "للفصل الصيفي /
  الأول / الثاني" and the year are placeholders.
- `resources/forms/checklist-template.docx`: the official Check List rebuilt
  from the JPEG layout (PAAET logo, title, 11 header fields, 12 checkbox rows,
  checker name).
- Generation is done by unzipping the template, substituting in
  `word/document.xml`, and re-zipping (PhpWord template processor or a small
  in-house class). PDF is produced with LibreOffice headless on the server;
  if LibreOffice is unavailable the Word file is still downloadable.
- Templates are versioned in the repo; a template change is a code change.

## 6. Files and security

- Uploads stored under `storage/app/private/applications/{id}/` with random
  names; never under `public/`. Download only through an authenticated,
  policy-checked route. Admin downloads of instructor documents are audit-logged.
- Turnstile on registration and login (server-side token verification;
  a test double in the test environment).
- Rate limits: login 5/min per IP+email, registration 3/hour per IP.
- Passwords: Laravel defaults (bcrypt, min 8, compromised-password check on).
- Sensitive columns encrypted (casts) with a hashed blind-index column for civil
  ID uniqueness lookups.
- All admin mutations and reveals go to `audit_log`.
- Backups: nightly MySQL dump + storage rsync, same as the sibling projects
  (handled in `deploy/`, not in app code).

## 7. Testing

- Feature tests (Pest or PHPUnit, whichever help.q8ee.com uses — follow it):
  - Registration with Turnstile stub; email verification gate before upload.
  - Checklist derivation for all condition combinations (local vs foreign
    degree; government vs private; bachelor vs master/phd).
  - Upload validation (type, size, ZIP rejected), versioning on re-upload.
  - Accept/reject flow and status transitions; approval blocked while any
    required item is not accepted.
  - Excel import: idempotent re-import, error rows reported, transaction rollback.
  - Assignment only on approved applications in open terms; weekly hours sum.
  - Policy scoping: instructor A cannot read B's application/document/attestation.
  - Reveal action logs to audit_log; masked by default in views.
- Unit tests for the week generator:
  - Fixture: summer 2025-2026 term (7 Jun – 23 Jul 2026, holiday 16 Jun) with a
    10 h/week section meeting theory Sun+Tue (2 h each) and practical
    Mon+Wed+Thu (2 h each) must reproduce the sample's rows and totals
    (June: 4 weeks, theory/practical/total 14/20/34; July: 4 weeks, 12/20/32).
  - A regular term with a mid-week holiday and a month starting on Thursday.
- Generated .docx checked by unzipping and asserting placeholders are gone and
  expected Arabic strings are present.

## 8. Delivery milestones

1. **Intake** (usable first): terms, registration, profile, checklist,
   uploads, admin review/approval, printable Check List, emails.
2. **Assignment**: Excel import, assign sections, weekly hours.
3. **Attestation**: generator, instructor confirm, Word/PDF export, attention
   list items, term close and on-file logic.

Each milestone ends deployed at parttime.q8ee.com.

## 9. Out of scope for V1

- Editable checklist definitions.
- WhatsApp/SMS notifications.
- Direct integration with jadawil or PAAET systems.
- Instructor self-service editing after approval (admin edits on request).
- Multiple departments/colleges (single-department app; the department name is
  configuration).

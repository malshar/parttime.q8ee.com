# parttime.q8ee.com — Milestone 2 Design: Committee Workflow, Sections Import, Assignments

Date: 2026-09-29
Owner: Dr. Mishal E. AlSharidah
Status: approved in conversation; awaiting review of this written spec
Extends: `2026-09-28-parttime-system-design.md` (the system spec). Where this
document and the system spec differ, this document wins for milestone 2.

## 1. Purpose

Milestone 1 delivered intake (registration, documents, admin review, Check
List). Milestone 2 makes the department's real approval path explicit and
connects each approved instructor to the sections they teach, so that
milestone 3 can generate the monthly (خ-3) attestation automatically.

Two facts learned after the system spec was written:

1. **Approval is not the department's decision.** Documents are verified by the
   department; the decision to appoint comes from the employment and part-time
   committee (لجنة التوظيف والانتداب). The system must show "documents
   complete, awaiting committee" as its own state, and the admin records the
   committee's outcome.
2. **The section list already exists as an export.** jadawil.q8ee.com exports
   the term's timetable as CSV or XLSX with one row per meeting block (course,
   section, activity, days, times, room, instructor). help.q8ee.com already
   imports that format. Milestone 2 imports the same file instead of a
   hand-made sheet.

Success: after import and assignment, every approved instructor's application
shows their sections and computed weekly hours by type, and the admin's
attention list shows what is waiting on the department versus the committee.

## 2. Decisions (locked 2026-09-29)

1. **Weekly hours are scheduled contact time**, computed from meeting
   durations. Never typed. Fractional hours are allowed (e.g. 2.5).
2. **The admin records the committee decision.** No committee login. A new
   status `complete` precedes it.
3. **Import source is the jadawil export** (CSV or XLSX), same header contract
   as help.q8ee.com's importer. No manual section entry, no meeting editing.
4. **Approach A**: one plan, two ordered groups — Group 1 workflow changes and
   carry-overs, Group 2 sections and assignments.
5. **Carry-overs from milestone 1 included**: admin profile edit on request,
   reopen a withdrawn application, one consolidated rejection email.
6. Stack, conventions, roles, sensitive-data rules and language rules are
   unchanged from the system spec.

## 3. Workflow changes (Group 1)

### 3.1 Status pipeline

```
draft → submitted → under_review ⇄ incomplete
                         ↓
                      complete  → approved | rejected
withdrawn  ← any non-final state ; withdrawn → draft (admin reopen)
```

- `complete` (ملف مكتمل): set by the admin via a "الملف مكتمل" action, allowed
  only when every required item's latest document is `accepted`, the
  application is `under_review` or `incomplete`, and the term is open.
- From `complete`, rejecting a document returns the application to
  `incomplete` (existing rule) and clears the completeness.
- `approved` / `rejected` are set only through the committee decision (3.2).
  The existing direct approve/reject actions are removed.
- `EDITABLE_STATUSES` and `FINAL_STATUSES` are unchanged; `complete` is neither
  editable nor final.

### 3.2 Committee decision

New columns on `applications`:
- `committee_outcome` enum `approved|rejected` (nullable)
- `committee_met_on` date (nullable)
- `committee_reference` string 60 (nullable)
- `committee_note` text (nullable)

Admin form on a `complete` application (open term): outcome (required), meeting
date (required, not in the future), reference (required, max 60), note
(optional, max 1000). Approved → status `approved`, `decided_at = now`, the
existing `ApplicationApproved` email. Rejected → status `rejected`,
`rejection_reason = note` (note required in this case), `ApplicationRejected`
email. Audit action `committee_decision`. The PAAET assignment decision number
and date entered after approval (milestone 1 fix wave) are unchanged and
separate from the committee reference.

### 3.3 Attention list

Three groups on the dashboard, each with counts:
1. **بانتظار مراجعة القسم**: `submitted` and `under_review` (existing).
2. **بانتظار اللجنة**: `complete`, with days since completion.
3. **تنبيهات**: approved applications on the open term with zero assignments
   (from Group 2), and sections flagged "not in latest import".

`complete_at` timestamp added to `applications` for the wait counter.

### 3.4 Carry-overs

- **Admin profile edit** (`admin.applications.profile.edit|update`): the same
  fields and `ProfileRequest` rules as the instructor form, rendered on the
  application page for admin. Allowed in any status. On save, an audit row
  `admin_edit_profile` with the list of changed field names (never values).
  The instructor's own lock during review is unchanged.
- **Reopen withdrawn** (`admin.applications.reopen`): allowed when status is
  `withdrawn` and the term is open. Sets `draft`, clears `decided_at`, audit
  `reopen_application`, emails the instructor (`ApplicationReopened`, Arabic).
- **Consolidated rejection email**: `reviewDocument()` no longer sends
  `DocumentsRejected`. A new `documents.notified_at` timestamp marks which
  rejections have been communicated. A "إنهاء المراجعة وإبلاغ المتقدم" action
  (`admin.applications.notify_rejections`) is shown when any latest document is
  `rejected` with `notified_at` null; it sends one `DocumentsRejected` listing
  all currently rejected latest documents, stamps them, audit
  `notify_rejections`. The application still flips to `incomplete` at the first
  rejection as today.

## 4. Sections (Group 2)

### 4.1 Data model

`sections`:
- `term_id`, `course_code` (string 12), `course_name_ar` (string 150),
  `section_number` (string 6), `reference_number` (string 20, nullable)
- `seats_capacity`, `seats_registered`, `seats_remaining` (nullable ints; only
  present in the XLSX export)
- `scheduled_instructor` (string 150, nullable; the export's المدرس text)
- `imported_at` timestamp; `missing_since_import` boolean default false
- Unique (`term_id`, `course_code`, `section_number`)

`section_meetings`:
- `section_id`, `day_of_week` tinyint (0 Sunday … 4 Thursday)
- `type` enum `theory|practical|field`
- `starts_at`, `ends_at` (time), `minutes` unsigned smallint (= ends − starts)
- `activity_ar` (string 30, the export's النشاط text), `building`, `room`
  (nullable strings)
- Unique (`section_id`, `day_of_week`, `starts_at`, `type`)

Derived, not stored: section weekly minutes by type = sum of `minutes` per
type; hours = minutes / 60 shown with one decimal (Arabic UI uses Western
digits for numbers, as elsewhere in the app).

Activity mapping (case-insensitive after trimming): محاضرة → theory; مختبر,
ورشة, عملي → practical; ميداني → field; anything else → theory plus a preview
warning naming the unknown activity.

### 4.2 Import format

Accepted files: `.csv` (UTF-8, optional BOM) and `.xlsx`, max 10 MB, produced by
jadawil's "export CSV/XLSX" for the term. Required header columns (Arabic,
exact text, order-independent): رقم المقرر, اسم المقرر, الشعبة, النشاط, من, الى,
الأيام. Optional: الرقم المرجعي, المبنى, القاعة, المدرس, الحد الأقصى, مسجلة,
متبقية. Unknown columns are ignored. A trailing attribution/footer line without
a course code is skipped, as in help.q8ee.com.

Row semantics: one row = one meeting block. `الأيام` is an Arabic day list
separated by "/" (e.g. "الأحد / الثلاثاء"); each day becomes one
`section_meetings` row with the row's times and type. Times are `H:MM` 24-hour.
Rows for the same (course code, section) are grouped into one section.

Parser: `App\Services\Sections\JadawilParser` — pure function from file
contents to a `ParsedTimetable` value object (`sections[]` each with
`meetings[]`, plus `warnings[]` and `errors[]` with row numbers). CSV via
`str_getcsv`; XLSX via `maatwebsite/excel` (already a known dependency in the
sibling project; add `^4.0`). No database access in the parser.

> **Implementation note (2026-09-29):** built with `phpoffice/phpspreadsheet`
> (`^5.10`) directly for XLSX reading instead of `maatwebsite/excel`; row/
> section semantics and the parser's pure-function shape are unchanged.

### 4.3 Import flow (admin, open term only)

1. `admin.sections.import.form`: upload field, link to the current term's
   section list.
2. `admin.sections.import.preview` (POST): validates the file, parses, stores
   the parsed result in the session (not the file), renders the preview:
   sections found, meetings, weekly hours by type, activity warnings, error
   rows, and the planned effect against the database — counts of insert /
   update / unchanged / delete / **kept-but-flagged** (assigned sections absent
   from the file). Any error row blocks confirm.
3. `admin.sections.import.confirm` (POST): re-reads the session payload,
   applies it in one transaction via `App\Services\Sections\SectionImporter`:
   upsert sections by the unique key, replace their meetings, delete unassigned
   sections absent from the file, set `missing_since_import = true` on assigned
   absent sections (and back to false when they reappear), `imported_at = now`.
   Audit `import_sections` with the counts. Session payload cleared.
4. Any exception rolls back everything and returns to the preview with the
   message.

### 4.4 Section list

`admin.sections.index`: the current (or chosen) term's sections with course,
section, meeting summary (Arabic day names + times per block), weekly hours by
type, scheduled instructor, assignee, flags. Filter by course code and
"unassigned only".

## 5. Assignments (Group 2)

### 5.1 Data model

`assignments`: `application_id`, `section_id`, `created_by` (user id),
timestamps. Unique on `section_id` (one instructor per section per term; the
section already belongs to a term). Unique on (`application_id`, `section_id`).

`applications.weekly_hours` changes from integer to `decimal(5,1)` and is
recomputed on every assignment change: sum of assigned sections' meeting
minutes / 60, rounded to one decimal. A `weekly_minutes` unsigned int is also
stored for exactness; milestone 3 uses minutes.

### 5.2 Rules

- Assign only when: application status `approved`, term open, section belongs
  to the same term, section not assigned to another application.
- Unassign allowed while the term is open.
- Both audited (`assign_section`, `unassign_section`) with section id.
- Suggestion: a section whose `scheduled_instructor`, normalised, equals an
  approved instructor's `full_name`, normalised, is highlighted with that
  instructor preselected in the dropdown. Normalisation: trim, collapse
  spaces, strip tashkeel, fold أ/إ/آ → ا, ة → ه, ى → ي. Never auto-assigned.

### 5.3 Screens

- `admin.assignments.index` (per term): the section table from 4.4 with a
  per-row instructor dropdown (approved applications on that term) and
  assign/unassign buttons; suggestions highlighted; totals per instructor in a
  side panel.
- `admin.applications.show`: an "الشعب المسندة" card listing the instructor's
  sections and weekly hours by type, with unassign buttons.
- Instructor home: read-only list of assigned sections and weekly hours.

## 6. Security

- All new routes admin-only (`role:admin` + policies `SectionPolicy`,
  `AssignmentPolicy` with admin-only abilities; instructors read their own
  assignments through `ApplicationPolicy::view`).
- Uploaded import files are read once and discarded; nothing is written to the
  disk. Parsed payloads in the session contain no personal data beyond the
  export's instructor names.
- Blade escaping for all export-derived text.
- Every state-changing admin action in this milestone writes `audit_log`.

## 7. Testing

- Unit (`JadawilParser`): fixture CSV and XLSX created from jadawil's own
  sample data (no applicant data); day-list parsing; time parsing and minutes;
  activity mapping incl. unknown; grouping across rows; footer skipping;
  missing required header → error; malformed time → error with row number.
- Unit (`ArabicNameNormaliser`): the folding rules with examples.
- Feature: preview writes nothing; confirm inserts/updates/deletes as counted;
  re-import is idempotent; assigned section absent from file is kept and
  flagged; transaction rollback on a mid-import failure; instructors get 403 on
  all section/assignment routes.
- Feature (assignments): rules in 5.2 each have a test; weekly hours/minutes
  recompute on assign and unassign; suggestion matches with folded names and
  does not match otherwise.
- Feature (workflow): `complete` transition guards; committee decision both
  outcomes with emails; reopen; admin profile edit audit row lists changed
  fields; consolidated rejection email sent once and stamps `notified_at`;
  dashboard groups and counts.
- Milestone 1 tests updated where they used the removed direct approve/reject
  actions.

## 8. Delivery

One plan, tasks ordered: Group 1 (status `complete` + attention list,
committee decision, admin profile edit, reopen, consolidated email), then
Group 2 (migrations + models, parser, importer + preview/confirm, section list,
assignments + hours, screens, instructor view). Deployed together at the end
as milestone 2; the deploy runbook gains "import the term's jadawil export"
as a step.

## 9. Out of scope

- Committee login or notifications to committee members.
- Editing sections or meetings by hand; manual seat management.
- Section swaps mid-term with history (unassign + assign is enough for V1).
- Milestone 3: monthly attestation generation.

# parttime.q8ee.com — Milestone 7a Design: Degrees, Checklist Round 2, Timetable

Date: 2026-10-04
Owner: Dr. Mishal E. AlSharidah
Status: approved in conversation; awaiting review of this written spec
Extends: the system spec and the milestone 2–6 specs. Where this document
and an earlier spec differ, this document wins for milestone 7a. Milestone
7b (several open terms, signed attestations, export with documents) follows
in its own spec.

## 1. Purpose

Feedback of 2026-10-03/04 on the intake, as applicants start to use it:

1. Every degree the applicant holds must be documented, not only the
   highest: certificate, transcript and (for degrees from outside Kuwait)
   the equivalency from the Ministry of Higher Education, per degree.
2. A CV is required for the committee.
3. The employer approval letter is not a requirement.
4. The undertaking must be the college's own form, signed by the applicant
   and uploaded as a colour scan; the blank form is offered for download.
5. The teaching timetable is pulled by the instructor from Banner and
   uploaded per term, once sections are assigned; the department no longer
   issues it.
6. The (خ-3) "total students" is the sum of the students of each course
   taught in the month, not the sum down the weekly column.

## 2. Decisions (locked 2026-10-04)

1. **One row per degree held** (bachelor, master, doctorate): title,
   country, date. Replaces the single highest-degree fields on the profile.
2. **Checklist items per degree**: certificate, transcript (exemptable) and
   equivalency (foreign country only) for each held level.
3. **CV** required in stage 1. **Employer approval** retired. **Schedule
   (department)** retired, replaced by the applicant's **timetable** item
   (stage 2, renews each term, applicable once the application has
   assignments).
4. **Undertaking**: the college's blank form `resources/forms/undertaking-cts.pdf`
   is served to signed-in users from the upload row.
5. **Official Check List mapping**: items carry an `official_line` (1–12);
   the printed form prints its 12 fixed lines, ☑ when every applicable item
   mapped to the line is satisfied.
6. **Student total** = sum of `seats_registered` of the distinct sections
   taught in the month.

## 3. Data model

### 3.1 `instructor_degrees` (new)

- `instructor_id` FK cascade; `level` string 10 (`bachelor | master | phd`);
  unique `(instructor_id, level)`.
- `title` string 150; `country` string 2 (same list as today); `obtained_on`
  date; timestamps.
- Model `InstructorDegree`; `Instructor::degrees()` (ordered bachelor,
  master, phd); `Instructor::degree(string $level): ?InstructorDegree`;
  `Instructor::holds(string $level): bool`;
  `Instructor::isForeignDegree(string $level): bool`.
- `instructors.highest_degree` stays and is written from the rows on every
  profile save (max level present). `degree_title`, `degree_country`,
  `degree_obtained_on` are **dropped** after the data migration copies them
  into one row for the highest degree (`level = highest_degree`). The
  attestation header and the Check List header read the highest degree's
  row.
- Profile form: a "المؤهلات العلمية" card with three blocks. Bachelor is
  always required (title, country, date). Master and doctorate each have a
  "أحمل هذا المؤهل" checkbox that reveals their fields; when checked all
  three fields are required. `experience_years` keeps its rule (required for
  bachelor-only holders).
- Admin profile edit uses the same partial. The admin application page's
  profile card lists every degree held.
- Rule 4b (M4) profile-field names for the audit details become
  `degree_<level>_title`, `degree_<level>_country`, `degree_<level>_obtained_on`
  (plus `highest_degree`); `ProfileDiff` compares the degree rows as those
  flat keys.

### 3.2 `checklist_items`

- New column `official_line` unsigned tinyint nullable (1–12) replaces the
  `official` boolean (dropped). New condition values (section 4).
- Seed (`sort_order` in this order; stage, condition, renews, exemptable,
  official line):

| code | label (ar) | stage | condition | renews | exempt. | line |
|---|---|---|---|---|---|---|
| assignment_letter | كشف التكليف للمنتدب | 0 (department) | always | | | 2 |
| attestation | كشف المزاولة للمنتدب | 0 | always | | | 3 |
| civil_id | صورة البطاقة المدنية سارية المفعول | 1 | always | | | 4 |
| cv | السيرة الذاتية | 1 | always | | | — |
| degree_bachelor | صورة شهادة البكالوريوس | 1 | always | | | 5 |
| degree_master | صورة شهادة الماجستير | 1 | holds_master | | | 5 |
| degree_phd | صورة شهادة الدكتوراه | 1 | holds_phd | | | 5 |
| transcript_bachelor | كشف درجات البكالوريوس | 1 | always | | yes | — |
| transcript_master | كشف درجات الماجستير | 1 | holds_master | | yes | — |
| transcript_phd | كشف درجات الدكتوراه | 1 | holds_phd | | yes | — |
| equivalency_bachelor | معادلة شهادة البكالوريوس | 1 | foreign_bachelor | | | 6 |
| equivalency_master | معادلة شهادة الماجستير | 1 | foreign_master | | | 6 |
| equivalency_phd | معادلة شهادة الدكتوراه | 1 | foreign_phd | | | 6 |
| social_insurance | شهادة من المؤسسة العامة للتأمينات الاجتماعية | 2 | private_sector | yes | | 7 |
| experience | صورة من شهادة الخبرة | 1 | bachelor_only | | | 8 |
| salary_cert | شهادة راتب حديثة | 2 | always | yes | | 9 |
| iban | كشف الآيبان IBAN معتمد من البنك | 2 | always | | | 10 |
| employer_approval | موافقة جهة العمل | 2 | never | yes | | 11 |
| undertaking | نموذج إقرار وتعهد | 2 | always | yes | | 12 |
| timetable | الجدول الدراسي من نظام البانر | 2 | assigned | yes | | 1 |
| schedule | الجدول الدراسي | 0 | never | | | — |

Notes: `equivalency_*.note_ar` = "للمؤهلات الصادرة من خارج دولة الكويت";
`undertaking.note_ar` = "صورة ملونة من نموذج الكلية موقعة من المنتدب";
`timetable.note_ar` = "يرفعه المنتدب من نظام البانر بعد إسناد الشعب";
`employer_approval` keeps its old note. The seeder keeps `schedule` and
`employer_approval` rows (documents reference them) with `condition = never`;
retired items never appear on the pages (not in required, optional or
not-applicable lists) and never block anything.

### 3.3 Data migration of documents and exemptions

Existing rows that reference `degree` and `equivalency` are re-pointed, per
instructor, to `degree_<highest>` and `equivalency_<highest>` (the highest
degree is the only one the old profile described). The old `degree` and
`equivalency` item rows are then deleted. `transcript_*` rows are unchanged.
Checklist renewals and exemptions follow the same mapping. Done in one
migration, in a transaction, before the seeder runs.

## 4. Conditions and plan

`ChecklistItem::appliesTo(Instructor $i, ?Application $a = null)`:

- `always`, `private_sector`, `bachelor_only`, `master_or_above` as today;
- `holds_master`, `holds_phd` → `$i->holds(level)`;
- `foreign_bachelor`, `foreign_master`, `foreign_phd` → held and country not
  `KW`;
- `assigned` → `$a !== null && $a->assignments()->exists()`;
- `never` → false, and the item is **retired**: excluded from every plan
  collection (`ChecklistResolver` filters `condition = never` out before
  grouping).

`ChecklistResolver::for(Instructor, ?Application = null)`;
`ApplicationWorkflow::plan()`/`checklist()` pass the application. Rows for
`assigned` items appear only once assignments exist; the stage-2 readiness
(`stageTwoMissing`) therefore lists the timetable as missing after
assignment until it is accepted, which keeps attestations waiting for it
(decision 3). For continuations the timetable is a renewing stage-2 item and
so part of the term papers once assigned.

`ChecklistItem::PROFILE_FIELDS` (rule 4b):

- `civil_id` → civil ID and expiry (unchanged);
- `degree_<level>` and `equivalency_<level>` → the three flat keys of that
  level;
- `experience` → `experience_years`, `highest_degree`;
- `social_insurance`, `iban` unchanged.

## 5. Printed Check List

The printer iterates lines 1–12 in order with the official labels and notes
exactly as today (from a fixed table in `ChecklistDocument`, no longer from
item rows). For each line it collects the applicable applicant items with
that `official_line` in the application's checklist:

- none applicable (e.g. no foreign degree, retired employer approval) → `—`
  and "لا ينطبق";
- department line (2, 3) → `☐`;
- all applicable items satisfied → `☑`, else `☐`.

Items without a line (CV, transcripts) are not printed, as before.

## 6. Undertaking form download

Route `GET /forms/undertaking` (`forms.undertaking`, `auth` middleware)
streams `resources/forms/undertaking-cts.pdf` inline. The instructor's
undertaking row shows "تحميل نموذج الكلية" above the upload control; the
admin row shows the same link. The file is committed to the repo (it is a
blank college form). Audit is not needed (no personal data).

## 7. Student total on (خ-3)

`Attestation::totals()['student_count']` = sum of `seats_registered` over
the **distinct sections** that appear in any week of the attestation (the
generator records which sections each week covers; where it does not, the
sections are the application's assignments at generation time). Weekly
rows keep their per-week student count. The combined and single exports and
the show page use the same `totals()`.

## 8. Screens and copy

- Profile: the degrees card (section 3.1); the admin profile card lists
  degrees as "بكالوريوس — title — country — date" lines.
- Instructor application page: items as seeded; the undertaking row's form
  link; the timetable row appears after assignment with its note.
- Admin application page: same; the attention warning for bachelor
  experience unchanged.
- Everything formal undiacritized Arabic with `en` twins.

## 9. Security

- The form download is behind `auth` only (blank form, no data).
- Degree rows are owned by the instructor; profile authorization unchanged.
- Audit details for profile edits carry the flat degree keys only.
- The data migration runs in a transaction; it never deletes documents or
  files.

## 10. Testing

- Degrees: profile save with one, two and three degrees; bachelor required;
  master fields required when the box is checked; `highest_degree` derived;
  admin edit; rule 4b fires when a degree row changes; migration copies the
  old fields into one row and drops the columns.
- Items: plan for bachelor-only local, master foreign, doctorate with a
  foreign master; retired items absent everywhere; `assigned` item appears
  only after an assignment and is required for stage 2 and for a
  continuation's term papers; exemptions still only for transcripts.
- Document migration: `degree`/`equivalency` rows re-pointed per instructor.
- Check List: line marks for each case, lines 1, 11 for retired/assigned
  items, CV and transcripts absent.
- Form download: 200 inline for instructor and admin, redirect for guests.
- Student total: three weeks of the same two sections → total = sum of the
  two sections' seats, not three times.
- Lang parity, no tashkeel.

## 11. Delivery

One plan, subagent-driven: degrees table + profile + migration; items,
conditions, resolver, document migration, Check List; undertaking form;
student total; docs. Deploy = `deploy.sh` (migrations + seeder); then the
admin re-saves nothing: existing profiles are migrated.

## 12. Out of scope (milestone 7b)

Several open terms with a term selector, signed attestations uploaded by
the instructor, export of an attestation with the instructor's documents.

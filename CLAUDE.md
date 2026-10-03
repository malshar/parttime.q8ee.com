# CLAUDE.md — parttime.q8ee.com (المنتدبون / Part-timers & Interns Management)

> **Status (2026-10-03): live at https://parttime.q8ee.com — milestones 1–4,
> 5a and the (خ-3) spacing hotfix merged on `main` and deployed.** Laravel 12 app
> (`php artisan test` green, 383 tests passed, 1 skipped, on branch
> `milestone-6-year-approval` after the milestone 6 final fix wave). Milestone 1 (intake) shipped first;
> milestone 2 adds: the `complete` application status with a three-group
> attention list, a committee decision step (replacing direct approve/reject)
> with a consolidated rejection notice, reopening of withdrawn applications,
> audited admin profile edits (new `audit_log.details` column),
> a sections/meetings/assignments schema, an importer for the term's timetable
> exported from **jadawil** (CSV or XLSX, parsed with `phpoffice/phpspreadsheet`)
> at `/admin/sections/import`, the section list at `/admin/sections`, and
> instructor-to-section assignment at `/admin/assignments` with dashboard
> alerts. Milestone 3 (monthly (خ-3) attestation: generator, admin screens at
> `/admin/attestations`, Word/PDF and combined PDF via LibreOffice) is merged
> and deployed. Milestone 4 (term close rules, on-file documents, attestation
> fixes) is merged and deployed. **Milestone 5a (feedback round, branch
> `milestone-5a-feedback`) is implemented, Tasks 1–5: (1) the (خ-3) template
> fits one page per instructor with 9 pt table rows, fixed column widths, and
> the footer caption "المنتدب" merged into one run, course lines as "name
> code"; (2) nationality, employer and bank are lists via `App\Support\KuwaitLists`
> (38 government agencies plus private sector / other, 15 banks keyed by IBAN
> with IBAN pre-selecting the bank), nationality from `app.countries` (a
> legacy free-text value is kept as a `__keep` option until changed), profile
> forms keep non-sensitive values after errors; (3) expired sessions redirect to login with a message, header
> shows user name and role, admin application page shows applicant email,
> documents open in in-page pop-ups for PDFs and images; (4) sections and
> assignments tables show and sort by reference number with filters by
> reference, course, name and instructor; (5) jadawil (v2.4.13+) timetable
> export includes seat columns `الحد الأقصى`, `مسجلة`, `متبقية`, this app's
> importer reads them — re-export and re-import after both deploys so the
> student count prints on (خ-3). 5a covers feedback items
> 1–3, 5, 8–11 and 14–18; items 4, 6, 7, 12 and 13 (two-stage documents,
> transcript with exemptions, salary timing, multi-file upload) are
> milestone 5b.** **Milestone 5b (two-stage documents, branch
> `milestone-5b-two-stage`) is implemented, Tasks 1–9: checklist items carry
> stage/exemptable/official flags plus a `checklist_exemptions` table and
> multi-part documents; `ApplicationWorkflow::checklist()` orders stage-1
> rows before stage-2 and optional rows; submission and committee completion
> gate on stage-1 rows only, with stage-2 readiness (documents plus salary)
> tracked separately; an applicant may request an exemption from an
> exemptable stage-1 item instead of uploading it, decided by the admin;
> stage-2 documents (salary certificate, IBAN letter, employer approval,
> undertaking) are uploaded and reviewed only after committee approval;
> `basic_salary`/`total_salary` are optional at profile save and collected
> after approval via `PUT my/salary`; an upload may carry several files
> stored as parts of one document version; (خ-3) attestation generation is
> gated on stage 2, with a dashboard group and an attestation-page waiting
> list for instructors not yet there; the instructor and admin application
> pages show two sections (stage 1 / stage 2) and the approval mail lists
> outstanding stage-2 items; parked items (Task 9): `submit()` now locks the
> term row for its guards and status update, `degree_country` validates
> against the same country list as `nationality`, and deploy/test
> hardenings. Deploy note: `./deploy/deploy.sh` runs four migrations
> (checklist stage flags, `checklist_exemptions`, `documents.part`, nullable
> salary columns) and the `ChecklistItemSeeder` re-run; afterwards check
> PHP-FPM's upload limits (`deploy/DEPLOY.md` §4).** **Milestone 6 (year
> approval, continuation, renewal, branch `milestone-6-year-approval`) is
> implemented: a `committee_approvals` table records one row per instructor
> per academic year (kind `initial`/`renewal`, outcome `approved`/
> `not_renewed`); `applications.kind` (`initial`/`continuation`) and
> `applications.approval_id` link a same-year later-term application to its
> year's decision. A continuation application needs only the per-term
> papers (checklist items flagged `renews_each_term`) plus a new civil ID if
> the old one expired — no exemptions, no committee form — and is approved
> directly by the department completing the file ("اعتماد الاستمرار"). The
> renewal batch at `/admin/renewals` (nav link) lists last year's approved
> instructors with no row yet this year, records one meeting decision for
> the whole list (renewed / not renewed with a note), creates first-term
> continuation drafts for the renewed names (the target year's first term
> must already exist), mails `RenewalApproved`/`RenewalRefused`, exports an
> audited names-list Word document; deleting a renewal row is allowed only
> while its draft is still a draft (per row, not per batch). The instructor record at
> `/admin/instructors/{id}` (linked from the application page's profile
> card) shows approval history and an academic bundle ZIP export. The
> term-end page at `/admin/terms/{id}/closing` (linked from the terms
> index) shows attestation-month coverage, next-term continuation
> readiness, and counts. Deploy note: two migrations, no server steps; the
> renewal batch needs the target year's first term to exist.** The (خ-3)
> Word template is `resources/forms/kh3-template.docx`, rebuilt by
> `scripts/build-kh3-template.py` from the official blank form. Design:
> `docs/superpowers/specs/2026-09-28-parttime-system-design.md` (milestone 1),
> `docs/superpowers/specs/2026-09-29-milestone-2-assignment-design.md`
> (milestone 2), `docs/superpowers/specs/2026-09-30-milestone-3-attestation-design.md`
> (milestone 3), `docs/superpowers/specs/2026-09-30-milestone-4-term-close-on-file-design.md`
> (milestone 4),
> `docs/superpowers/specs/2026-10-03-milestone-6-year-approval-design.md`
> (milestone 6); final reviews with rulings and deferred minors are in
> `docs/superpowers/reviews/`. Deploy scripts/docs are in `deploy/` (`deploy/DEPLOY.md` for
> the server setup as done on 2026-09-30, `./deploy/deploy.sh` for routine
> deploys; mail goes through the server's mailcow as `mail.q8ee.com`).
>
> Application status pipeline: `draft` → `submitted` → `under_review` →
> (`incomplete` ⇄ `under_review`) → `complete` → committee decision →
> `approved` / `rejected`, or `withdrawn` at any point before a final
> decision (an admin can reopen a withdrawn application, which returns it to
> `draft`). Weekly load is stored as `weekly_minutes` (scheduled contact time
> from the imported timetable); hours are derived for display.
>
> Real applicant files continue to arrive in `../part-time/` (10 applicants
> as of 2026-09-27). See the root `../CLAUDE.md` for what is there and how
> to handle it (sensitive data) — never copy it into this repo.

## What this project is (intended)

A management system for the department's **interns and part-time instructors
(المنتدبون / part-timers)** at the Electrical Engineering Technology
Department, College of Technological Studies, PAAET, Kuwait.

Likely responsibilities (to confirm in brainstorming): register part-timers/
interns, track which courses/sections they teach, hours/load, contact and
official info, contracts/periods, and reporting for the department. It may
connect conceptually to the scheduling tool (jadawil — instructor names) and
to help.q8ee.com, but it is a **separate project/repo**.

## Observed intake (from `../part-time/`, filenames only — 2026-09-27)

Each applicant currently sends, by WhatsApp/email, some mix of:
- CV (PDF or DOCX, usually named after the person)
- Bachelor's and Master's certificates + transcripts (كشف درجات)
- Equivalency certificates (معادلة) for foreign degrees
- Civil ID (PDF/PNG), nationality certificate, personal photo
- Occasionally training-course certificates
Formats: PDF, JPG/PNG, DOCX, ZIP bundles. Names are Arabic full names.
This strongly suggests the system needs an **applicant/document intake and
checklist** capability (which required docs are present per applicant), not
only post-hire tracking — confirm in brainstorming.

## Official forms (reference, 2026-09-28)

PAAET has a fixed set of official forms for seconded instructors. Dr. Mishal
keeps the originals in OneDrive under
`committees/لجنة الجداول 2025-2026/schedule planning/2026-2027-Term-1/منتدبين/forms/`.
Transcriptions live in `docs/forms/`:
- `docs/forms/checklist.md` — the official **Check List** (page 7): 11 header
  fields per instructor + 12 required documents, 3 of them conditional
  (equivalency, social-insurance certificate, experience certificate). This is
  the authoritative definition of the per-applicant document checklist.
- `docs/forms/actual-practice-form.md` — نموذج (خ-3) **استمارة المزاولة
  الفعلية**: monthly teaching-hours attestation, one page per month, filled
  after teaching starts (checklist item 3). Header = 15 instructor fields
  (incl. bank account + salary → sensitive); body = week-by-week table of
  courses, student count, theory/practical/field hours, totals; 3 signatures.
  Weeks/dates/holidays are pre-printed per semester by the college. Strong
  candidate for **automatic generation** (Word/PDF) from system data.
- The OneDrive `forms/` folder also has a **filled** PDF of (خ-3) for a real
  instructor — sensitive, never copy its values.

## Open scope questions (answer before building)

- Exact records to manage (interns vs part-timers — same or separate?).
- Fields per person; any sensitive data (civil ID → encrypt + mask, follow
  the help.q8ee.com pattern).
- Who uses it: admin-only, or do part-timers log in? Roles?
- Link to jadawil instructor data / SWRSCHA, or standalone?
- Reports/exports needed (Excel/PDF, RTL).

## Stack (proposed — confirm)

Two established patterns to choose from:
- **Laravel 12 + MySQL + Bootstrap 5 RTL, Blade, no build step** — same as
  help.q8ee.com (best for a real multi-user admin app with auth/roles).
- **Self-contained single-file tool** — same as jadawil (best for a
  focused, mostly-client tool).

Pick per scope. Arabic-first, RTL, complete translation coverage from day one.

## Conventions (carry over from the other projects)

- **Formal, undiacritized Arabic** in all UI/shared copy (no tashkeel).
- Owner: Dr. Mishal E. AlSharidah — د. مشعل إبراهيم الشريده.
- Deploy target: the alsharidah.shop server (Apache vhost at
  parttime.q8ee.com); a `deploy/deploy.sh` like the sibling projects.
- Private GitHub repo when it starts.
- Sensitive data encrypted at rest + masked in UI, reveals audit-logged.

## Next step

Milestones 1–4, 5a and the (خ-3) spacing hotfix (page break folded into the
note run, verified live at one page per instructor) are deployed. The
synthetic test instructors were removed from production on 2026-10-02.
Also deployed 2026-10-02: the civil-ID check-digit fix (PACI weights
2,1,6,3,7,9,10,5,8,4,2), two **optional** checklist items (`transcript_bachelor`,
`transcript_master`; `checklist_items.optional` flag, `ChecklistPlan::optional`,
never block submission/completion, not printed on the Check List) and the
compact responsive profile form (`resources/views/instructor/_profile_fields.blade.php`,
shared by the instructor and admin pages).
Milestone 5b (two-stage documents, transcript with exemptions, salary
timing, multi-file upload, parked minors) is implemented on branch
`milestone-5b-two-stage`, Tasks 1–9.

Milestone 6 (year approval, continuation, renewal, branch
`milestone-6-year-approval`) is implemented: a per-instructor,
per-academic-year `committee_approvals` row (kind `initial`/`renewal`,
outcome `approved`/`not_renewed`) records the committee's yearly decision;
`applications.kind` (`initial`/`continuation`) and `applications.approval_id`
link a later-term application in the same year back to that decision. A
continuation application (same-year, later term) needs only the per-term
papers (checklist items flagged `renews_each_term`) plus a new civil ID if
the old one expired, and exemptions are refused; the department approves it
directly by completing the file ("اعتماد الاستمرار"), with no committee form.
The renewal batch at `/admin/renewals` (linked from the nav) lists candidates
approved last year with no row yet this year, records one meeting decision
(renewed, or not renewed with a note) for the whole list, creates first-term
continuation drafts for the renewed names (the target year's first term must
already exist), mails `RenewalApproved`/`RenewalRefused`, exports an audited
names-list Word document; deleting a renewal row is allowed only while its
draft is still a draft (per row, not per batch). The instructor record at
`/admin/instructors/{id}` (linked from the application page's profile card)
shows the approval history and an academic bundle ZIP export. The term-end
page at `/admin/terms/{id}/closing` (linked from the terms index) shows
attestation-month coverage, next-term continuation readiness, and counts.

Next step: deploy milestone 6 (two migrations, no seeder changes), then
create the next academic year's first term before running a renewal batch
against it. Then the first real applicants, and re-export/re-import the
term's jadawil timetable (v2.4.13 or later, with seat columns `الحد الأقصى`,
`مسجلة`, `متبقية`) so the student count prints on (خ-3).

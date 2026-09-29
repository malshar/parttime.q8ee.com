# CLAUDE.md — parttime.q8ee.com (المنتدبون / Part-timers & Interns Management)

> **Status (2026-09-29): Milestone 2 (committee workflow, sections import,
> assignments) implemented on branch `milestone-2-assignment`, deploy
> pending; final review + fix wave done.** Laravel 12 app (`php artisan test`
> green, 147 tests). Milestone 1 (intake)
> shipped first; milestone 2 adds: the `complete` application status with a
> three-group attention list, a committee decision step (replacing direct
> approve/reject) with a consolidated rejection notice, reopening of
> withdrawn applications, audited admin profile edits (new
> `audit_log.details` column), a sections/meetings/assignments schema, an
> importer for the term's timetable exported from **jadawil** (CSV or XLSX,
> parsed with `phpoffice/phpspreadsheet`) at `/admin/sections/import`, the
> section list at `/admin/sections`, and instructor-to-section assignment at
> `/admin/assignments` with dashboard alerts. Design:
> `docs/superpowers/specs/2026-09-28-parttime-system-design.md` (milestone 1)
> and `docs/superpowers/specs/2026-09-29-milestone-2-assignment-design.md`
> (milestone 2); final reviews with rulings and deferred minors are in
> `docs/superpowers/reviews/`. Deploy scripts/docs are in `deploy/` (`deploy/DEPLOY.md` for
> first-time server setup, `./deploy/deploy.sh` for routine deploys) — the
> first real deploy to parttime.q8ee.com has not been run yet.
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

Milestones 1 and 2 are built — see the status header above. Next: review and
merge `milestone-2-assignment` to main, then Dr. Mishal runs the first
deploy per `deploy/DEPLOY.md`; after that, brainstorm/plan milestone 3
(monthly (خ-3) attestation).

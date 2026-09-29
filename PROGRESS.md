# PROGRESS — parttime.q8ee.com

## Status (2026-09-29)
Milestone 2 (committee workflow, sections import, assignments) implemented
on branch `milestone-2-assignment`; tests green; deploy pending. Milestone 1
(intake) shipped first on branch `milestone-1-intake`
(`php artisan test` green, 89 tests; final whole-branch review + fix wave done — see
`docs/superpowers/reviews/2026-09-28-milestone-1-final-review.md`). See
`docs/superpowers/specs/2026-09-28-parttime-system-design.md` for the
milestone 1 spec and `docs/superpowers/specs/2026-09-29-milestone-2-assignment-design.md`
for the milestone 2 spec. Deploy scripts and docs are in `deploy/`; the
actual first deploy to the server has not been run yet — see
`deploy/DEPLOY.md`.
Real applicant documents are accumulating in `../part-time/` (10 applicants
as of 2026-09-27; sensitive — see root `../CLAUDE.md`).

## Next
1. Review + merge `milestone-2-assignment` to main.
2. First deploy per `deploy/DEPLOY.md` (server prerequisites, DB, .env,
   Turnstile keys, vhost).
3. Brainstorm milestone 3 (monthly (خ-3) attestation).

## Decided (2026-09-28 brainstorm)
Approach A: one Laravel 12 app, three milestones (intake → assignment →
attestation). Applicants self-register (Cloudflare Turnstile). (خ-3) is
generated from section meetings + term calendar, confirmed by the instructor.
Sections imported per term from Excel. Full details in the spec.

## In progress / blocked
(nothing yet)

## Log
- 2026-09-25 — scaffold folder + git init (1 commit).
- 2026-09-27 — context refresh: root CLAUDE.md added, intake observations
  recorded; still no code.
- 2026-09-28 — official PAAET Check List form transcribed to
  `docs/forms/checklist.md` (11 fields + 12 required documents, 3 conditional).
  Same day: نموذج (خ-3) استمارة المزاولة الفعلية transcribed to
  `docs/forms/actual-practice-form.md` (monthly hours attestation; auto-
  generation candidate). Those are the only two forms in the OneDrive
  `forms/` folder so far.
- 2026-09-28 — milestone 1 (intake) implemented, Tasks 1-13: auth with
  Turnstile, terms, encrypted instructor profiles, checklist, applications,
  document uploads, submission, admin review, printable Check List
  generation, and deploy scripts/docs (`deploy/`). `php artisan test` green
  (89 tests after the final-review fix wave). Final review and deferred
  minors: `docs/superpowers/reviews/2026-09-28-milestone-1-final-review.md`.
  Deploy pending server setup.
- 2026-09-29 — milestone 2 (committee workflow, sections import,
  assignments) implemented on branch `milestone-2-assignment`, Tasks 1-13:
  `complete` status + three-group attention list, committee decision
  replacing direct approve/reject, consolidated rejection notice, reopening
  withdrawn applications, audited admin profile edits (`audit_log.details`
  column), sections/meetings/assignments schema, jadawil CSV/XLSX importer
  (`phpoffice/phpspreadsheet`) at `/admin/sections/import`, section list at
  `/admin/sections`, assignment screen at `/admin/assignments`, dashboard
  alerts. `php artisan test` green. Design:
  `docs/superpowers/specs/2026-09-29-milestone-2-assignment-design.md`.
  Deploy pending.

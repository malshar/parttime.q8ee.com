# PROGRESS — parttime.q8ee.com

## Status (2026-09-28)
Milestone 1 (intake) implemented; deploy pending server setup. Built on
branch `milestone-1-intake` (Tasks 1-13 of the implementation plan;
`php artisan test` green). See
`docs/superpowers/specs/2026-09-28-parttime-system-design.md` for the full
system spec and `docs/superpowers/plans/2026-09-28-milestone-1-intake.md`
for the implementation plan. Deploy scripts and docs are in `deploy/`; the
actual first deploy to the server has not been run yet — see
`deploy/DEPLOY.md`.
Real applicant documents are accumulating in `../part-time/` (10 applicants
as of 2026-09-27; sensitive — see root `../CLAUDE.md`).

## Next
1. Dr. Mishal reviews the branch and, when ready, runs the first deploy per
   `deploy/DEPLOY.md` (server prerequisites, DB, .env, Turnstile keys, vhost).
2. Merge `milestone-1-intake` to main once deployed and verified.
3. Brainstorm/plan milestone 2 (assignment) and milestone 3 (attestation).

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
  (80 tests). Deploy pending server setup.

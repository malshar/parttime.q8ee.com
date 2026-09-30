# PROGRESS — parttime.q8ee.com

## Status (2026-09-30)
**Live at https://parttime.q8ee.com** since 2026-09-30 (first deploy of
milestones 1 + 2, merged on `main`). `php artisan test` green (149 tests).
Milestone 2 review record: `docs/superpowers/reviews/2026-09-29-milestone-2-final-review.md`
(deferred minors + rulings, start there for milestone 3). Deploy runbook and
scripts: `deploy/` (`DEPLOY.md`, "After the first deploy"). Milestone 1
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
1. Post-deploy: trusted proxies + backups scripts, browser checks (DEPLOY.md
   step 10), admin password change, import the term's jadawil export.
2. Brainstorm milestone 3 (monthly (خ-3) attestation).

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
  alerts. Final review + fix wave (SectionPolicy, profile lock on
  `complete`, audited admin edit form, `weekly_minutes` as the only stored
  load, `complete` in the status filter). `php artisan test` green (147
  tests). Design:
  `docs/superpowers/specs/2026-09-29-milestone-2-assignment-design.md`;
  review: `docs/superpowers/reviews/2026-09-29-milestone-2-final-review.md`.
  Deploy pending.
- 2026-09-30 — first production deploy: DB, `.env`, migrations, admin,
  Apache vhost + certbot, Turnstile, mail via the server's mailcow as
  `mail.q8ee.com` (mailcow's certificate had been expired since 2024-10;
  fixed with `SKIP_IP_CHECK` + `ADDITIONAL_SAN`, DNS-only records), q8ee.com
  SPF/DKIM/DMARC published. Test mail delivered. Scripts under `deploy/`.

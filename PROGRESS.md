# PROGRESS — parttime.q8ee.com

## Status (2026-09-30)
**Live at https://parttime.q8ee.com** since 2026-09-30 (first deploy of
milestones 1 + 2, merged on `main`). Milestone 3 (monthly (خ-3) attestation)
is merged on `main` (207 tests at merge); it is not deployed yet.
Milestone 4 (term close, on-file documents, parked attestation fixes) is
implemented, Tasks 1-5, on branch `milestone-4-term-close-on-file`
(`php artisan test` green, 234 tests, one skip without `soffice`); not
deployed yet. Milestone 2
review record: `docs/superpowers/reviews/2026-09-29-milestone-2-final-review.md`
(deferred minors + rulings, start there for milestone 3). Deploy runbook and
scripts: `deploy/` (`DEPLOY.md`, "After the first deploy"). Milestone 1
(intake) shipped first on branch `milestone-1-intake`
(`php artisan test` green, 89 tests; final whole-branch review + fix wave done — see
`docs/superpowers/reviews/2026-09-28-milestone-1-final-review.md`). See
`docs/superpowers/specs/2026-09-28-parttime-system-design.md` for the
milestone 1 spec and `docs/superpowers/specs/2026-09-29-milestone-2-assignment-design.md`
for the milestone 2 spec, `docs/superpowers/specs/2026-09-30-milestone-3-attestation-design.md`
for milestone 3, `docs/superpowers/specs/2026-09-30-milestone-4-term-close-on-file-design.md`
for milestone 4.
Real applicant documents are accumulating in `../part-time/` (10 applicants
as of 2026-09-27; sensitive — see root `../CLAUDE.md`).

## Next
1. Final review of milestone 4, merge to `main`, then deploy it with
   `./deploy/deploy.sh` (this also ships milestone 3), followed by the
   one-time setup and PDF visual check in `deploy/DEPLOY.md` ("Milestone 3:
   PDF export").
2. Remaining post-deploy items (`deploy/DEPLOY.md`, "After the first
   deploy"): step 10's browser checks; the admin password change and
   deleting `/root/parttime-admin-initial.txt`; the term's jadawil import.

## Decided (2026-09-28 brainstorm)
Approach A: one Laravel 12 app, three milestones (intake → assignment →
attestation). Applicants self-register (Cloudflare Turnstile). (خ-3) is
generated from section meetings + term calendar. Sections imported per term
from Excel. Full details in the spec.
Changed 2026-09-30 (milestone 3 design): (خ-3) is generated and printed by
the admin and signed on paper; there is no instructor confirmation step.

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
- 2026-09-30 — milestone 3 (monthly (خ-3) attestation) implemented on branch
  `milestone-3-attestation`, Tasks 1-7: `AttestationGenerator` reproducing
  the official summer 2026 form from section meetings + term calendar,
  `AttestationService` (listed/resolveMonth/generateMissing/update/
  regenerate/unlock/markExported, all audited), the admin screens at
  `/admin/attestations` (month picker, generate missing, per-attestation
  edit with locking, regenerate, unlock), the Word template
  (`resources/forms/kh3-template.docx`, rebuilt by
  `scripts/build-kh3-template.py`) and document builder, LibreOffice-backed
  PDF conversion with per-instructor and combined-monthly downloads, and
  dashboard alerts for months with missing or unexported attestations plus
  a link from the application page to the term's attestations. `php artisan
  test` green (195 tests). Final whole-branch review + fix wave: existing
  attestations stay visible/exportable when an instructor loses all
  assignments, CRLF-safe edits, temp-file cleanup + backup exclusion, page
  break moved to the end of the template block, fontconfig/SOFFICE_PATH
  deploy steps, and the review minors (207 tests). Deploy pending.
- 2026-09-30 — milestone 4 (term close, on-file documents, parked fixes)
  implemented on branch `milestone-4-term-close-on-file`, Tasks 1-5: closing
  a term refuses unfinished applications, withdraws drafts and drops
  archived ones; checklist rows derive "on file" from accepted documents of
  earlier applications, with a renewal-overrides table and an admin request
  for a fresh copy (covered by the notice email and shown on the instructor
  and admin screens and the printed Check List); attestation fixes: saves
  run under a row lock and refuse a stale form, regenerate is refused (and
  its button hidden) without assignments, nested `${` markers in free text
  cannot re-form a placeholder, LibreOffice runs in the output directory and
  a failed run leaves no PDF, a Friday/Saturday last teaching day on the
  1st/2nd is noted in the previous month, the (خ-3) note cell is no longer
  underlined (template rebuilt); unused `Term::attestations()` removed.
  `php artisan test` green (234 tests). Deploy pending.

# PROGRESS — parttime.q8ee.com

## Status (2026-10-02)
**Live at https://parttime.q8ee.com** since 2026-09-30 (milestones 1–4
deployed). Milestone 5a (feedback round) is implemented on branch
`milestone-5a-feedback`, Tasks 1–5 (`php artisan test` green, 271 tests passed,
1 skipped). Final reviews and design specs: milestones 1–4 specs in
`docs/superpowers/specs/`; reviews and deferred minors in
`docs/superpowers/reviews/`. Deploy runbook and scripts: `deploy/` (`DEPLOY.md`,
"Routine per-term setup" updated for jadawil v2.4.13+ seat columns).
Real applicant documents are accumulating in `../part-time/` (10 applicants
as of 2026-09-27; sensitive — see root `../CLAUDE.md`).

## Next
1. Deploy milestone 5a with `./deploy/deploy.sh` (feedback round, all items
   covered; remaining items in 5b).
2. Re-export the term's jadawil timetable (v2.4.13 or later, with seat
   columns `الحد الأقصى`, `مسجلة`, `متبقية`) and re-import at
   `/admin/sections/import` so the student count prints on (خ-3).
3. Verify the one-page PDF on the server.
4. Milestone 5b (two-stage documents, transcript with exemptions, salary
   timing, multi-file upload).

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
  a term refuses unfinished applications and withdraws drafts, and the
  unused `archived` term status is removed from the code; checklist rows derive "on file" from accepted documents of
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
- 2026-09-30 — milestone 4 final-review fix wave: an on-file copy stops
  counting once a profile field it certifies changes after acceptance
  (instructor self-edits now audited as `edit_profile`, field names only;
  shared `App\Support\ProfileDiff`); civil-ID expiry no longer changes final
  applications; end-to-end on-file test from submission to approval; term
  close runs under a row lock with a confirm dialog; messages shown once;
  neutral "need correction or updating" notice wording; docs corrected
  (milestone 3 deployed on 2026-09-30). `php artisan test` green (250
  tests, one skip).
- 2026-10-02 — milestone 5a (feedback round, all items) on branch
  `milestone-5a-feedback`, Tasks 1–5: (1) (خ-3) template fits one page per
  instructor, 9 pt table rows, fixed column widths, footer "المنتدب" merged,
  course lines as "name code"; (2) nationality, employer, bank via
  `App\Support\KuwaitLists` (38 agencies, 15 banks by IBAN), profile forms
  retain non-sensitive values after errors; (3) expired sessions redirect to
  login with message, header shows user name/role, admin app page shows
  applicant email, documents in in-page pop-ups; (4) sections/assignments
  tables show and sort by reference number with filters by reference, course,
  name, instructor; (5) jadawil v2.4.13+ seat columns `الحد الأقصى`,
  `مسجلة`, `متبقية` imported — re-export/re-import after both deploys for
  student count. `php artisan test` green (271 passed, 1 skipped).
  Deploy pending.

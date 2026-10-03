# PROGRESS — parttime.q8ee.com

## Status (2026-10-02)
**Live at https://parttime.q8ee.com** since 2026-09-30 (milestones 1–4
deployed). Milestone 5a (feedback round) is implemented on branch
`milestone-5a-feedback`, Tasks 1–5 plus the final-review fixes (`php artisan
test` green on the branch, 286 tests passed, 1 skipped). Final reviews and design specs: milestones 1–4 specs in
`docs/superpowers/specs/`; reviews and deferred minors in
`docs/superpowers/reviews/`. Deploy runbook and scripts: `deploy/` (`DEPLOY.md`,
"Routine per-term setup" updated for jadawil v2.4.13+ seat columns).
Real applicant documents are accumulating in `../part-time/` (10 applicants
as of 2026-09-27; sensitive — see root `../CLAUDE.md`).

## Next
1. Deploy milestone 5a with `./deploy/deploy.sh` (feedback items 1–3, 5,
   8–11, 14–18; items 4, 6, 7, 12, 13 are in 5b).
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
- 2026-10-02 — milestone 5a (feedback round) on branch
  `milestone-5a-feedback`, Tasks 1–5. Eighteen feedback items: 5a covers
  1, 2, 3, 5 (lists, retained values), 8, 9, 10, 11 (session expiry, header
  user, applicant email, document pop-up), 14, 15 (reference number,
  filters), 16, 17, 18 ((خ-3) one page, footer, student count); 5b covers
  4, 6, 7, 12, 13 (two-stage documents, transcript with exemptions, salary
  timing, multi-file upload). Tasks: (1) (خ-3) template fits one page per
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
  Final-review fixes: legacy free-text nationality kept on an untouched save
  (`__keep` option), empty nationality choice for new profiles, 16 more
  countries (incl. بدون), IBAN bank auto-select now attaches, translated
  field names in errors, choice fields validated with `Rule::in`, bank keys
  BBKU/BBME/CITI/QNBA, guest forms keep input on an expired token, SRI on
  Bootstrap JS, wrapping navbar, LIKE wildcards stripped from filters, sample
  (خ-3) written only with `KH3_WRITE_SAMPLE=1`. `php artisan test` green
  (286 passed, 1 skipped). Deploy pending.
- 2026-10-03 — milestone 5b (two-stage documents) on branch
  `milestone-5b-two-stage`, Tasks 1-9, covering feedback items 4, 6, 7, 12,
  13: checklist items carry stage/exemptable/official flags, a
  `checklist_exemptions` table and multi-part documents (schema);
  `ApplicationWorkflow::checklist()` orders stage-1 rows before stage-2 and
  optional rows; submission and committee completion gate on stage-1 rows
  only, with stage-2 readiness (documents plus salary) tracked separately;
  an applicant may request an exemption from an exemptable stage-1 item
  instead of uploading it, decided by the admin; stage-2 documents (salary
  certificate, IBAN letter, employer approval, undertaking) are uploaded and
  reviewed only after committee approval; `basic_salary`/`total_salary` are
  optional at profile save and collected after approval via `PUT my/salary`;
  an upload may carry several files stored as parts of one document
  version; (خ-3) attestation generation is gated on stage 2, with a
  dashboard group and an attestation-page waiting list for instructors not
  yet there; the instructor and admin application pages show two sections
  (stage 1 / stage 2) and the approval mail lists outstanding stage-2 items;
  Task 9 (parked items, deploy notes, docs): `submit()` now locks the term
  row for its guards and status update, `degree_country` validates against
  the same country list as `nationality` (`Rule::in`, dropping `size:2`/
  `alpha`), an optional stage-1 item can no longer render on both stage
  tables on either application page, and deploy/test hardenings
  (`DEPLOY.md` PHP-FPM upload-limit note, docx table-cell-count assertion,
  admin-name assertion). `php artisan test` green (342 passed, 1 skipped).
  Deploy pending.
- 2026-10-04 — milestone 6 (year approval, continuation, renewal) on branch
  `milestone-6-year-approval`, Tasks 1-6: a `committee_approvals` table
  records one row per instructor per academic year (kind `initial`/
  `renewal`, outcome `approved`/`not_renewed`); `applications.kind`
  (`initial`/`continuation`) and `applications.approval_id` link a
  same-year later-term application to its year's decision (two
  migrations); a continuation application needs only the per-term papers
  (checklist items flagged `renews_each_term`) plus a new civil ID if the
  old one expired, exemptions are refused, and the department approves it
  directly by completing the file ("اعتماد الاستمرار") with no committee
  form; the renewal batch at `/admin/renewals` (nav link) lists last
  year's approved instructors with no row yet this year, records one
  meeting decision for the whole list (renewed / not renewed with a note),
  creates first-term continuation drafts for the renewed names (the target
  year's first term must already exist), mails `RenewalApproved`/
  `RenewalRefused`, exports an audited names-list Word document
  (`export_renewal_list`); deleting a renewal row is allowed only while its
  draft is still a draft (per row, not per batch); the instructor record at
  `/admin/instructors/{id}` (linked from the application page's profile card) shows approval history
  and an academic bundle ZIP export (`export_academic_bundle`); the
  term-end page at `/admin/terms/{id}/closing` (linked from the terms
  index) shows attestation-month coverage, next-term continuation
  readiness, and counts. `php artisan test` green (375 passed, 1 skipped).
  Deploy pending.
- 2026-10-03 — milestone 6 final fix wave on branch
  `milestone-6-year-approval`: accepted exemptions now carry over to later
  applications the same way accepted documents do
  (`ApplicationWorkflow::onFileExemptions()`); a `not_renewed` row no
  longer blocks a fresh initial application (`committeeDecision('approved')`
  replaces it, the one exception to approval rows never being updated);
  `ApplicationWorkflow::convertToContinuations()` converts every
  not-yet-final initial application of the instructor's in the approved
  academic year to a continuation (a `complete` one returns to
  `under_review`), called from `committeeDecision()` and from
  `RenewalService::record()` (replacing the old "convert only the
  first-term draft" logic), and `reopen()` re-derives the kind the same way
  `start()` does; the renewals page now defaults to the newest `first`
  term's year when it has candidates; review minors: `markComplete()`
  refuses an unapprovable continuation, `RenewalService::record()` refuses
  a closed target first term, the renewal delete button and note field get
  confirmation/hint text, the candidates table shows employer and highest
  degree and links the last term, the names-list headers and the
  instructor-record page's masked civil ID/empty-state copy move to lang
  keys, the renewal list export audits only after the document is built,
  the academic bundle download is dated and its build() cleans up on
  failure, a continuation's stage-2 table hides satisfied on-file rows, and
  `committeeDecision()` checks `isFinal()` before `committee_not_needed`.
  `php artisan test` green (383 passed, 1 skipped). Deploy pending.

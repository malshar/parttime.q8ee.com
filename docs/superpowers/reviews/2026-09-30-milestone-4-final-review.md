# Final review — milestone-4-term-close-on-file (e0d36e7..26231c8)

### Strengths
- Every spec section is present: §3 close rules and blockers, §4.1 table, §4.2 derivation in the specified order, §4.3 uploading over an on-file item, §4.4 the fresh-copy request plus notice, §4.5 screens and ☑, §5 policies and audit, all seven §6 parked items, and §7 tests for nearly all of it.
- `ApplicationWorkflow::checklist()` (ApplicationWorkflow.php:50-75) follows the spec order exactly and fetches the on-file set only when a row needs it (`$onFile ??=`).
  - `onFileDocuments()` filters on `instructor_id`, excludes this application, and takes only terms that start earlier. Documents accepted for another instructor cannot leak, and `test_only_earlier_terms_and_other_applications_count` pins this.
- A renewal row cannot wrongly bring back "missing". The app never deletes documents, so once a fresh copy exists rule 1 always wins. `needsNotice()` also ignores renewal rows that already have a document. FreshCopyTest's last test pins Review Focus 4 from start to finish.
- `closeTerm()` withdraws drafts with one conditional UPDATE inside a transaction. It writes one `close_term` row with only the count and sends no email.
  - The blocker flash carries only name, status and URL.
  - `Instructor::hasLockedApplication()` ignores withdrawn applications and closed terms, so an instructor whose draft was withdrawn gets an editable profile back.
- The new route is inside the `role:admin` group with `authorize('review')` and `withoutScopedBindings()` (correct, since a checklist item is not a child of an application).
  - An unknown item code gives 404. An item this instructor does not need gives `fresh_copy_wrong_state`.
  - `ChecklistRenewal` fields are only ever set on the server.
- `AttestationService::update()` locks correctly. The generator's transaction writes the attestation row first (`generated_at` always changes), so the row lock serialises save against regenerate, and export rechecks after the lock. Stale ids are refused all-or-nothing.
- `holdsLastTeachingDay()` is right at the edges: Friday 1st, Saturday 1st/2nd, Friday 2nd (goes to the next month), and a block that starts in the previous month.
- Template check (I extracted both builds of `word/document.xml` into a temp directory): exactly two `<w:u w:val="single"/>` were removed, both inside the `${week_note}` cell (paragraph mark and run). Bold, font, size, `rtl` and `lang` are kept, and the other 17 zip entries and the rest of the XML are byte-identical. The regex cannot hit `<w:uCs>`.
- `plain()` loops until nothing changes (`$${{` becomes `cid1#1}`). `PdfConverter` sets the working directory and deletes a PDF left by a failed run.
- Checks run:
  - `php artisan test --compact`: 233 passed, 1 skipped (990 assertions).
  - `vendor/bin/pint --test`: fails only on `bootstrap/app.php` and vendor `lang/*/{auth,validation,…}.php`. None of those files are in this range, and `routes/web.php` passes.
  - `lang/ar/app.php` has 0 tashkeel characters, and `ar`/`en` key sets are identical in both directions.
  - The migration only creates a new table. `deploy.sh` already runs `migrate --force` plus the seeder and caches, so nothing new is needed on the server.

### Issues

#### Critical (Must Fix)
None.

#### Important (Should Fix)
1. **A document on file is never checked against later profile changes, so a stale copy prints ☑.** ApplicationWorkflow.php:62-69 (rules 4-5) and ChecklistDocument.php:64.
   - Rule 4 looks only at the current `civil_id_expires_on`. Example: an instructor renews the civil ID card and enters the new expiry. The earlier copy, of a card that has since expired, is now "على الملف", and the official Check List prints ☑ «صورة البطاقة المدنية سارية المفعول».
   - The same happens when `highest_degree` goes from master to PhD (the degree and equivalency copies on file are the master's), when the IBAN or bank changes (the IBAN letter on file is for the old account while خ-3 prints the new one), and when the employer changes (social insurance).
   - The department expects a ☑ to mean a copy that matches the current profile, above all for IBAN (money) and for "valid civil ID". Today it relies entirely on the admin opening every on-file link; FreshCopyTest even uses «الآيبان تغير» as the manual workaround.
   - Instructor profile edits are not audited (Instructor/ProfileController.php:24-37), so nothing can detect the change.
   - The spec is silent here, so this needs a ruling from Dr. Mishal. Two ways to fix:
     - Cheap: on the admin page, show a warning ("the profile changed after this copy was accepted") on on-file rows when `instructor.updated_at > source.reviewed_at`.
     - Proper: record which profile fields changed (field names only, via `wasChanged`) in an audit row. Then skip on-file for any item whose mapped fields (civil_id, degree, equivalency, iban, social_insurance) changed after `source.reviewed_at`.
2. **Missing end-to-end test that spec §7 requires** (tests/Feature/Admin/OnFileTest.php:150-161).
   - §7 says an on-file-only application "can be submitted and marked complete; the committee can approve it". Only the predicates `allRequiredUploaded` and `allRequiredAccepted` are tested.
   - Add one test that calls `submit()`, then `markUnderReview`, `markComplete()` and `committeeDecision('approved')` on an application whose non-renewing items are all on file.
   - Also make the "document wins over both" case use one item carrying both a renewal row and an earlier copy; today it tests degree and iban separately.
3. **The docs are inaccurate** (the ruled correction plus others found here):
   - CLAUDE.md:17 and PROGRESS.md:14-16 say milestone 3 is "deploy pending". It was deployed on 2026-09-30.
   - PROGRESS.md:27 says the milestone 4 deploy "also ships milestone 3".
   - CLAUDE.md:20 and the PROGRESS log say closing "drops archived ones". This reads as if archived terms or applications are deleted; what happened is that the unused `archived` status was removed from the code.
   - CLAUDE.md:122-127 ("Next step") still says to deploy milestone 3 and plan milestone 4.
   - Fix all of these in the fix wave.

#### Minor (Nice to Have)
1. **Closing a term can race with an instructor submitting.** ApplicationWorkflow.php:312-323.
   - The blocker check runs outside the transaction with no lock on the term. A draft submitted between the check and the UPDATE is left `submitted` on a closed term, and no one can decide it through the UI.
   - Two admins closing at once write two `close_term` rows.
   - Fix: inside the transaction, `Term::whereKey()->lockForUpdate()`, recheck `isOpen()` and the blockers. Have `submit()` read the term under a lock. Have TermController.php:68 also catch a bare `\DomainException`, which this makes reachable (see ledger T1).
2. **The close button has no confirmation** (resources/views/admin/terms/index.blade.php:48-50). Closing is now irreversible and withdraws drafts in bulk (reopen needs an open term). Add `onsubmit="return confirm(...)"` with a new ar/en key.
3. **Messages show twice.** layouts/app.blade.php:39-44 already renders `session('status')` and `$errors->all()`.
   - The new blocks at terms/index.blade.php:8-20 and admin/applications/show.blade.php:20-22 show the success message, `close_blocked` and the renewal error a second time.
   - Keep only the blocker list and drop the repeated `status` and error text. The same pattern already exists in the milestone 3 attestation views.
4. **Wording still says "rejected" for fresh-copy requests.** lang/ar/app.php:53, 102, 161-163 and the en twins.
   - For a request, the instructor's email says «هناك مستندات مرفوضة», the page shows «بعض المستندات مرفوضة», and the admin flash says «:count مستند مرفوض».
   - Neutral wording ("مستندات تحتاج إلى تصحيح أو تحديث") would be accurate for both cases.
5. **Past records change when the civil ID expires.** ApplicationWorkflow.php:63.
   - Rule 4 uses today's date even for final or closed-term applications. Once the card expires, an approved application's screen and reprinted Check List flip civil ID from ☑ to ☐.
   - Skip rule 4 when `$application->isFinal()`, or evaluate it at `decided_at`.
6. **Query count on the admin application page** (Admin/ApplicationController.php:43-50).
   - `checklist()` runs three times per render (`checklist`, `allRequiredAccepted`, `pendingRejectionNotices`), about 6 queries each. Each renewal row also lazy-loads `requester` (show.blade.php:119).
   - This is acceptable for a single-record page. Optionally let the predicates take pre-computed rows and eager-load `renewals.requester`.
7. **Social insurance never renews** (plan/spec issue, not the implementation). The seeder marks `social_insurance` `renews_each_term = false`, but the certificate attests current private-sector employment. Ask Dr. Mishal whether it should renew each term; that is a one-flag seeder change.
8. **Check production for an `archived` term before deploy.** `lang` no longer has `terms.statuses.archived`. No code path ever set it, but run `select count(*) from terms where status='archived'` first.

### Triage of ledger's deferred minors
- **T1, bare `DomainException` in `close()`:** fix together with Minor 1, where it becomes reachable; otherwise it may stay.
- **T1, blocker view-model built in the controller:** stay (the plan mandated it).
- **T1, stale `open|closed|archived` comment in the terms migration:** may stay (comment only).
- **T2, `checklist()` queried again by the predicates:** stay (see Minor 6).
- **T2, redundant `whereKeyNot`:** stay (harmless defence).
- **T2, unused `$draft` in a test:** stay.
- **T2, ☐ for on_file until Task 4:** resolved (ChecklistDocument.php:64, tested).
- **T3, TOCTOU on the on-file check:** stay. The only editable unfinished status is `incomplete`, and a request that races an upload just leaves a harmless renewal row next to a real document.
- **T3, `routes/web.php` not Pint-ed:** resolved (`pint --test` does not list it).
- **T3, mail `@elseif` showing a stale renewal reason:** stay. It cannot happen, because `RejectDocumentRequest` requires a reason when rejecting.
- **T3, no combined-precondition ordering test:** stay.
- **T4, literal `'accepted'` next to `STATE_ON_FILE`:** stay (cosmetic).
- **T4, no test for the closed-term branch of the form gating:** stay (the service refusal is tested).
- **T5, `firstOrFail` inside `update()`:** stay (cannot happen today).

### Declined to judge
- An instructor-side link to the earlier on-file document: the spec only asks for badge, source term and optional upload.
- Withdrawing a fresh-copy request: spec §9 puts it out of scope.
- Reopening a closed term or archiving: spec §9 puts them out of scope.
- The spec's `submitted → under_review → incomplete` path versus the direct transition: the ledger ruled it, and the end state is the same.
- Pint failures in `bootstrap/app.php` and vendor `lang/*` files: they exist before this branch and fall outside the range.
- The PDF output of the rebuilt template: it needs `soffice` and Dr. Mishal's visual check (spec §6.6). I checked only the XML.
- Plan requirements drifting with the current profile for past applications: milestone 1 behaviour, not changed here.

### Recommendations
- Get a ruling from Dr. Mishal on Important 1. At least the admin warning should ship before instructors return for a second term. That is not urgent for this term, because no earlier accepted documents exist in production yet.
- Fix wave: Important 2 and 3, then Minor 1-4 (all small), and run the SQL check in Minor 8 before `./deploy/deploy.sh`.

### Assessment
**Ready to merge?** With fixes
**Reasoning:** The implementation matches the spec, the on-file rule cannot leak across instructors, and the suite is green. It should wait for the fix wave: a ruling on stale on-file copies (civil ID, degree, IBAN), the missing §7 test from submission to approval, and the docs corrections.

## Post-review fix wave (commits 7ce7a81, 0211f35, 324ce19, 5e5cf89, 52b2f7f, 52d0061)

One fix dispatch covered the three Important findings and five minors; one
scoped re-review (26231c8..52d0061) returned "all findings addressed, no new
Critical/Important breakage". Suite after the wave: 250 passed, 1 skipped
(the LibreOffice unit test on a Mac without `soffice`), 1041 assertions.

- **F1 profile changes invalidate on-file copies** (ruling; the spec was
  silent). Instructor self-edits are audited as `edit_profile` with the sorted
  changed field names only, through the shared `App\Support\ProfileDiff` that
  the admin edit already used. `ChecklistItem::PROFILE_FIELDS` maps each item
  to the profile fields it certifies. `onFileDocuments()` drops a source when
  an `edit_profile` or `admin_edit_profile` row newer than the copy's
  acceptance names one of those fields, scoped to this instructor. Rule 4b is
  skipped for final applications, like the civil-ID expiry rule, so a past
  application's Check List never flips. Spec §4.2 carries the rule.
- **F2** end-to-end test from submission to committee approval on on-file
  rows; "document wins over renewal and earlier copy" on one item.
- **F3** docs corrected: milestone 3 deployed 2026-09-30; milestone 4 deploy
  ships milestone 4 only; "archived" wording; next step; DEPLOY.md pre-deploy
  check `select count(*) from terms where status='archived'` must be 0.
- **M1** term close re-reads the term under `lockForUpdate()`, rechecks
  open state and blockers inside the transaction; the controller catches
  the bare `DomainException`.
- **M2** confirm dialog on the close button. **M3** messages shown once
  (the layout already prints status and errors). **M4** neutral wording for
  the consolidated notice ("تحتاج إلى تصحيح أو تحديث"). **M5** civil-ID
  expiry rule skipped for final applications.

### Parked from the fix wave

- `submit()` does not take the term lock: a submission in the same instant
  as a close can land on a closed term. Rare; visible on the applications
  list; milestone 5.
- Profile edits made before this deploy were never audited and cannot drop
  an on-file copy. No earlier accepted documents exist in production, so
  nothing is affected.
- Docs say "250 tests"; the suite has 251 including the skipped one.

## Rulings made during execution (from the ledger)

- Pre-flight: a fresh-copy request moves `submitted` straight to `incomplete`
  (spec said via `under_review`; end state identical).
- Task 2: the brief's draft fixture collided with the unique (term,
  instructor) key; the implementer used a distinct earlier term.
- Task 5: the nested-placeholder test expectation was corrected to
  `cid1#1}` (the brief miscounted the strip passes); the implementer fixed a
  temp-file leak in `TermCloseTest` outside its file list.
- Final review "declined to judge", all accepted: no instructor-side link to
  the earlier document; withdrawing a request and reopening a term are out of
  scope; direct `submitted → incomplete`; Pint failures outside the range are
  pre-existing; the PDF output of the rebuilt template is a visual check at
  deploy; plan drift for past applications is milestone 1 behaviour.

## Open question for Dr. Mishal

- Should the social-insurance certificate (`social_insurance`) renew each
  term? It attests current private-sector employment; today it is on file
  once accepted. One flag in `ChecklistItemSeeder`.

## Deferred minors (carry forward)

- Task 1: the blocker view-model is built in the controller (plan-mandated);
  stale `open|closed|archived` comment in the terms migration.
- Task 2: `checklist()` re-queried by the completeness predicates (three
  runs per admin page render); redundant `whereKeyNot`; unused `$draft` in a
  test.
- Task 3: TOCTOU on the on-file check in `requestFreshCopy()`; the mail
  template could show a stale renewal reason on a rejected row without a
  reason (cannot happen: rejection requires a reason); no combined-precondition
  ordering test.
- Task 4: literal `'accepted'` beside the `STATE_ON_FILE` constant in
  `ChecklistDocument`; no test for the closed-term branch of the form gating.
- Task 5: `firstOrFail` inside `update()`'s transaction would 404 if the row
  vanished (unreachable today).
- Final review: query count on the admin application page; requester
  lazy-loaded per renewal row.

# Milestone 5b — Final Review Record (2026-10-03)

Branch: `milestone-5b-two-stage` (from `main` @ 779cc33). Spec:
`docs/superpowers/specs/2026-10-02-milestone-5b-two-stage-documents-design.md`.
Plan: `docs/superpowers/plans/2026-10-02-milestone-5b-two-stage-documents.md`.

Process: nine tasks, each with a fresh implementer and a task review (two on
the most capable model: the gate logic in Task 2 and the multi-file upload in
Task 6); Task 6 took one fix round; one whole-branch review on the most capable
model; one fix wave; one scoped re-review. Suite: 347 passed, 1 skipped
(pre-existing) at the end.

## Whole-branch review verdict

"With fixes." No Critical finding. Three Important findings, all fixed in the
fix wave (commit 75062cd):

1. **`documents.part` migration order on MySQL 8.** The old composite unique
   `(application_id, checklist_item_id, version)` was dropped before the new
   one existed; on production it is the only index serving the
   `application_id` foreign key (verified read-only with `SHOW INDEX`), so the
   drop would have failed with error 1553 and left the deploy half-migrated.
   Fixed: the new unique is created first, then the old one dropped; `down()`
   mirrors it.
2. **Notify-rejections reachable on withdrawn/rejected applications** after
   Task 4 removed the `isFinal()` guard for the approved case. Fixed: allowed
   only before a final status or while approved, in both the page and the
   workflow; tested on a withdrawn application.
3. **Re-requested exemption while `incomplete` stalled the application** (no
   automatic re-submission, unlike an upload). Fixed: the request applies the
   `afterUpload()` rule; tested (status → submitted, admin mail).

Also fixed in the wave (cheap, reviewer's minors): `requestExemption()`
refuses `on_file` rows; `decideExemption()` refuses when a document now
supersedes the request; duplicate pending-exemption text removed from the
instructor table; Cloudflare's 100 MB request-body cap noted in `DEPLOY.md`.

## Rulings made during execution (ledger)

- Task 2: the spec (§3.4) wrongly said the salary columns were already
  nullable; migration `2026_10_03_100003` makes them nullable. Accepted; spec
  corrected in Task 9.
- Task 1/2/4: existing tests that enumerated the old six required items got the
  two transcripts added, or swapped `iban` (now stage 2) for a stage-1 item;
  setups only, assertions unchanged.
- Task 5: one out-of-brief line (`setRelation('instructor', …)`) in
  `ProfileController::update()` to fix a stale relation cache. Accepted.
- Task 6: the reviewer's "500 on a non-array `files` post" was elevated to
  Important and fixed in the task's fix round; the FileBag-only legacy
  normalisation replaced the `$convertedFiles` reset.
- Task 7: the attestation index view nests the empty-rows check inside the
  term branch so the waiting list renders even with no rows. Accepted.
- Task 8: the plan's `stage1` filter lacked `! optional` (latent duplicate
  rendering of an optional stage-1 item); fixed in Task 9 with a test.
- Task 9: the submit race test proves the recheck and the atomicity, not lock
  contention (would need a second DB connection). Accepted as evidence.

## Declined to judge (whole-branch reviewer), with the executor's rulings

- In-flight production applications without transcripts would get stuck under
  review. Production has two draft applications only (counted read-only
  before merge), both with transcripts accepted. No action.
- Salary "0" counts as entered; readiness keeps a salary entered in an earlier
  term. Per spec. No action.
- Validation before authorization on the new form requests (422 vs 403 for a
  stranger); upload version computed without a row lock. Pre-existing patterns.
- The approval mail lists every stage-2 item even when one is on file. Per
  spec §9.
- **Exemptions do not carry over between terms** (on-file applies to accepted
  documents only). The spec is silent. Open decision for Dr. Mishal.

## Deferred minors (carry forward)

- Checklist rebuilt several times per admin page view, twice per approved
  application in dashboard/attestation listing, `stageTwoComplete()` recomputes
  `stageTwoMissing()`; history table counts parts per row (N+1). Fine at the
  current scale.
- `DocumentPolicy::create` allows stage-2 uploads while editable (page hides
  the control); a stage-2 document rejected before approval still sets
  `incomplete`.
- Spec §6 "superseded exemption shown as superseded": the admin partial hides
  it instead.
- Salary form appears only while the salary is missing; a typo can only be
  corrected by the admin until the term closes.
- A `PostTooLargeException` shows a bare 413 page.
- Copy: `parts_count` ("‏:n ملفات") reads oddly for 2; the stage-2 hint still
  shows after approval; the applicant's "المطلوب لاستكمال الملف" list includes
  documents awaiting review; dead lang keys `app.profile.salary_not_yet`,
  `app.exemptions.pending`; unused `undecidedExemptions` view variable;
  placeholder-only inputs lack `aria-label`; `en` `applications` key order
  differs from `ar`.
- Tests: exemption display states on both pages; `stage2_complete` on the
  admin page; admin-page assertion for the optional dedup; non-head review id
  and `reviewed_by/at` parity across parts; ZIP case reaching `after()`;
  `salary_later` toggle; clear-and-resubmit salary edge; a true
  lock-contention test for `submit()`.
- `Document::parts()` builds from instance attributes and would not work with
  `with('parts')` eager loading (comment it); no integer cast on `part`;
  redundant `sortBy('part')`; by-reference `$paths` across transaction
  retries; migration `100003` `down()` fails once a null salary exists
  (expected).

## Deploy notes

- `./deploy/deploy.sh` runs four migrations (`2026_10_03_100000`–`100003`)
  and re-runs the seeder (14 items, flags).
- Then check PHP-FPM: `upload_max_filesize = 10M`, `post_max_size = 110M`,
  `max_file_uploads = 20`, reload `php8.4-fpm`. Cloudflare caps request bodies
  at 100 MB on free/Pro plans.
- Effect on existing data: approved applications already satisfied every item
  under the old gates, so they stay listed for attestations; drafts simply
  follow the new stage-1 gate.

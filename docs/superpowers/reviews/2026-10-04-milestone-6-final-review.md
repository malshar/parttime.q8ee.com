# Milestone 6 — Final Review Record (2026-10-04)

Branch: `milestone-6-year-approval` (from `main` @ 0170603). Spec:
`docs/superpowers/specs/2026-10-03-milestone-6-year-approval-design.md`.
Plan: `docs/superpowers/plans/2026-10-03-milestone-6-year-approval.md`.

Process: six tasks, each with a fresh implementer and a task review (the
continuation gates and the renewal batch on the most capable model); Tasks
2–5 each took one fix round; one whole-branch review on the most capable
model; one fix wave; one scoped re-review. Suite: 383 passed, 1 skipped
(pre-existing) at the end.

## Whole-branch review verdict

"With fixes." No Critical finding. Four Important findings, all confirmed by
the reviewer with scratch tests and all fixed in the fix wave (commit
e273a1a):

1. **Accepted exemptions did not carry over.** An instructor exempted from a
   transcript in the initial application had that row `missing` on every
   continuation, and exemptions are refused there. Ruling: accepted
   exemptions of earlier applications resolve as `exempted`, the same way
   accepted documents resolve `on_file` (`onFileExemptions()`). This also
   settles the 5b open question: exemptions carry over between terms.
2. **A `not_renewed` instructor applying again crashed the approval** on the
   unique key. Ruling: re-application is allowed; approving it replaces the
   `not_renewed` row with the initial approved row inside the transaction
   (the one exception to "rows are never updated", recorded in spec §4.1).
3. **Initial applications could get stuck once the year was approved by
   another route** (renewal recorded after the instructor had already
   submitted, a withdrawn application reopened, two open terms in one
   year). Ruling: creating an approved year row converts the instructor's
   other non-approved, non-rejected initial applications of that year to
   continuations (`convertToContinuations()`), a `complete` one returning to
   `under_review`; `reopen()` re-derives the kind.
4. **The renewals page defaulted to the wrong year** once the next year's
   first term existed (the precondition for the batch). Ruling: default to
   the newest first term's year when it has candidates.

Also fixed in the wave (reviewer's minors): refusal of a continuation
approval without a year row; refusal when the target first term is closed;
confirm on renewal delete; employer, degree and last-term link on the
candidates table; note hint ("sent to the instructor"); names-list headers
through lang; audit after a successful build for the list; bundle file name
with the date; bundle temp files cleaned on failure; instructor page
direction and empty text; no "newer copy" control on a continuation's
on-file stage-2 rows; `isFinal()` guard before `committee_not_needed`; docs
wording.

## Rulings made during execution (ledger)

- Task 2: `requiredMissing()` means what the applicant still has to provide
  (rows `missing` or `rejected`), not "unsatisfied"; the plan's wording had
  listed pending uploads as required.
- Task 2 → 3: the admin page's year-approval line shows the approval row's
  kind (initial / renewal), not the application's.
- Task 3: a normal resubmit says `already_recorded`; the controller catches
  only `UniqueConstraintViolationException`; deleting a renewal row also
  removes the draft's stored files; an existing initial draft is converted
  rather than ignored; the names list carries a fixed signature title.
- Task 4: a test-client limitation is solved in the test (`sendContent()`),
  not with production code; audit after a successful build.
- Task 5: the term-end rows are computed once per request.

## Declined to judge (whole-branch reviewer), with the executor's rulings

- `Term::current()` is the latest open term, so creating next year's first
  term early redirects instructors and admin defaults to it. Pre-existing;
  the deploy note tells the admin to create it close to the batch.
- Approvals spanning several years, editing approval rows, minutes and
  assignment letters, auto-creating second/summer continuations: out of
  scope (§12).
- Process-global PhpWord escaping, UTF-8 ZIP entry names, MySQL round trip
  (standard builders; rehearsed at deploy), concurrent batch recording
  (unique key + narrow catch).
- "Continuations not started" counts instructors who were not renewed: per
  the spec's definition.

## Deferred minors (carry forward)

- Renewal delete now reaches every application linked to the row (all
  drafts deleted with their files; any non-draft blocks the delete); the
  confirm text says "its draft" in the singular. Reverting converted
  applications to initial would be gentler.
- `convertToContinuations()` writes no audit row and sends no notice when a
  `complete` application returns to `under_review`.
- Two simultaneous committee approvals for the same instructor and year can
  both pass `hasApprovalFor()` outside the transaction; the second fails on
  the unique key with a 500 (pre-existing shape, very unlikely).
- `resolveYear()` may default to a year whose first term is closed (the
  batch then refuses clearly); `candidates()` runs twice per page load.
- `record()` reuses an existing first-term application whatever its status,
  so a rejected initial one is linked from the renewal mail without a new
  draft.
- Deleting a renewal row deletes a converted draft that the instructor
  started (spec says "deletes the draft too"); reverting it to initial would
  be gentler.
- Migrations split into two `Schema::table` calls without an in-code
  comment; the `committee_not_needed` guard also blocks a `rejected`
  decision when an approved row exists (intended).
- No test asserts the names-list signature title; `renewals.outcomes.not_renewed`
  reused on the instructor page; the close form on the term-end page lacks
  `btn-sm`.
- `PROGRESS.md`'s top status header predates 5b.

## Deploy notes

- `./deploy/deploy.sh` runs two migrations (`2026_10_04_100000`,
  `2026_10_04_100001`); the seeder is unchanged.
- Production effect: existing applications get `kind = initial`; there are
  no approval rows yet, so the first committee decisions create them. The
  two existing drafts are unaffected.
- Before running a renewal batch: create the next academic year's first
  term, close to the batch date.

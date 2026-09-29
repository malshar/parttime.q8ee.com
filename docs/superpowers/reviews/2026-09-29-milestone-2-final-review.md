# Final review — milestone-2-assignment (bd81376..9da7fae)

Reviewed in passes, no subagents, working tree untouched (`git status` clean before and after; `pint --test` and `php artisan test` are read-only): (1) milestone 2 spec, plan header/constraints/review focus/file structure, the ledger, milestone 1's final review; (2) the diff indexed with `grep -n '^diff --git'`, then every application hunk read in slices — `app/`, `routes/web.php`, `database/{migrations,factories}`, `resources/views`, `lang/{ar,en}/app.php`, `tests/`, `deploy/DEPLOY.md`, `composer.json`, `CLAUDE.md`, `PROGRESS.md` (the `composer.lock` hunk and the plan/spec hunks were checked only for the PhpSpreadsheet requirement and the implementation note); (3) working-tree reads at HEAD where hunks were cut off (`Instructor.php`, `Instructor/ProfileController.php`, `Instructor/ApplicationController.php`, `ApplicationPolicy.php`, `bootstrap/app.php`, `admin/applications/index.blade.php`, `deploy/deploy.sh`, `config/database.php`, the sessions migration, `composer.lock`). `php artisan test --compact` run by me: 142 passed, 527 assertions, 6.0 s. Lang parity verified programmatically: 295 keys in `ar`, 295 in `en`, zero difference; no tashkeel in `lang/ar/app.php` or views; no `{!! !!}` anywhere.

### Strengths

- Group 1 is a faithful implementation of spec §3. The pipeline is exactly `draft → submitted → under_review ⇄ incomplete → complete → approved | rejected`, `withdrawn` from any non-final state, `withdrawn → draft` by admin reopen. Every transition is guarded in `ApplicationWorkflow` (status, open term, all-required-accepted), every guard has a feature test, and the old `approve`/`reject` routes, controller methods, lang keys and tests are gone with a test asserting the routes no longer exist. No dead references remain (`grep` for `applications.approve|reject` finds nothing).
- Committee decision: a `FormRequest` with `before_or_equal:today`, `requiredIf` note on rejection, `Rule::in` on outcome; `isFinal()` guard first so the two-tab scenario (Review Focus #3) is refused with "already final" and no second email (tested, including `assertSent(..., 1)`); outcome select preserved after validation failure and never defaulting to "approved" (the Task 2 fix, tested).
- Consolidated rejection notice is done right: `reviewDocument()` no longer mails; `documents.notified_at` marks what has been communicated; `pendingRejectionNotices()` derives the button state from the latest versions; the one email carries *all* currently rejected latest documents; re-upload plus re-rejection makes the item pending again (tested end to end).
- Admin profile edit reuses `ProfileRequest` through a small subclass that swaps the owner id for the civil-ID uniqueness check, so the rules cannot drift; the changed-field list is computed against decrypted originals before `fill()` so encrypted casts do not pollute it; the `details` column carries names only and the test asserts no value and no `iban` appear.
- Group 2 has clean seams: `JadawilParser` is a pure function to immutable-ish value objects with row-numbered errors and warnings, `SectionImporter::plan()` is a side-effect-free diff and `apply()` recomputes the plan inside the transaction, `AssignmentService` owns the rules and the recompute. The importer never deletes an assigned section (flag instead), the FK is `restrictOnDelete` as ruled, and the reappearing section is unflagged — all tested, including Review Focus #2 (assigned section's meetings change → replaced, assignment kept, hours recomputed to 75).
- The session payload is `serialize()`d and `unserialize()`d with `allowed_classes` restricted to the three value objects; the uploaded file is read with `$file->get()` and never written anywhere (the XLSX branch uses a temp file it unlinks in `finally`); nothing personal beyond the export's instructor names reaches the session.
- Assign race handled: unique index on `section_id` plus `UniqueConstraintViolationException → DomainException`, so a double click yields the translated error rather than a 500 (tested with a partial mock that reproduces the losing request's view).
- All state-changing admin actions write `audit_log` (`mark_complete`, `committee_decision`, `notify_rejections`, `reopen_application`, `admin_edit_profile` + details, `import_sections` with counts in both `action` and `details`, `assign_section`, `unassign_section`); all mail goes through `safeSend`; every new form has `@csrf`; confirm dialogs use `@js()`; every export-derived string is rendered through `{{ }}`.
- Closed-term read-only holds across all new actions: `markComplete`, `committeeDecision`, `notifyRejections`, `reopen`, `apply()`, `assign`, `unassign` all throw `term_closed`; views hide the forms; import preview refuses when no term is open. Each has a test.
- Fixture data is synthetic (placeholder names, invented course codes), the XLSX test builds its workbook in the test, and `Exceptions::fake()` is used to assert the reader failure is reported.
- Docs are honest: CLAUDE.md/PROGRESS.md state the branch and pipeline, DEPLOY.md gains the per-term import step, the spec carries an implementation note for the PhpSpreadsheet substitution.

### Issues

#### Critical (Must Fix)

None. No data-exposure path, broken auth, data-loss path or broken core flow found.

#### Important (Should Fix)

1. **Instructor profile lock does not cover the new `complete` status.** `app/Models/Instructor.php:89-95` `hasLockedApplication()` lists `submitted, under_review, approved`; `app/Http/Controllers/Instructor/ProfileController.php:27` is the only guard. An applicant whose file is `complete` (every document verified against the typed civil ID / IBAN / sector, waiting for the committee) can change civil ID, IBAN, salaries or `employer_sector` from their own profile page. `committeeDecision()` does not re-check `allRequiredAccepted()`, so a sector change that makes a new item required is not caught and the committee approves a file whose derived checklist is no longer satisfied; the printed Check List and later the (خ-3) carry the new civil ID. This is milestone 1's Important #2 reappearing for the state that most needs the lock. Fix: add `Application::STATUS_COMPLETE` to the `whereIn` list (and to the doc-comment), add one assertion to `tests/Feature/Instructor/ProfileTest` (or `CompleteStatusTest`) that `PUT instructor.profile.update` is refused while `complete`.

2. **Admin profile edit form renders plaintext sensitive data with no audit row.** `app/Http/Controllers/Admin/ProfileController.php:17-22` `edit()` returns `admin/applications/profile.blade.php`, which pre-fills `civil_id`, `iban`, `basic_salary`, `total_salary` decrypted (`profile.blade.php:18,88,92,94`). The system's rule since milestone 1 is "masked on every screen, reveal audit-logged"; the application page enforces it (`reveal_sensitive`). The edit link is on the profile card next to the reveal button, so an admin can read everything without a trace by clicking "تعديل البيانات" and cancelling. The `update()` side is fine (names-only details, `dontFlash` verified by test). Fix: `AuditLog::record($request->user()->id, 'reveal_sensitive', $application)` (or a distinct `open_profile_edit`) in `edit()`, and one `assertDatabaseHas` in `AdminProfileEditTest`.

3. **Spec §6 policies are missing; the new Section/Assignment/Import controllers rely on the route middleware alone.** Spec: "All new routes admin-only (`role:admin` + policies `SectionPolicy`, `AssignmentPolicy`)"; plan Global Constraints: "additionally checked by `$this->authorize(...)` where a model is involved." `app/Policies/` contains only `ApplicationPolicy` and `DocumentPolicy`; `AssignmentController::store/destroy` take a bound `Section` and call no `authorize`; `SectionController`, `SectionImportController` likewise. Instructors do get 403 (tested for every route) because of `role:admin`, so the practical risk today is nil, but it is a stated requirement and it breaks the milestone 1 convention that every admin controller method also calls a policy. Fix (small): one `SectionPolicy` with `viewAny/import/assign` returning `$user->isAdmin()`, `$this->authorize('viewAny', Section::class)` / `authorize('assign', $section)` in the seven actions, register nothing (auto-discovery). This is a plan gap (the plan's file structure omits the policies) rather than an implementer slip.

4. **`weekly_hours` schema: the spec deviation is unjustified and is about to become permanent.** `database/migrations/2026_09_29_110003_change_weekly_hours_on_applications_table.php` adds `weekly_minutes` and `weekly_hours_decimal` and leaves the integer `weekly_hours` (always 0, no reader anywhere: `grep weekly_hours app resources` finds only the label helper). The ledger accepted this for "SQLite alter limits", but Laravel 12 (installed: 12.69.2) modifies SQLite columns natively (`->change()` rebuilds the table, no doctrine/dbal). Milestone 3 will read minutes, the UI reads `weeklyHoursLabel()` (minutes), and nothing reads either hours column, so the branch ships one dead column and one confusingly named one. There is no production database yet, so the cheapest moment is now. Fix: in that migration `dropColumn('weekly_hours')` and add `decimal('weekly_hours', 5, 1)` (or simply keep `weekly_minutes` only and drop both hours columns, since hours are derived); update `$fillable` and `AssignmentService::recomputeHours()`, `Section::hoursFromMinutes` stays. `down()` mirrors it.

5. **Admin applications index cannot filter on `complete`.** `resources/views/admin/applications/index.blade.php:18` hard-codes `['draft','submitted','under_review','incomplete','approved','rejected','withdrawn']`. The dashboard's committee group covers only `Term::current()`, so an admin looking for "awaiting committee" files on any other term has no list. The dashboard badge row was updated (`dashboard.blade.php`), this list was not. Fix: add `'complete'` (ideally derive both lists from one constant on `Application`).

#### Minor (Nice to Have)

1. `app/Services/ApplicationWorkflow.php:184-206` `committeeDecision()` is check-then-update with no lock; two truly simultaneous submits both pass `isFinal()` and both mail. Cheap hardening: `Application::whereKey($application->id)->where('status', STATUS_COMPLETE)->update([...])` and throw `already_final` when the affected-row count is 0.
2. `ApplicationWorkflow::reopen()` (`:208-219`) nulls `decided_at` only; `complete_at`, `reviewed_at`, `submitted_at` stay from the previous life. Harmless today (dashboard groups filter by status) but `complete_at` will read as a stale "file completed on" once the reopened file is completed again only if `markComplete` is skipped — null all three for a clean draft.
3. `app/Services/Sections/JadawilParser.php:47-56` matches header names by exact string. If the real export writes `إلى` (hamza) or has a trailing space inside the quotes, the import stops with a clear "missing column" error — safe, but one `ArabicNameNormaliser::normalise()` on both sides (and on the activity cell, `:110`, so `محاضره` stops warning) costs nothing and removes the most likely first-import surprise.
4. Same file, `:32-33`: lines are split on newlines before `str_getcsv`, so a quoted course name containing a newline would break the row. jadawil's export probably never emits one; note it in the parser doc-comment or parse the whole string with a CSV reader.
5. XLSX vs CSV numeric cells: `cellToString()` (`:184-192`) turns a numeric `01` into `"1"`, while the CSV keeps `"01"`. Re-importing a term with the other format would treat every section as new + absent (delete unassigned, flag assigned). Not data loss, but churn. Add a runbook line: "always re-import the same format you first imported for the term" (or left-pad/normalise section numbers).
6. `app/Http/Controllers/Admin/AssignmentController.php:24` runs `assignments()->count()` per approved application (ledger Task 12). `->withCount('assignments')` on the `$approved` query and `$a->assignments_count`. One line.
7. `app/Http/Controllers/Admin/SectionImportController.php:39-43`: a corrupt or foreign payload makes `unserialize()` return `false` and the `apply()` call throws a `TypeError` (500). Guard `abort_unless($timetable instanceof ParsedTimetable, 419)` and `session()->forget('sections_import')` in the `catch` so a failed apply does not leave a stale preview.
8. `app/Models/Section.php` has no integer casts on `seats_*`; the fingerprint `json_encode`s them, so on a driver that returns strings (`ATTR_EMULATE_PREPARES` true) every re-import would count all sections as "updated". Laravel's MySQL connector uses native prepares, so this is insurance only: add `'seats_capacity' => 'integer'` etc.
9. `resources/views/admin/dashboard.blade.php:38` reuses `app.review.no_attention` ("لا توجد طلبات بانتظار المراجعة") as the empty state of the committee group; `app.review.attention` and `Application::REVIEWABLE_STATUSES` are now unused. Add a `no_committee` key, delete the two dead symbols.
10. `app/Models/SectionMeeting.php:16` `DAY_NAMES_AR` is Arabic UI text in a model with no English twin (the English UI would print Arabic day names). Move to `lang` (`app.days.0..4`) when convenient.
11. `tests/Feature/Admin/SectionImportServiceTest.php:100` `test_apply_refuses_errors_and_closed_terms_and_rolls_back` tests neither closed terms (a separate test does) nor rollback. Rename, or add the rollback case (throwing `AssignmentService::recomputeHours` mock → `assertDatabaseCount('sections', n_before)`).
12. `vendor/bin/pint --test` fails on milestone 2 files: `app/Services/Sections/JadawilParser.php`, `tests/Unit/JadawilParserTest.php`, `tests/Feature/Admin/{AssignmentTest,CommitteeDecisionTest,AssignmentScreensTest,ReviewTest}.php` (plus pre-existing files). The ledger recorded "Pint claim unverified"; it is now verified failing. Run `vendor/bin/pint` on the branch's own files before merge.
13. `app/Http/Controllers/Admin/DashboardController.php:14-19`: `$department`/`$committee` are scoped to `Term::current()` while `$counts` (`:31-32`) spans every open term. With one open term at a time this never shows, but the two should agree.
14. `AuditLog` rows for `assign_section`/`unassign_section` carry the section as subject but not the application; put `application_id=N` in `details` so the audit trail answers "who was assigned" without a join to a row that may since be deleted.
15. `lang/*/app.php` `review.waiting_days` has no Arabic plural forms (`يوم`/`يومان`/`أيام`). Laravel's `trans_choice` handles it.

### Triage of ledger's deferred minors

Task 1
- canComplete duplicates markComplete guards — CAN WAIT (two lists of two statuses; extract `canMarkComplete()` when next touched).
- REVIEWABLE_STATUSES unused — CAN WAIT (delete; Minor #9).
- waiting_days no Arabic plural forms — CAN WAIT (Minor #15).
- complete_blocked message shown for `submitted` too — CAN WAIT (`show()` moves `submitted` to `under_review` before rendering on open terms, so it is unreachable).
- app.review.attention key now unused — CAN WAIT (Minor #9).

Task 2
- replacement assertion vacuous for status submitted — CAN WAIT (committee-on-closed-term is covered in `CommitteeDecisionTest`).
- no lock around check-then-update in committeeDecision — CAN WAIT (Minor #1; two-tab sequential case is tested and correct).
- no test for committee route on withdrawn/final — CAN WAIT (`test_second_submission_is_refused` covers the final branch).
- rejection_reason display note — CAN WAIT.

Task 3
- no test for the term-closed branch of notifyRejections — CAN WAIT (guard is present and identical in shape to the tested ones).
- checklist() computed twice per notify — CAN WAIT.

Task 4
- view test does not cover withdrawn-on-closed-term hiding reopen — CAN WAIT (the POST is tested; the view condition is one `&& $termOpen`).

Task 5
- no PUT 403 test for instructors — CAN WAIT (route group middleware; GET is tested).
- salary compared as raw strings — CAN WAIT (affects only which *names* land in `details`; never values).
- test 1 relies on factory date differing from payload — CAN WAIT.
- sensitive inputs fall back to stored values after validation failure — CAN WAIT as behaviour (it is an edit form), but it is another reason for Important #2: the render must be audited.

Task 6
- section_meetings.type free string — CAN WAIT (parser is the only writer and emits the three values).
- weekly_hours_decimal no cast / not written until Task 11 — superseded by Important #4.

Task 7
- duplicate key day|start|type too coarse — CAN WAIT (it matches the DB unique index exactly, so no crash is possible; the warning is the right behaviour).
- ksort SORT_NATURAL — CAN WAIT.
- empty course code row dropped silently — CAN WAIT (that is the footer rule).
- lines split before CSV parse — CAN WAIT (Minor #4).
- row numbers shift after leading blank lines — CAN WAIT (blank lines are skipped but `$i + 1` keeps the physical line number, which is what the admin sees in a spreadsheet — arguably correct).
- activity not normalised — CAN WAIT (Minor #3).
- later rows' conflicting name/ref/instructor ignored, "0" → null — CAN WAIT.
- mutable value objects — CAN WAIT (readonly on the identity fields is enough).
- weak row-number assertions and missing CRLF/quoted-comma/24:00 tests — CAN WAIT (CRLF and quoted-comma were added in the fix round).
- LOG_DEPRECATIONS_WHILE_TESTING — CAN WAIT (enable in `phpunit.xml` in a housekeeping commit).

Task 8
- 0.0/1.0 time fractions become "0"/"1" → bad_time — CAN WAIT (midnight classes do not exist).
- (string)(int) precision beyond 2^53 — CAN WAIT.

Task 9
- idempotency test doesn't prove meetings untouched — CAN WAIT (the unchanged branch never touches meetings by construction).
- mid-import rollback untested — CAN WAIT, but rename the test that claims it (Minor #11).
- seat columns lack integer casts — CAN WAIT (Minor #8; add casts opportunistically).
- in_array O(n²) on unchanged keys — CAN WAIT (hundreds of sections).
- lazy $section->assignment per row — CAN WAIT.
- audit details string untested — CAN WAIT (the `action` suffix is asserted).

Task 10
- stale session payload after failed apply — CAN WAIT (Minor #7).
- corrupt payload → TypeError 500 instead of 419 — CAN WAIT (Minor #7; requires a corrupted server-side session).
- unescaped LIKE wildcards in course filter — CAN WAIT (admin-only, read-only query).
- preview view duplicates meeting-summary formatting — CAN WAIT.
- no empty states on index — CAN WAIT.
- file input/filter button reuse sections.title label — CAN WAIT.
- no test for the imported flash / term closing between preview and confirm — CAN WAIT (`apply()` guards the term and is tested at service level).

Task 11
- term_closed message reused from applications wording — CAN WAIT.
- whereNotNull allows empty scheduled_instructor — CAN WAIT (parser stores `null`, never `""`).
- assertTrue(true) no-op in test — CAN WAIT.
- composite unique (application_id, section_id) redundant with unique(section_id) — CAN WAIT (harmless; drop it if Important #4 has you editing migrations anyway).
- AssignmentTest relies on manual Mockery::close() — CAN WAIT.

Task 12
- totals panel runs assignments()->count() per approved application — CAN WAIT (Minor #6; one line, do it with the merge fixes if the controller is open).
- closed-term unassigned rows show an empty action cell — CAN WAIT.
- hard-coded separator punctuation in totals/alerts — CAN WAIT.
- Pint claim unverified — now verified failing on branch files: MUST FIX BEFORE MERGE only in the sense of running `vendor/bin/pint` on the six files (Minor #12); no behaviour change.

Task 13
- CLAUDE.md does not say reopen returns to `draft` — CAN WAIT (it says "can be reopened by an admin"; add "(to draft)" when next edited).

### Declined to judge

- Admin profile edit is allowed on closed terms: the profile belongs to the instructor, not to a term, and the spec says "allowed in any status"; treated as intended.
- Instructor may withdraw from `complete` (spec: "withdrawn ← any non-final state"): spec-conformant; whether the department wants a withdrawal after verification is a product question.
- `Application::$fillable` now also lists `committee_*`, `weekly_minutes`, `weekly_hours_decimal`, `complete_at`: only ever mass-assigned from service code, never from request input (same ruling as milestone 1).
- `AssignmentController::store` validates `exists:applications,id` without scoping to the term: the service enforces same-term, tested; the validation is only a 422 shortcut.
- Section/assignment index pages accept any `?term=` including closed/archived terms: read-only by construction (forms hidden, services guard); spec §4.4 says "current (or chosen) term".
- Spec §3.4 says the admin profile form is "rendered on the application page"; it is a separate page linked from the application page: same fields, same rules, same audit; layout choice.
- Spec §4.2 names `maatwebsite/excel`; branch uses `phpoffice/phpspreadsheet` directly: ledger ruling, documented in plan header and spec note; PhpSpreadsheet's extension requirements (gd, zip, xml, mbstring, ...) are already in DEPLOY.md §0's check.
- Committee "note" is stored as `rejection_reason` on rejection and shown to the applicant: spec §3.2 says exactly that.
- `hasManyThrough` `sections()` on `Application` and the `assignments()` unique pair: schema per spec §5.1.
- `Term::current()` is used for the import target (not a term picker): spec §4.3 says "admin, open term only".
- Session driver is `database` in production (`longText payload`), so a whole term's parsed timetable fits: environment fact, not code.
- The `weekly_hours` integer column being "unused" is covered by Important #4, not set aside.
- Milestone 1 minors that remain open (nosniff header, reveal persisting for the session, throttle on civil-ID oracle, etc.): outside this milestone's plan; still listed in the milestone 1 review.

### Recommendations

1. Land Important #1, #2 and #5 in one small commit (three code lines plus two assertions); they are the ones with a visible effect on a real user before the first committee sits.
2. Land Important #3 and #4 in a second commit while the branch is still unmerged and no production DB exists: a `SectionPolicy` and `authorize()` calls, and a corrected `110003` migration. If Dr. Mishal prefers to keep the schema as ruled, at least drop the dead integer column so milestone 3 has one source of truth (`weekly_minutes`).
3. Run `vendor/bin/pint` on the branch's own files (Minor #12) and rename the misnamed rollback test (Minor #11) in the same pass.
4. Before the first real import, add to DEPLOY.md's per-term step: export from jadawil, upload, read the preview's warnings and errors (unknown activity, duplicate meeting, unknown day), and always use the same format (CSV or XLSX) for re-imports of the same term. Expect the first real export to reveal header-text or day-name spelling differences (Minor #3) — the parser will refuse with a clear column/row message rather than import anything wrong.
5. For milestone 3, read hours from `weekly_minutes` only; consider the conditional-update pattern from Minor #1 for every final transition.

### Assessment

**Ready to merge?** With fixes

**Reasoning:** Both groups are complete against the spec, the workflow is guarded and tested end to end, the importer is transactional and never deletes assigned work, authorization for instructors holds on every new route, and sensitive data stays out of session, logs and audit values. What is missing is small but real: the new `complete` state is not covered by the instructor profile lock (a verified file can be altered while it waits for the committee), the admin edit form is an unaudited plaintext reveal, the spec's policies were never added, and a dead schema column is about to become permanent. All five Important items are under an hour together; merge once #1, #2 and #5 are in and #3/#4 are either fixed or explicitly re-ruled in the ledger.

## Post-review fix wave (commits d1f86a0, 3e2d4b3, 1f69d58, 9fe4b71)

One fix dispatch covered all five Important findings plus housekeeping; one
scoped re-review (9da7fae..9fe4b71) returned "all findings addressed, no new
Critical/Important breakage". Suite after the wave: 147 tests, 551 assertions.

- **#1 profile lock covers `complete`** — `Instructor::hasLockedApplication()`
  includes `STATUS_COMPLETE`; test proves the instructor PUT is refused and the
  data unchanged. Admin edits bypass the lock by design (`AdminProfileEditTest`).
- **#2 admin edit form audited** — `Admin\ProfileController::edit()` writes a
  `reveal_sensitive` row with details `profile_edit_form`; test asserts it.
- **#3 `SectionPolicy`** — `viewAny`/`import`/`assign`/`unassign` (admin only),
  registered beside the Application and Document policies, called via
  `authorize()` in the section, import (form/preview/confirm) and assignment
  (index/store/destroy) controllers; unit test covers admin allowed and
  instructor denied for all four.
- **#4 `weekly_minutes` is the single source of truth** — migration `110003`
  drops the integer `weekly_hours` and adds only `weekly_minutes`;
  `weekly_hours_decimal` does not exist; hours are derived through
  `Section::hoursFromMinutes()` / `Application::weeklyHoursLabel()`. Spec §5.1
  carries the implementation note. Safe: no code read the M1 column, it had no
  index, and no production database exists yet.
- **#5 `Application::STATUSES`** — all eight statuses in pipeline order drive
  both the admin applications filter (now includes `complete`) and the
  dashboard counts; test for `?status=complete`.
- **Housekeeping** — `withCount('assignments')` in the assignment totals
  (closes the Task 12 deferral); rollback test renamed
  `test_apply_refuses_timetables_with_errors`; DEPLOY.md notes the per-term
  jadawil import; Pint run limited to branch files (formatting and imports
  only).

### Rulings made during the fix wave

- The dashboard counts row now shows a `draft` badge because it iterates
  `Application::STATUSES` — accepted as informative.
- `SectionImportController::preview()` validates the upload before
  `authorize()`; acceptable because the `role:admin` middleware runs before
  both. Deferred minor.
- The `reveal_sensitive` audit row is written on every render of the admin
  edit form, including the redirect back after a failed save (re-reviewer
  observation). Accepted: every plaintext render leaves a trace.

## Rulings made during execution (from the ledger)

Pre-flight:
- T5: audit "changed fields" computed by comparing `getOriginal()` (decrypted)
  with the validated value before `fill()`, because encrypted casts make
  `getDirty()` report unchanged ciphertext as changed.
- T6: accepted the plan's `weekly_minutes` deviation from the spec's
  `weekly_hours decimal(5,1)`; later revised in the final review to
  `weekly_minutes` only (see #4 above).
- T7: fixture has three valid sections; plan's `assertCount(4)` fixed to 3.
- T8: `phpoffice/phpspreadsheet` instead of the spec's `maatwebsite/excel`
  (documented in the plan header and spec §4.2).
- T11: stray `expectExceptionMessageMatches` removed from the plan's test.

Per task:
- T1: approve form still reachable on `complete` until T2 removed
  approve/reject (plan-mandated seam).
- T2: committee outcome select gains a placeholder and `@selected(old())` so a
  failed rejection cannot silently become an approval.
- T5: new nullable `audit_log.details` text column; `AuditLog::record()` gains
  `?string $details`; one `admin_edit_profile` row with the sorted field list
  (the 60-char `action` column truncated it).
- T6/T9: `assignments.section_id` uses `restrictOnDelete()` so no path can
  delete an assigned section.
- T7: explicit `''` escape for `str_getcsv()` (PHP 8.5 deprecation).
- T8: `report($e)` inside the XLSX catch-all.
- T11: `UniqueConstraintViolationException` on assign is caught and rethrown as
  `DomainException('already_assigned')` (TOCTOU between exists() and create()).
- T12: assignment totals N+1 deferred to final-review triage, then fixed in the
  wave.

## Deferred minors (carry into milestone 3)

- T1: `canComplete()` duplicates `markComplete()` guards; `REVIEWABLE_STATUSES`
  unused; `waiting_days` has no Arabic plural forms; `complete_blocked` message
  also shown for `submitted`; `app.review.attention` key unused.
- T2: vacuous replacement assertion in `test_document_review_blocked_on_closed_term`
  for `submitted` (add a complete-on-closed-term case); no lock around
  check-then-update in `committeeDecision()`; no test for the committee route
  on withdrawn/final applications; rejection_reason display note.
- T3: no test for the term-closed branch of `notifyRejections()`; `checklist()`
  computed twice per notify.
- T4: view test does not cover withdrawn-on-closed-term hiding the reopen button.
- T5: no PUT 403 test for instructors; salary compared as raw strings (500 vs
  500.000 false positive); sensitive inputs fall back to stored values after a
  validation failure (expected, document).
- T6: `section_meetings.type` is a free string (no enum/CHECK).
- T7: duplicate-meeting key `day|start|type` too coarse; `ksort` should be
  `SORT_NATURAL`; empty course-code rows dropped silently; lines split before
  CSV parse (quoted newlines); row numbers shift after leading blank lines;
  activity not normalised (محاضره); conflicting name/ref/instructor on later
  rows ignored; "0" → null; mutable value objects; missing CRLF/quoted-comma/
  24:00 tests. Deprecations are silenced in `artisan test` unless
  `LOG_DEPRECATIONS_WHILE_TESTING=true`.
- T8: 0.0/1.0 time fractions (midnight) become `bad_time`; `(string)(int)`
  precision beyond 2^53.
- T9: idempotency test does not prove meetings untouched (check ids);
  mid-import rollback untested; seat columns lack integer casts; `in_array`
  O(n²) on unchanged keys; lazy `$section->assignment` per row; audit details
  string untested.
- T10: stale session payload kept after a failed apply; corrupt payload →
  TypeError 500 instead of 419; unescaped LIKE wildcards in the course filter;
  preview duplicates meeting-summary formatting; no empty states on the index;
  file input/filter button reuse `sections.title`; no test for the imported
  flash or for the term closing between preview and confirm.
- T11: `term_closed` message reused from applications wording; `whereNotNull`
  allows an empty `scheduled_instructor`; composite unique
  `(application_id, section_id)` redundant with `unique(section_id)`;
  `AssignmentTest` relies on manual `Mockery::close()`.
- T12: closed-term unassigned rows show an empty action cell; hard-coded
  separator punctuation in totals/alerts.
- T13: CLAUDE.md does not say reopen returns to `draft` (fixed in the
  hand-off commit).
- Fix wave: import preview validates before authorize.

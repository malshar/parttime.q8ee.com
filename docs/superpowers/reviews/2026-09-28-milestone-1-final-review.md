# Final review — milestone-1-intake (e4a73eb..d75b7bf)

Reviewed in passes: (1) spec, plan header/constraints/review focus, ledger; (2) diff index via `grep -n '^diff --git'`, then every application hunk read from the working tree at d75b7bf (`app/`, `routes/`, `bootstrap/app.php`, `database/{migrations/2026_*,seeders,factories}`, `resources/views`, `lang/{ar,en}/app.php`, `tests/`, `deploy/`, `.gitignore`, `phpunit.xml`, `composer.json`, `config` keys); (3) skeleton/vendor hunks only checked for committed secrets (none: only `.env.example` and `deploy/.env.production.example` are tracked, no `.env`, no uploads, no `part-time/` content). `php artisan test --compact` run by me: 80 passed, 247 assertions. No subagents used; working tree untouched.

### Strengths

- Authorization is genuinely policy-based and consistent: every instructor route goes through `ApplicationPolicy`/`DocumentPolicy` with an ownership check, every admin route sits behind `role:admin` plus a `review` ability; cross-user tests assert 403 for applications, documents, admin routes and terms. `DocumentPolicy::create` also refuses uploads for items the derived checklist does not require, so the department items and not-applicable items cannot receive files.
- Sensitive data handling is careful: encrypted casts for civil ID, IBAN and salaries; HMAC blind index that is never mass-assignable; `$hidden` on the model; masked by default on every admin/instructor screen; `dontFlash` plus a custom `TokenMismatchException` handler so plaintext never lands in the session on validation errors (with a test that checks `_old_input`); reveal, downloads, inline views, decisions, document reviews, Check List prints and term mutations all write to `audit_log`.
- Latest-version semantics are correct and tested end to end (`latestDocuments()` orders by version desc then `unique()`; the approval test covers "old rejected v1 must not block once v2 is accepted").
- Checklist derivation is dynamic (resolved from the profile on every read), so the review-focus scenario "sector changes after uploads" works without stored state, and uploads are kept because documents are keyed by item, not by plan.
- Upload validation is layered (`mimes` + `mimetypes` + explicit ZIP rejection with a dedicated Arabic message), files land on the private disk under random names, and downloads go through policy-checked streamed responses.
- Translations: `lang/ar/app.php` and `lang/en/app.php` have identical key sets (203 keys, verified programmatically), no tashkeel in any Arabic string, no `{!! !!}` anywhere, and no hard-coded Arabic outside the seeder and the Check List generator (which reproduces the official form's fixed Arabic text and is correct to hard-code).
- The Check List generator escapes output, uses a per-request random file name, deletes the file after send, and the tests unzip the .docx and assert well-formed XML plus the expected Arabic strings and box states.
- The per-task review loop caught real issues (closed-term edits, session flashing of civil IDs, duplicate verification mails, PhpWord escaping, the runbook ordering) and the fixes are in the branch.

### Issues

#### Critical (Must Fix)

None found. No data exposure path, broken auth or data loss in the reviewed code.

#### Important (Should Fix)

1. **Closed terms are not read-only for the admin review actions.** `app/Services/ApplicationWorkflow.php:114-135` (`reviewDocument`) and `:156-164` (`reject`) check `isFinal()` but never `term->isOpen()`; only `approve()` does (`:142`). On a closed term the admin can still reject a document, which flips the application to `incomplete` and emails the applicant to re-upload something the policy will refuse (`DocumentPolicy::create` requires an open term). The spec says closed/archived terms are read-only for everyone. Fix: add the same `term_closed` `DomainException` guard to `reviewDocument()` and `reject()`, and hide the accept/reject/reject-application forms in `resources/views/admin/applications/show.blade.php:102,193` when `! $application->term->isOpen()`. Add a test next to `test_approve_blocked_on_closed_term`.

2. **Profile stays editable while an application is submitted / under review / approved.** `app/Http/Controllers/Instructor/ProfileController.php:19-30` has no state check. Consequences: (a) the admin accepts the civil-ID scan against the typed civil ID, then the applicant changes the number and the printed official Check List carries the new value; (b) an applicant switches `employer_sector`/`degree_country` after submission, a new item becomes "missing", `allRequiredAccepted()` goes false and approval is blocked while uploads are also blocked (not editable) — a deadlock the admin can only break by rejecting an unrelated document. Spec §9 explicitly lists "instructor self-service editing after approval" as out of scope, i.e. not something the system should permit. Fix: in `ProfileController::update` (and hide the form/button in `profile.blade.php`), refuse with a translated message when the instructor has an application in `submitted|under_review|approved` on an open term; allow edits in `draft|incomplete` (where the review-focus #2 scenario legitimately applies). Test both branches.

3. **Synchronous mail sends happen after the state change and are unguarded.** `ApplicationWorkflow::submit():87-88`, `afterUpload():94-95`, `reviewDocument():130-133`, `approve():148-153`, `reject():161-163` update the row, write the audit row, then call `Mail::to(...)->send(...)`. Production is `QUEUE_CONNECTION=sync` + SMTP. Any SMTP failure (wrong credentials on first deploy, provider outage) produces a 500 to the user after the application was already submitted/approved/rejected; the retry then fails with `already_final`/`submit_blocked`, which is confusing and will generate support requests. Fix: wrap each send in `try { ... } catch (\Throwable $e) { report($e); }` (or `Mail::...->queue()` once a worker exists) so the action succeeds and the failure is logged; add a test that a throwing mailer does not roll back the status or 500.

4. **Spec-required admin warning for bachelor holders with < 10 years is missing.** Spec §3 (derivation rule): "the system records `experience_years` and warns the admin if < 10, it does not block". `resources/views/admin/applications/show.blade.php:50` prints the number with no warning and there is no test. The plan's coverage table claims §3 is covered; this line was dropped. Fix: in the profile card, when `highest_degree === 'bachelor' && experience_years < 10`, render an `alert-warning` with a new `app.review.experience_below_min` key (ar + en); one assertion in `ReviewTest`.

5. **Assignment decision number/date can only be entered at approval time.** Spec §4 step 10: "approve. Enter the PAAET assignment decision number/date **when available**" — in practice the PAAET decision arrives after departmental approval. The approve form (`show.blade.php:175-188`) is the only place these fields exist; after approval the decision card only shows a badge (`:171-172`). Fix: add `admin.applications.decision` (POST, admin only, allowed on `approved` applications, audit `set_decision`) with the two fields, shown on approved applications. This is a plan gap rather than an implementation slip; small to add now, awkward to retrofit after real approvals exist.

6. **Deploy rsync ships local development artefacts to the server.** `deploy/deploy.sh:31-46` excludes `.env`, `vendor`, `storage/*`, `tests`, but the working tree also contains `database/database.sqlite` (94 KB local dev DB), `.superpowers/` (2.1 MB of ledger + this review diff), `.phpunit.result.cache`, `bootstrap/cache/packages 2.php`/`services 2.php` (iCloud duplicate files) and `docs/`. None are web-reachable (DocumentRoot is `public/`), but a local database with dev data must not be copied to production, and the same list is duplicated by hand in `DEPLOY.md` §3. Fix: add `--exclude 'database/*.sqlite' --exclude '.superpowers' --exclude '.phpunit.result.cache' --exclude 'bootstrap/cache/*.php' --exclude 'docs'` to both places (or better, generate the manual command from the script so they cannot drift).

7. **Origin trusts every proxy; runbook never restricts the origin to Cloudflare.** `bootstrap/app.php:18` `trustProxies(at: '*')` is plan-mandated and needed behind Cloudflare, but combined with an origin that answers on 443 for anyone who knows the IP (the vhost uses a Cloudflare origin cert, which does not stop direct connections), a client can send its own `X-Forwarded-For` and defeat the per-IP rate limits (`RateLimiter` `register` 3/hour, `login` 5/min) and forge the `ip` column in `audit_log`. Fix in `deploy/DEPLOY.md` (environment, not code): add a step to firewall 80/443 to Cloudflare's published IP ranges (as help.q8ee.com should already do) or, alternatively, trust only those ranges in `trustProxies`. Say which one help.q8ee.com uses and mirror it.

#### Minor (Nice to Have)

1. `Admin/ApplicationController::reveal():49-58` stores the reveal in the session for the rest of the admin's session; later page loads of the same application render plaintext with no new audit row. Consider a one-request flash (`session()->flash`) so every plaintext render is logged, or at least a "hide" action.
2. `Instructor/ApplicationController::withdraw():75-82` does not check the term is open; the instructor's view (`application.blade.php:91`) shows the withdraw button on closed terms. Add `abort_unless($application->term->isOpen(), 403)` and hide the button for consistency with "closed terms are read-only".
3. Pipeline guard: `reviewDocument()`, `approve()` and `reject()` accept `draft` applications (admin index lists drafts). An applicant who never submitted can receive an approval/rejection email. Guard on `in_array(status, [submitted, under_review, incomplete])` and hide the forms for drafts.
4. One `DocumentsRejected` email per rejected document (`reviewDocument():129-133`), each listing the cumulative rejected set. Rejecting four items sends four emails. Consider a "finish review" action that sends one email, or debounce in the view. Spec wording ("rejection sends an email") tolerates the current behaviour.
5. Withdrawn applications block re-applying for the same term (`firstOrCreate` on the unique pair; home page shows the withdrawn card with no start button). Spec-conformant, but a mis-click after the confirm dialog needs the admin to intervene and there is no admin "reopen" action. Document it or add one later.
6. `DocumentStore::download(inline: true)` (`:38-40`) sets no `X-Content-Type-Options: nosniff`. Low risk because only sniffed PDF/JPEG/PNG/DOCX get stored, but it is a one-line header.
7. `ProfileRequest::withValidator` civil-ID-taken check is an existence oracle reachable by any verified instructor with no throttle on `instructor.profile.update`. Add `throttle:10,1` to that route.
8. `Instructor::isComplete()` (`:78-81`) and `civilIdExpired()` are unused; `DocumentController.php:24` carries a stale "Task 10 defines it" comment; several views keep `@if (Route::has(...))` scaffolding guards (`application.blade.php:58,84,91`, `admin/applications/show.blade.php:208`). Remove.
9. `resources/views/admin/applications/show.blade.php:46` prints `app.countries.XX` literally for a code not in the list (validation allows any two letters). Use `Rule::in` with the list from a single config/constant shared with `profile.blade.php:63`.
10. `ChecklistDocument::build():35` looks for `public/img/paaet-logo.png`, which does not exist in the repo; the printed form has no PAAET logo (spec §5 lists it). Add the asset or drop the branch.
11. `README.md` is the stock Laravel README; `package.json`/`vite.config.js`/`resources/{css,js}` are unused in a no-build project.
12. Commit trailers on the branch read `Co-Authored-By: Claude Sonnet 5`, not the plan's `Claude Fable 5.1` line. Not worth a history rewrite; fix going forward.

### Triage of ledger's deferred minors

Task 1
- commit trailer says "Claude Sonnet 5" — CAN WAIT (cosmetic; rewriting 20 commits is riskier than the mismatch).
- .env.example lists APP_LOCALE/… while config hardcodes — CAN WAIT (harmless; add a comment).
- unused Vite/Tailwind scaffolding — CAN WAIT (cleanup commit later).

Task 2
- CreateAdmin console strings hard-coded English — CAN WAIT (CLI output is not UI copy; the rule targets views/mail).
- pint nits (User.php blank line, inline FQCNs) — CAN WAIT.
- no failure-path test for CreateAdmin password guard — CAN WAIT.

Task 3
- TurnstileVerifier swallows exceptions without logging — CAN WAIT, but add `report($e)` when touching the file; an outage currently looks identical to a bot.
- TurnstileRuleTest uses Http::fake directly — CAN WAIT.

Task 4
- MailableChannel exists to satisfy Mail::fake — CAN WAIT (deviation is justified and documented; `Mail::send($mailable)` behaves identically in production).
- channel silently drops non-Mailable toMail() — CAN WAIT (both notifications return Mailables; add a `LogicException` when convenient).
- verification redirects ignore role — CAN WAIT (admins are created verified).
- login does not lowercase email — CAN WAIT (MySQL utf8mb4_unicode_ci is case-insensitive; registration lowercases).
- registration mail sent synchronously — CAN WAIT (covered by Important #3's try/catch pattern if applied to the channel too).

Task 5
- auth()->id() vs $request->user()->id inconsistency; no holiday_line_invalid test — CAN WAIT.
- parsedHolidays 'skip' branch dead — CAN WAIT.

Task 6
- isComplete()=exists fragile & untested — CAN WAIT (it is unused; delete it).
- no owner re-save test, strict user_id compare — CAN WAIT.
- civil-ID-taken enumeration oracle — CAN WAIT (see Minor #7 for the cheap throttle).
- array input → 500 in prepareForValidation — CAN WAIT (malicious input only; wrap with `is_string`).
- degree_country any 2 letters / list duplicated in view — CAN WAIT (Minor #9).
- experience_years min 10 not enforced — CAN WAIT as "not enforced" is correct per spec; the missing *warning* is Important #4.
- IBAN input lacks required / autocomplete=off — CAN WAIT (server validates; add attributes when next in the view).
- instructor/layout redundant content section — CAN WAIT.
- hashCivilId keyed on APP_KEY (rotation hazard) — CAN WAIT (runbook already says never rotate; encrypted casts have the same dependency, so a separate key buys little).
- withCheckDigit helper can emit invalid check digit — CAN WAIT (test helper only).

Task 7
- note_ar drift vs docs/forms/checklist.md (civil_id note, "فقط" on equivalency/experience) — CAN WAIT for merge but fold into the first follow-up: the seeder is `updateOrCreate`, so the text can be corrected by a redeploy without a migration. It does appear on the printed official form, so do not let it linger.
- provided_by/condition free strings — CAN WAIT.

Task 8
- start() TypeError without profile — RESOLVED in Task 10 (profile guard present at `ApplicationController.php:34-37`).
- firstOrCreate race on double-submit — CAN WAIT (unique index turns the race into a 500 only on a true double-click race).
- show() runs resolver 3x + latestDocuments 2x — CAN WAIT (12-row table; not a real N+1).
- hard-coded "v" prefix — RESOLVED (view uses `app.documents.version`).
- rejected alert lacks label; test name overclaims "keeps uploads"; no ApplicationPolicy::update test; profile-redirect flashed as success; redundant closed-term condition — CAN WAIT.

Task 9
- stored extension from client name — CAN WAIT (private disk, never executed; prefer `guessExtension()` when touched).
- version counter race → 500 + orphaned file — CAN WAIT.
- DOCX reported as application/zip by older libmagic — CAN WAIT for merge, but MUST be verified at first deploy: add "upload a real .docx" to DEPLOY.md §10, because on failure applicants cannot upload DOCX at all and see the ZIP message.
- ZIP test doesn't assert the message; no cross-owner upload test; vacuous "no audit on 403" (now non-vacuous since admin download exists); validation before authorization; Arabic Content-Disposition untested; inline branch nosniff — CAN WAIT (nosniff is Minor #6).

Task 10
- unreachable term_closed branch in submit(); withdraw() redundant check with untranslated 'final'; resubmission reuses "new application" subject; withdraw test never tests submitted — CAN WAIT.

Task 11
- superseded versions reviewable via direct POST — CAN WAIT (UI only exposes latest; add `abort_unless($document->is(latest))` when touching reviewDocument for Important #1).
- confirm() via Blade escaping (use @js) — CAN WAIT (no apostrophes in current strings).
- status column header uses applications.title — CAN WAIT.
- show page repeats resolver — CAN WAIT.
- approve-on-closed-term reuses instructor wording — CAN WAIT.
- no nosniff on inline view — CAN WAIT (Minor #6).
- test gaps (IBAN/salary mask, reject recipient, dashboard counts/filter) — CAN WAIT.
- unused AuditLog import in ReviewTest — CAN WAIT.
- mails synchronous outside transaction — MUST FIX BEFORE MERGE (escalated to Important #3; with sync queue + SMTP this is the first production failure mode you will hit).
- ApplicationWorkflow ~170 lines — CAN WAIT (cohesive; fine).
- dead inner isFinal() in reviewDocument — CAN WAIT (remove while doing Important #1).

Task 12
- audit row written before build() — CAN WAIT.
- float twips in indentation — CAN WAIT.
- civil_id note_ar null — CAN WAIT (same as Task 7 line).
- ChecklistDocumentTest leaves .docx on the real private disk — CAN WAIT, but it is two lines (`Storage::fake('local')` in `setUp`) and removes an environment-dependent test; do it with the merge fixes.

Task 13
- storage/framework/{cache,sessions,views} not created by first-sync runbook — CAN WAIT (Laravel's FileStore and Blade compiler create their directories; sessions use the database driver). Adding `mkdir -p storage/framework/{cache/data,sessions,views}` to the ssh block in deploy.sh is cheap insurance.

### Declined to judge

- Instructor profile form pre-fills decrypted civil ID / IBAN / salaries in `value=""` (`profile.blade.php:17,91,95,97`): the owner must be able to edit their own values; spec's "masked on every screen" is read as screens that display, not the owner's edit form.
- Password policy is min 10 + letters + numbers + uncompromised, stricter than spec's "min 8": plan-mandated and safe.
- No admin UI to edit an instructor profile "on request" (spec §9 parenthetical): not in the plan's file structure; a milestone-2/3 item.
- No UI for `admin_note`: column exists per spec, no plan task defines a form.
- No backup cron script in `deploy/` (spec §6 says backups are handled in `deploy/`): DEPLOY.md §12 defers to copying help.q8ee.com's cron; treated as an ops step, not code.
- PAAET Check List built with PhpWord instead of the `resources/forms/checklist-template.docx` in spec §5: plan explicitly chose this ("same output"); the ledger's tests cover the content.
- `verification.verify` requires the user to be logged in on the device that opens the link: stock Laravel behaviour.
- `withdrawn` is final and blocks a new application for the same term: spec's unique (term, instructor) constraint; product decision (Minor #5 only asks for a note).
- Registration `name` and profile `full_name` are separate fields: spec has both.
- `Application::$fillable` includes `status` and decision fields: only ever mass-assigned from service code, never from request input.

### Recommendations

1. Land Important #1-#3 and #6 in one small fix commit (four guard lines, one try/catch helper, rsync excludes); they are all under 40 lines including tests.
2. Important #4 and #5 are plan gaps against the spec; fix them now while the review screen is fresh, or record them as milestone-2 items explicitly in the ledger so they are not lost — but note #5 will bite as soon as the first real approval happens before PAAET issues its decision.
3. Add to DEPLOY.md §10 (verify): upload a real `.docx` and a real scanned `.pdf`, confirm both are accepted; and add the Cloudflare-only firewall step (#7).
4. Next milestone: consider a `finish review` action that sends one consolidated `DocumentsRejected` email, an admin "reopen withdrawn application" action, and moving mail to a queue with a worker (then the try/catch becomes a failed-jobs table).
5. Keep the `.superpowers/` ledger practice; it made this review tractable.

### Assessment

**Ready to merge?** With fixes

**Reasoning:** The security posture is sound — policies on every route, encryption plus blind index, no plaintext leaks into session/logs/mail bodies, audited reveals and downloads, and the tests exercise the real behaviours rather than mocks. What remains is spec compliance at the edges (closed-term review actions, profile mutability during review, the < 10-years warning, entering the PAAET decision after approval) and production robustness (unguarded synchronous mail, dev artefacts in the rsync set, origin not restricted to Cloudflare). None of these is a data-exposure or auth defect, all are small, and the branch should merge once Important #1-#3 and #6 are in, with #4, #5 and #7 either fixed alongside or explicitly carried in the ledger.

## Post-review fix wave (commits 7f70758, 3481612)

All seven Important findings fixed and re-reviewed clean. Rulings and deferred minors from the per-task loop:

- | Tasks | Produces / consumes | Finding | Ruling |
- | T3/T4 | TestCase::fakeTurnstile ← AuthTest; Password::uncompromised() hits api.pwnedpasswords.com for real (Http::fake without '*' lets unmatched URLs through) | network in tests | Ruling: fakeTurnstile() also fakes `api.pwnedpasswords.com/*` → empty 200 from T3 onward — plan §T4 step 4 already anticipates it — cost if wrong: none |
- | T5/T8 | TermController::index uses withCount('applications') → Application model exists only from T8 → fatal for admin index between T5 and T8 | transient breakage | Ruling: T5 index lists terms without withCount/applications column; T8 adds withCount + column — cost if wrong: one small edit |
- | T7 | ChecklistPlan + ChecklistResolver in one file (plan allows moving) | PSR-4: ChecklistPlan referenced by type-hint from ApplicationWorkflow (T8) autoloads only if its own file exists | Ruling: ChecklistPlan goes in app/Services/ChecklistPlan.php from the start — cost if wrong: none |
- | T8/T9 | T8 Application::latestDocuments() and ApplicationWorkflow::checklist() query Document model + documents table, which T9 creates → T8 tests fatal | real conflict | Ruling: T8 also creates app/Models/Document.php and the documents migration exactly as T9 specifies; T9 treats them as existing and adds factory/policy/store/controller/views — cost if wrong: duplicate work, none |
- | T12 | PhpWord paragraph 'indentation' key names differ across 1.x versions | possible API mismatch | Ruling: implementer may drop indentation if the installed PhpWord rejects it; layout is cosmetic — cost if wrong: none |
- Housekeeping: `.superpowers/` added to .gitignore (workspace script assumes it is ignored) — Ruling: minimal .gitignore commit before Task 1 — cost if wrong: none.
- Task 1: minor (deferred): implementer's commit trailer says "Claude Sonnet 5" (subagent attribution) instead of the plan's Fable 5.1 line — cosmetic
- Task 1: minor (deferred): .env.example still lists APP_LOCALE/APP_FALLBACK_LOCALE/APP_FAKER_LOCALE but config/app.php hardcodes them (plan-mandated) — add a comment or drop the vars
- Task 1: minor (deferred): unused Vite/Tailwind scaffolding (package.json, vite.config.js, resources/css, resources/js) in a no-build-step project — strip in cleanup
- Task 2: minor (deferred): CreateAdmin console strings hard-coded English (plan-mandated; CLI output — decide whether the no-hard-coded-strings rule covers artisan output)
- Task 2: minor (deferred): pint nits — User.php blank line between consts; bootstrap/app.php inline FQCNs (pre-existing style)
- Task 2: minor (deferred): no failure-path test for CreateAdmin password-length guard
- Task 3: minor (deferred): TurnstileVerifier catch-all swallows exceptions without logging — add report()/Log::warning
- Task 3: minor (deferred): TurnstileRuleTest uses Http::fake directly instead of fakeTurnstile() (plan-verbatim)
- Task 5: Ruling: (3) is plan-mandated but the spec's "read-only closed terms" and reasonable-person expectation win — fix all three — cost if wrong: none.
- Task 5: Ruling: TermHoliday date — drop the Carbon toStringFormat accessor; use plain 'date' cast like Term and change the brief's test lookup to compare via ->date->toDateString(); spec authority over plan test text; consistency with sibling models — cost if wrong: one test line.
- Task 5: minor (deferred): auth()->id() vs $request->user()->id inconsistency in TermController; no tests for holiday_line_invalid path
- Task 5: minor (deferred): parsedHolidays() 'skip' branch is dead code (blank lines filtered before map)
- Task 6: minor (deferred): isComplete()=exists fragile & untested; no owner re-save test, strict user_id compare; civil-ID-taken message is an enumeration oracle (spec-mandated); array input → 500 in prepareForValidation; degree_country accepts any 2 letters (no Rule::in) and country list duplicated in view; experience_years min 10 not enforced (plan-mandated); IBAN input lacks required + sensitive inputs lack autocomplete=off; instructor/layout redundant content section; hashCivilId keyed on APP_KEY (rotation hazard); withCheckDigit helper can emit invalid check digit
- Task 7: minor (deferred): note_ar drift vs docs/forms/checklist.md for civil_id (missing "سارية" note), equivalency and experience (missing "فقط") — plan-mandated text; fix seeder text in a follow-up
- Task 7: minor (deferred): provided_by/condition are free strings (no DB check constraint)
- Task 8: minor (deferred): start() TypeError (500) when instructor has no profile on direct POST (plan-mandated) — Ruling: carry to Task 10 dispatch (touches ApplicationController): add the same profile guard as home()
- Task 8: minor (deferred): firstOrCreate race on double-submit; show() runs resolver 3x + latestDocuments 2x; hard-coded "v" version prefix in application view; rejected alert lacks label; test name overclaims "keeps uploads"; no ApplicationPolicy::update test; profile-redirect flashed as success style; redundant closed-term condition
- Task 9: Ruling: plan defect — SubmitTest (T10) and ReviewTest (T11) also declare `private Application $app`; carry "rename to $application" into both dispatches — cost if wrong: none
- Task 10: Important (plan-mandated): notifyAdmin() silently no-ops when mail.admin_notify is empty — Ruling: carry to Task 11 dispatch (same file): Log::warning on the empty branch + test — cost if wrong: none
- Task 10: minor (deferred): unreachable term_closed DomainException branch in submit(); withdraw() service-side isFinal check redundant with controller abort and throws untranslated 'final'; resubmission reuses "new application" mail subject; test_withdraw_from_draft_or_submitted_only never tests submitted
- Task 11: review → Needs fixes. Important (plan-mandated, Ruling: fix — stale-tab/two-admin races are realistic — cost if wrong: none): (1) approve()/reject() lack isFinal() guard; (2) reviewDocument() mutates documents on a final application.
- Task 11: minor (deferred): superseded (non-latest) document versions can be reviewed → incomplete + empty rejection mail; confirm() string via Blade escaping (use @js); status column header uses applications.title; show page repeats resolver/latestDocuments; approve-on-closed-term reuses instructor wording; no nosniff on inline view; test gaps (IBAN/salary mask, reject recipient, dashboard counts/filter); unused AuditLog import in ReviewTest; mails synchronous outside transaction; ApplicationWorkflow ~170 lines
- Task 11: minor (deferred): inner `! $application->isFinal()` check in reviewDocument() now dead after the top guard
- Task 12: Ruling: fix all four. Department items render ☐ (admin ticks by hand; system holds no record of them in M1) and the brief's '☑ الجدول الدراسي' assertion becomes '☐ الجدول الدراسي' — spec says these are "provided by the department", not "already on file" — cost if wrong: one glyph + one assertion.
- Task 12: minor (deferred): audit row written before build() (records print even if build throws); float twips in indentation; civil_id note_ar null in seeder (already deferred from T7)
- Task 12: minor (deferred): ChecklistDocumentTest leaves generated .docx on the real private disk when run in isolation (cleanup relies on ID collision); use Storage::fake('local') or tearDown purge
- Task 13: minor (deferred): storage/framework/{cache,sessions,views} not created by the first-sync runbook (Laravel self-creates on first write; unverified on a fresh server)
- FINAL REVIEW: Ruling: single fix wave covers all 7 Important + Storage::fake('local') in ChecklistDocumentTest + DEPLOY.md verify step for real .docx/.pdf upload + guard against reviewing superseded document versions (cheap, same file as #1). All other minors stay deferred (triage recorded in final-review.md) — cost if wrong: rework visible in one commit.
- FINAL FIX WAVE: Ruling: post-approval decision entry stays allowed on closed terms — PAAET paperwork lags the term and the field is a record, not a workflow step — cost if wrong: one isOpen() guard
- FINAL FIX WAVE: minor (deferred): profile lock checked after validation (locked user with invalid input sees validation errors first); closed-term notice shown twice on review page; extra lang key app.review.decision_not_approved (fine)

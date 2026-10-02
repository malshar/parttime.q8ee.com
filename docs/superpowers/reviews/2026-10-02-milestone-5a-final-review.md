# Final review — milestone-5a-feedback (8bd76c9..d248030)

### Strengths
- The 419 handler is right. It matches `HttpException` with status 419 and `getPrevious() instanceof TokenMismatchException`, which is the shape Laravel 12's `prepareException()` produces. The import-confirm `abort(419)` is left alone. The comment explains why the M1 handler never fired, which is good diagnosis.
- `profileAttributes()` is correct on every branch. Private uses the typed name and forces sector to private. Other uses the typed name plus the required sector. An agency forces government. An unknown choice or bank raises a `ValidationException` with a translated message. The implementer also caught and fixed the plan's array-union bug (computed values first). `ProfileDiff`/audit rows still carry field names only, and mass assignment is unchanged.
- Template script: the GRID adds up to 10096 = `tblW`. `set_tcw` asserts span counts for all four rows. `nine_point` asserts every `rPr` carries `sz`. The `tblCellMar` is inserted in the schema-correct spot before `tblLook`. Underline stripping runs before the fit and still holds. There are still 50 placeholders. I diffed the old and new templates: the footer went from 20 runs to 5 and contains no field codes, so the merge drops nothing. `tblInd -368` plus 10096 gives exactly the symmetric overhang claimed.
- The document pop-up uses the existing policy-checked, audited `admin.documents.view` route. Uploads are pdf/jpg/png/docx only (no SVG), so the inline iframe adds no new exposure. The `href` stays as a fallback.
- `scopeFilter` wraps the instructor OR in a closure, so the term constraint holds. `orderByRaw('reference_number is null')` works on both MySQL 8 and SQLite.
- Tests: 271 passed, 1 skipped, as expected. Pint flags only 12 pre-existing vendor `lang/*` files, none touched by this branch. No migrations and nothing new for `deploy.sh`. I found no tashkeel in any added line, and `ar`/`en` keys are in parity.

### Issues

#### Critical
1. **An untouched save overwrites every legacy nationality with `ZZ` (data loss, against Review Focus #1).**
   - Where: `resources/views/instructor/profile.blade.php:32` and `resources/views/admin/applications/profile.blade.php:29`.
   - What happens: when the stored value is free text ("كويتي"), the select pre-selects `'ZZ'` ("دولة أخرى"). The next save stores `ZZ` and the real nationality is gone. All existing production profiles have free-text nationality (the factory does too). The most exposed path is an admin fixing an unrelated field, such as a mobile number.
   - Why the tests missed it: `test_legacy_values_preselect_other_and_survive_an_untouched_save` (ProfileTest.php:234) never saves, despite its name.
   - Related gap: the 19-entry list has no Syria, Iraq, Lebanon, Palestine, Sudan, Yemen or بدون. Those applicants can only record "دولة أخرى", with no free text.
   - Fix: when the stored value is not a code, render an extra selected option for the stored text, e.g. `value="__keep"` labelled with the stored text. In `profileAttributes()`, map `__keep` back to the stored value, or drop `nationality` from `$v`. Add a real untouched-save test for nationality, employer and bank. Consider extending the list, or adding a free-text field for "other".

#### Important
2. **The IBAN never pre-selects the bank (dead feature).**
   - Where: `resources/views/instructor/_employer_bank_fields.blade.php:47`.
   - What happens: the inline script runs when the bank partial is parsed. The IBAN input comes after it (profile.blade.php:95, admin 92), so `document.querySelector('input[name="iban"]')` returns null and the listener is never attached. No test covers the JS.
   - Fix: move the script into `@push('scripts')` (the layout stacks it at the end of `<body>`), or wrap it in `DOMContentLoaded`. While there, re-run `toggle(bank)` after the auto-select, otherwise the "other bank" field stays visible.
3. **A new applicant's nationality defaults to "دولة أخرى".**
   - Where: same lines as #1.
   - What happens: for a `new Instructor`, `strlen(null) !== 2`, so `ZZ` is pre-selected and `required` cannot catch it. An instructor filling the form on a phone can submit without noticing. The same code also calls `strlen(null)`, a PHP deprecation (logged only); `Instructor::nationalityLabel()` has the same problem.
   - Fix: add a leading `<option value="">{{ __('app.common.choose') }}</option>`, select it when the value is null, and use `strlen((string) …)`.

#### Minor
4. **Bootstrap JS has no SRI.** `resources/views/layouts/app.blade.php:55` loads the bundle without `integrity`/`crossorigin`, unlike the two CSS links. Add the 5.3.3 bundle hash from the Bootstrap docs.
5. **Guests on guest forms land on the login page.** `bootstrap/app.php:43`: a guest whose token expired on the register or forgot-password form is sent to login with "please sign in again" and loses the typed name/email. This is still better than the old plain 419 page. Fix: when `url()->previous()` is a guest route (register, password.*), use `back()->withInput($request->except(...))` with the session-expired message.
6. **Untranslated attribute names in errors.** No `attributes()` exist for `employer_other`, `bank_other`, `employer_choice`, `bank_choice`, so the Arabic error reads "حقل employer other مطلوب.". Add `attributes()` to `ProfileRequest` mapping to `app.profile.*` (covers the whole form).
7. **Choice validation runs late.** The invalid-choice checks fire only after validation passes, so a tampered choice is reported after the other errors, not with them. Prefer `Rule::in([...EMPLOYERS, 'private', 'other'])` and `Rule::in([...array_keys(BANKS), 'other'])` in `rules()`, and keep the exceptions as a backstop.
8. **Bank keys use slugs instead of IBAN codes.** In `app/Support/KuwaitLists.php`, the slug-keyed banks have known IBAN codes (from their BICs: BBK BBKU, FAB NBAD, HSBC BBME, Citi CITI, QNB QNBA), so they could auto-match. I could not confirm `IBKK` for Industrial Bank of Kuwait; check it.
9. **Sensitive fields are blanked after any error.** On both forms they now show empty after an error, so the admin must re-type the applicant's civil ID, IBAN and salaries, or cancel and reopen. This is consistent with the plan's test; noting the cost to the admin.
10. **LIKE escaping is inconsistent.** `app/Models/Section.php:43`: `\%` is not an escape in SQLite without `ESCAPE '\'`, so behaviour differs between test and MySQL. The reference, course and instructor filters are not escaped at all. Admin-only and parameterized, so not a security issue.
11. **Docs are inaccurate.**
    - `CLAUDE.md` says "271 tests … on main"; it is the branch.
    - It also says "all feedback items are covered in 5a" and then lists 5b leftovers.
    - The PROGRESS log line does not list the eighteen items and their coverage, as plan Task 6 asked.
12. **The sample test writes a file on every run.** `test_writes_sample_filled_document_for_visual_check` writes `storage/app/private/generated/sample-kh3.docx` each time the suite runs. The path is gitignored, but it is a side effect of the test run.
13. **Phone navbar.** The header now carries the name, role badge, up to 6 admin buttons, logout and the language toggle in a non-collapsing `d-flex`. It will wrap or overflow on a phone.

### Triage of ledger's deferred minors
- T5, no automated test on `doExportCSV`'s header shape: defer (other repo, already reviewed).
- T1, `set_tcw()` has no count assertion: defer. The week row's tcW values are pinned by the test and the server conversion was verified. Cheap hardening for a later round.
- T2, no `attributes()` translations: **fix before merge** (see #6). It is user-facing on the instructor form, and the new fields make it more visible.
- T3, SessionExpiryTest toggles `APP_RUNNING_IN_CONSOLE`: defer. The value is reverted in `tearDown`.
- T3, the admin header test does not assert the admin's own name: defer.
- T4, LIKE escaping only on name: defer (#10).
- T4, no empty-state message for unassigned-only: defer.
- T4, reference numbers sort as strings: defer, as long as jadawil references stay fixed-width.

### Declined to judge
- The jadawil commit 6b1a8a4: out of this diff and already reviewed. I only confirmed that DEPLOY.md's "v2.4.13+" is consistent with it (XLSX already carried the columns).
- Visual one-page fit and the footer rendering in LibreOffice: verified on the server by the controller; I cannot render here.
- Accuracy and completeness of the 38 agency names against the Sahel app: the source screenshots are not available to me.
- Whether production Apache or Cloudflare adds X-Frame-Options/CSP that would block the iframe: nothing in the repo sets one, but I cannot see the live headers.
- Whether PDFs render inside the iframe on mobile browsers (iOS/Android): device behaviour I cannot test; admins are assumed to use a desktop.

### Recommendations
- Fix #1–#3 and #6 (all small), and add tests for:
  - an untouched legacy save (nationality, employer and bank unchanged, no `nationality` in the audit details);
  - a new-profile render showing an empty nationality choice.
- Optionally add #4 (SRI) in the same commit.
- After deploy, regenerate the October test attestations so `courses_text` drops the parentheses, as the ledger plans.

### Assessment
**Ready to merge?** With fixes

**Reasoning:** Most of the work is solid and well tested. But the nationality select silently replaces every existing free-text nationality with "دولة أخرى" on the next save, and the IBAN auto-select never runs. Both are small fixes that must land before deploying to the live system.

## Post-review fix wave (commits 35ff3a4, 4846b7e, 72577a7, 8dbfe30, e48d1b2)

One fix dispatch covered the Critical finding, the two Important ones and the
minors below; one scoped re-review (d248030..e48d1b2) returned "all findings
addressed, no new Critical/Important breakage". Suite after the wave: 286
passed, 1 skipped (LibreOffice absent on the Mac), 1288 assertions.

- **F1 legacy nationality kept** — a `__keep` option carries a stored free-text
  nationality through an untouched save (mapped back to the stored value in
  both requests; rejected when the stored value is already a code); new
  profiles start on an empty choice so `required` catches it; the country list
  grew by 16 entries (SY, IQ, LB, PS, YE, SD, TN, MA, DZ, LY, IR, PH, BD, LK, NP,
  XB بدون) and the degree-country select reads the same list.
- **F2 IBAN auto-select** — the script is pushed to the layout's `scripts`
  stack, after the IBAN field, and re-toggles the "other bank" field.
- **F6 translated field names** in validation messages for the whole profile
  form.
- **Minors** — SRI on the Bootstrap bundle; an expired token on a guest form
  (register, forgot/reset password) returns to that form with its input; `Rule::in`
  on both choice fields built from `KuwaitLists`; bank keys BBKU/BBME/CITI/QNBA;
  `%`/`_` stripped from all section filters; docs wording; the sample-document
  test writes only with `KH3_WRITE_SAMPLE=1`; the navbar wraps on phones.

### Rulings made during the fix wave

- The fixer's commit trailer names its own model; accepted.
- Guest forms show "page expired, try again", not "sign in again"; accepted.
- `InstructorFactory` can emit a 13-digit civil ID (rare test flake, pre-existing);
  parked for 5b.
- `IBKK` for Industrial Bank of Kuwait unconfirmed; `fab` stays a slug.
- `degree_country` validated as `size:2|alpha` only (pre-existing); parked for 5b.

## Rulings made during execution (from the ledger)

- Pre-flight: the jadawil change is implemented and committed in its own
  repository and deployed by the controller after review; the one-page fit is
  verified on the server with a sample document rather than locally.
- Task 1: the table was widened to 10096 twips (overhanging both margins by 368,
  where the official table already overhung one side); accepted after the server
  conversion showed one page per instructor. Old `courses_text` keeps its
  parentheses until a month is regenerated.
- Task 2: the implementer fixed an array-union bug in the plan's
  `profileAttributes()` snippet; the bank select was moved beside the IBAN by
  splitting the shared partial into two sections (plan defect).
- Task 3: the milestone 1 "page expired" handler had never fired (Laravel wraps
  the token exception in a 419 before renderable callbacks); retyped with a
  guard that leaves other 419s alone.
- Final review "declined to judge", accepted: the jadawil commit was reviewed
  separately; LibreOffice rendering verified by the controller; agency names
  transcribed by the controller from the user's screenshots; live frame headers
  checked by the controller after deploy; mobile iframe behaviour not tested.

## Deferred minors (carry forward)

- Task 1: `set_tcw()` has no substitution-count assertion.
- Task 3: `SessionExpiryTest` toggles `APP_RUNNING_IN_CONSOLE`; the admin header
  test does not assert the admin's own name.
- Task 4: no empty-state message when only the `unassigned` checkbox matches
  nothing; reference numbers sort as strings.
- Task 5 (jadawil): no automated test on the CSV export's header shape.
- Final review: sensitive fields are blanked after any error (by design, costs
  the admin re-typing); mobile browsers may not render PDFs inside the iframe.

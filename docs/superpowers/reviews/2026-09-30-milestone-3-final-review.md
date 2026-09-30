# Final review — milestone-3-attestation (1a28da3..18333de)

### Strengths
- The generator (`app/Services/Attestations/AttestationGenerator.php:132-187`) follows spec §4 closely. I traced it by hand and it handles a term starting on Friday or Saturday (the whole block is skipped, so there is no empty row), a holiday on Friday (the day is never in the 0..4 loop), a whole week of holidays (a zero row with the holiday note), a month with no Sunday–Thursday day (zero rows, and the document prints one blank row), and `آخر يوم دراسي` on the last block. The tests pin both months of the official form row by row.
- Hours come only from meeting `minutes` and `applications.weekly_minutes` (`Section::hoursForForm`). This respects the milestone 2 deferred rule that `weekly_minutes` is the single source. `hoursForForm` is correct for 0, whole values, 1.25 and 1.33.
- The template pipeline is sound. I inspected `word/document.xml` of `resources/forms/kh3-template.docx`:
  - The whole page (title table to "ملاحظة مهمة") sits inside `${page}…${/page}`.
  - The signatures and "نموذج (خ-3)" stay in the footer and header, which have no placeholders.
  - `cloneBlock` indexing (`#p`) and `cloneRow` suffixes (`#p#k`) match.
  - Escaping runs before PhpWord's CR/LF→`<w:br/>` conversion.
  - The first page break is removed, and the zero-week blank row is handled.
- Exports are careful: random temp names, one LibreOffice profile per call deleted in `finally`, the Word download survives a PDF failure, the combined export's status/audit loop is inside a transaction and the PDF is removed on a throw, and the Process env is plain (the ledger's fix was right). Audit details hold only formats and column names.
- Policy plus `role:admin` cover all 8 routes, and 403 tests cover every route. The migrations only create new tables (FKs to `applications` restrict and `users` null-on-delete), so they are safe on the live MySQL.
- Checks I ran:
  - `php artisan test --compact`: 195 passed, 1 skipped (soffice).
  - `vendor/bin/pint --test`: it fails only on files this range does not touch (`bootstrap/app.php`, `app/Services/ChecklistDocument.php`, vendor-derived `lang/*/{auth,passwords,validation,…}.php`).
  - Lang parity script over `attestations.*`: no missing keys either way, no tashkeel.

### Issues

#### Critical (Must Fix)
None.

#### Important (Should Fix)

1. **An attestation disappears from the month page and the combined PDF once its instructor is no longer "listed".**
   - Where: `app/Http/Controllers/Admin/AttestationController.php:227-229` and `:329` (plus the dashboard at `app/Http/Controllers/Admin/DashboardController.php:407-421`).
   - What: all three read existing attestations only through `whereIn('application_id', listed()->pluck('id'))`.
   - Effect: an instructor who taught in June and had their sections unassigned in July loses the June attestation from the month page, from the June combined PDF (the pay form is silently missing from the printed batch) and from the "not exported" alert. It can only be reached by typing its URL, and the application page link is hidden once sections are empty (`applications/show.blade.php:1892`).
   - Why this is a defect: spec §5.1 says the combined PDF covers "all attestations of the month that exist". The plan's Review Focus #5 says "the month page still lists it (it exists)".
   - Plan defect: the plan's own pinned test (`AttestationsTest::test_generate_missing_skips_…`, `assertDontSee('أحمد سالم')`) contradicts its Review Focus #5, and the implementer followed the test.
   - Fix:
     - Rows = listed applications ∪ applications that have an attestation for the month (query attestations by `application.term_id` + year/month, not by the listed ids).
     - Combined and the unexported alert should query all of the month's attestations for the term.
     - Keep "generate missing" on `listed()` only.
     - Flip the test to `assertSee` and add a combined-PDF case.

2. **Browsers send textarea line breaks as CRLF, so multi-line cells look edited after any save.**
   - Where: `app/Http/Requests/AttestationUpdateRequest.php:478-492` and `app/Services/Attestations/AttestationService.php:1307-1313`.
   - What: submitted `note_ar`/`courses_text` arrive with `\r\n`. The generated twins use `\n`.
   - Effect: after the admin edits a single student count, every multi-line note and course list is stored as changed:
     - `update_attestation` audit details wrongly list `courses_text,note_ar`;
     - `isEdited()` becomes true;
     - the show page prints a spurious "المولد: …" under each such cell, identical to the value (`show.blade.php:2077,2092`).
   - The tests miss it because they post `\n` directly.
   - Fix: normalise `str_replace(["\r\n", "\r"], "\n", …)` in `weeks()` (or `prepareForValidation`), and add a test that posts CRLF with no changes and asserts empty change details.

3. **The combined and PDF exports probably will not look like the official form with the server's fonts.**
   - What: the template asks for `w:cs="Simplified Arabic"` (every table cell and header) and `"PT Bold Heading"` (the title and the footer signatures). Neither is on the server.
   - Effect: fontconfig will substitute whatever Arabic-capable font it ranks first (often DejaVu Sans), with different metrics.
   - Page fit: most months have 5 Sunday–Thursday blocks (e.g. October 2026: 1, 4-8, 11-15, 18-22, 25-29), and the official form was laid out for 4 week rows. So the "fits one page" risk is real and not covered by checking a 4-week month.
   - The `deploy/DEPLOY.md:1697-1700` remedy (`apt install fonts-sil-scheherazade fonts-kacst`) does not address the missing names.
   - Fix: add a fontconfig alias (e.g. `/etc/fonts/local.conf` mapping "Simplified Arabic" and "PT Bold Heading" to Amiri, the latter bold) and document it in DEPLOY.md. Make the post-deploy visual check use a 5-week month and a two-instructor combined PDF.

4. **The docs are stale or contradictory (ledger Task 7 findings, routed to the fix wave, still unfixed at HEAD).**
   - `deploy/DEPLOY.md:286-288`: still lists `cloudflare-trusted-proxies.sh` and `install-backup.sh` as "Still to run once". Both were run on 2026-09-30.
   - `PROGRESS.md`:
     - The status paragraph says "Live … since 2026-09-30" and also "the actual first deploy to the server has not been run yet".
     - "Next" dropped the true remaining post-deploy items: step 10 browser checks, the admin password change and deleting `/root/parttime-admin-initial.txt`, and the jadawil import.
     - "Decided" still says (خ-3) is "confirmed by the instructor", which decision 1 overturned.
   - `CLAUDE.md`:
     - The header says "149 tests" (now 195).
     - The design list omits the milestone 3 spec.
     - "Next step" still says "brainstorm/plan milestone 3".
   - Fix all of these in the final fix wave.

5. **The alert text doubles the word "month": "… لشهر الشهر الأول/ يونيو".**
   - Where: `lang/ar/app.php:1795-1796`. `:month` is the full `الشهر الأول/ يونيو` label.
   - Effect: this is user-facing formal Arabic on the dashboard.
   - Fix: `':n مزاولة غير مولدة في :month.'` / `':n مزاولة مولدة وغير مصدرة في :month.'`, and the English twin is already fine. The dashboard test uses `__()` so it keeps passing.

6. **Decrypted Word files can be left behind, and the backup would copy them.**
   - Where: `app/Http/Controllers/Admin/AttestationController.php:303-306` (the Word branch) and `:354-367` (`toPdfOrNull` catches only `\RuntimeException`).
   - What: if `markExported()` throws on the Word branch, or the converter throws anything other than a RuntimeException (e.g. Symfony Process `LogicException` when `proc_open` is unavailable, or an `Error`), the decrypted .docx (civil ID, IBAN, salaries) stays in `storage/app/private/generated/tmp`.
   - Why it is worse: `deploy/parttime-q8ee-backup.sh` tars all of `storage/app/private` nightly for 14 days, so a leftover is copied into backups.
   - Spec §7 says "every path deletes what it creates".
   - Fix:
     - Wrap the Word branch like the PDF branch (`unlink` + rethrow).
     - In `toPdfOrNull`, `unlink($docx)` in a `finally`, catch `\Throwable` for the fallback, or at least clean up and rethrow.
     - Add `--exclude=private/generated/tmp` to the backup tar.
   - Two ledger deferred minors (Task 6) belong here.

#### Minor (Nice to Have)
1. `app/Services/Attestations/AttestationService.php:1299-1320`:
   - `update()` is not wrapped in a transaction and does not lock or recheck status. A regenerate or export racing with a save has a window the width of one request.
   - Saves whose week ids no longer exist (after another admin regenerated) are silently ignored, yet still report "saved".
   - It writes an audit row with empty details when nothing changed.
   - Fix: `DB::transaction` + `lockForUpdate()` re-read of the attestation, skip the audit when `$changed === []`, and refuse when no posted id matched.
2. `app/Services/Attestations/AttestationGenerator.php:1168`: `$minutes[$m->type] +=` raises "Undefined array key" (500) for any meeting type outside theory/practical/field. `section_meetings.type` is a free string (milestone 2 T6 deferred). Guard with `isset`, or map unknown types to theory with a report.
3. `AttestationGenerator.php:1201-1205`: if `teaching_ends_on` falls on a Friday or Saturday, the "آخر يوم دراسي" line never prints, because only Sunday–Thursday days are scanned. Check the block's Sunday..Saturday range instead.
4. `resources/views/admin/attestations/show.blade.php:2036-2049`: spec §5.2 asks for a header block with the masked civil ID and IBAN. The page shows neither (the name appears only in the title). Add them using the milestone 1 mask helpers.
5. `scripts/build-kh3-template.py:2306-2311`: every cloned page after the first starts with an empty `pageBreakBefore` paragraph, one line taller than page 1. That matters for page fit (Important #3). Consider a `<w:br w:type="page"/>` run at the end of the block instead.
6. Placeholder injection: admin-typed notes or courses containing `${…#n}` are expanded by later `setValues` calls (`AttestationDocument.php:958-971`). This is admin-only, but it can pull another page's civil-ID digits into the text. Strip or escape `${` in free-text values.
7. `AttestationDocument.php:996`: prints `j/n/Y` where spec §6.1 says `d/m/Y`. This follows the plan (plan line 2443). Either amend the spec or pad the date.
8. `tests/Feature/Admin/AttestationExportTest.php:2376,2388,2441`: `sendContent()` prints `%PDF-1.4 fake` into the test output. Wrap it in `ob_start()`/`ob_end_clean()`.
9. `app/Services/Attestations/PdfConverter.php:1416`: with PHP-FPM's `clear_env`, `soffice` resolves through the `exec('command -v')` fallback and runs with no `HOME`. Set `SOFFICE_PATH=/usr/bin/soffice` in the production `.env` (document it in DEPLOY.md), and consider passing `['HOME' => $profile]` as a fixed Process env value. That does not reintroduce the FPM-leak concern.
10. The week-note cell inherits `<w:u w:val="single"/>` (template `document.xml` offsets 43109/43346), so every generated note is underlined. Decide at the visual check. If it looks wrong, drop `<w:u>` from that cell's rPr in the build script.

### Triage of ledger's deferred minors
- **T1** (factories unexercised, `months()`/`monthIndex()` not memoised, `Term::attestations()` untested): factories are now exercised (resolved). The rest can wait. `Term::attestations()` is unused and could be dropped.
- **T2**, no Friday/Saturday term-start test: can wait. The code is correct by my trace, but a 5-line test is cheap and worth adding in the fix wave.
- **T2**, no `seats_registered=null` test: can wait.
- **T2**, TOCTOU in `generate()`: can wait. The unique key protects integrity, and the worst case is a 500 on a concurrent "generate missing". Fold it into Minor #1 if touched.
- **T3**, `generateMissing` runs `exists()` per application: can wait (about 10 instructors).
- **T3**, `none_for_month` unused: resolved (used by `combined`).
- **T3**, 404 on an out-of-range month: can wait.
- **T3**, sort not locale-aware: can wait.
- **T4**, hard-coded `—`, the forged PUT on a locked page showing a validation error, repeated FQCN calls: all can wait.
- **T5**, note underline: can wait until the visual check (Minor #10).
- **T5**:
  - Total assertions collide with week values: can wait, though tightening them with `rowXml` like the empty-hours test would be better.
  - Civil ID length unchecked: can wait (intake validates 12 digits).
  - Global `Settings` escaping: can wait.
  - `combinedDocx([])`: can wait (the controller guards it).
  - Build-script hygiene: can wait.
  - PhpWord 1.4 vs 1.3: can wait.
  - Unused `$courses`: can wait.
  - One `sections()` query per page: can wait.
- **T6**, the Word branch leaving the .docx and `toPdfOrNull` catching only RuntimeException: **must fix** (Important #6). Both concern plaintext sensitive files left in storage and in backups.
- **T7**, stale CLAUDE.md "Next step", contradictory PROGRESS.md, doubled "لشهر الشهر": **must fix** (Important #4, #5). The same goes for the stale DEPLOY.md line and the dropped post-deploy items.

### Declined to judge
- The download is a GET that changes state (sets `exported` and writes an audit row), so a cross-site top-level navigation could flip an attestation to exported. Set aside because spec §5.2/§6.2 mandate GET downloads, and the impact is only a lock the admin can undo.
- Header values (IBAN, salaries, course list, weekly hours) are read live at each export, so a reprint after a profile or assignment change differs from the original print. Set aside because spec §3/§6.1 define these fields as coming from `Instructor`/`Application`/assigned sections, not stored; flagged here as a spec-level question against "reprinted identically" (§2.5).
- Regenerating after unlock nulls `exported_at`. Set aside because spec §4 step 9 requires `exported_at = null`, although §5.2 calls it history.
- Editing term holidays after generation does not mark existing attestations as stale. Set aside because spec §10 says the admin regenerates. A future "holidays changed since generation" hint would help.
- Editing a term's teaching dates after generation can make `monthIndex()` null (the title falls back to "الأول"). Set aside because the spec is silent and this is outside milestone 3's flows.
- Letter paper size (`w:pgSz 12240×15840`) inherited from the official file. Set aside because it is faithful to the source form; confirm at the print check.
- The fidelity of retyped header paragraphs (`set_para` keeps only the first run's formatting, and label/value spacing is made with spaces). Set aside because I cannot judge it without rendering; the plan defers it to the post-deploy visual check.
- Arabic name ordering by byte order in `listed()`/`combinedDocx`. Set aside as a ledger-deferred item.
- Instructor-facing screens, email and scheduler. Set aside as out of scope by decision 1.

### Recommendations
- Fix wave, in order: Important #1 (listed vs existing), #2 (CRLF), #6 (temp-file cleanup plus backup exclude), #5 (alert wording), #4 (docs), and #3 (DEPLOY.md fontconfig alias plus a 5-week check). Each has a small, local fix, and #1, #2 and #6 each deserve a regression test.
- At deploy:
  - Set `SOFFICE_PATH` to an absolute path.
  - Add the font aliases, then download a single PDF for a 5-week month and a combined PDF for 2+ instructors.
  - Confirm the result fits one page per instructor, the Arabic is shaped, the footer signatures are present, and the notes are legible and not underlined, if that is the decision.
- Consider pre-running `soffice --headless --terminate_after_init` once as `www-data` with a temporary profile, so the first real export does not pay LibreOffice's first-run cost inside the 90 s timeout.

### Assessment
**Ready to merge?** With fixes

**Reasoning:** The domain logic, template pipeline, security coverage and tests are solid and match the spec. However, the listed-only query drops existing attestations from the month page and the combined pay-form PDF. The CRLF bug corrupts the edit markers and the audit record. Decrypted temp files can still be left behind in two code paths, where the nightly backup would copy them. Several docs lines that the ledger routed to the fix wave are still wrong. All of these are small fixes that should land before the live deploy.

## Post-review fix wave (commits 4f03c76, 0054956, 449aaa6, 6f22036, b0147da)

One fix dispatch covered all six Important findings and eight small minors; one
scoped re-review (18333de..b0147da) returned "all findings addressed, no new
Critical/Important breakage". Suite after the wave: 207 passed, 1 skipped (the
LibreOffice unit test on a Mac without `soffice`), 882 assertions.

- **F1 existing attestations stay visible** — `AttestationService::monthAttestations()`
  returns every attestation of the term for a month; the month page rows are
  the listed applications plus any application with an attestation that month;
  the combined PDF and the "unexported" alert cover all of them; "generate
  missing" and the "missing" alert stay on the listed set; the application page
  links to attestations for approved applications with sections or attestations.
- **F2 CRLF** — `AttestationUpdateRequest::prepareForValidation()` normalises
  line endings of `courses_text` and `note_ar`, so a save no longer marks every
  multi-line cell as edited.
- **F3 fonts and page fit** — DEPLOY.md gained a LibreOffice section
  (`SOFFICE_PATH=/usr/bin/soffice`, fontconfig aliases mapping "Simplified
  Arabic" and "PT Bold Heading" to Amiri, a warm-up run as www-data, a visual
  check with a 5-week month and a two-instructor combined PDF); the template's
  page break moved to the end of the page block (`stripLastPageBreak()`), so
  cloned pages no longer start with an empty paragraph.
- **F4 docs** — DEPLOY.md, PROGRESS.md and CLAUDE.md corrected (scripts already
  run, remaining post-deploy items, admin-signed-on-paper decision, 207 tests,
  milestone 3 spec listed, next step).
- **F5 alert wording** — "في :month" instead of "لشهر :month".
- **F6 temp files** — the Word branch unlinks and rethrows; `toPdfOrNull()`
  catches `\Throwable` and unlinks the docx in `finally`; the nightly backup
  excludes `private/generated/tmp` (re-run `deploy/install-backup.sh` on the
  server to install the new script).
- **Minors fixed** — unknown meeting type counted as theory with `report()`;
  "آخر يوم دراسي" printed when the term ends on a Friday/Saturday; no audit row
  when a save changes nothing; `${` stripped from free text before filling the
  template; masked civil ID and IBAN in the attestation header; decision date
  `d/m/Y`; test output buffering; a Friday-start regression test.

### Rulings made during the fix wave

- `PdfConverter` is no longer `final` so the tests can stub it.
- The backup exclude reaches the server only after `install-backup.sh` is
  re-run: a documented post-deploy item.
- The page-break change is verified at the XML level; the deploy visual check
  covers rendering.
- Parked from the re-review: `plain()` strips `${` once, so a nested `$${{`
  rebuilds a token. Admin-only free text, worst case wrong text on a printout.
  Fix in milestone 4 together with `update()` locking.

## Rulings made during execution (from the ledger)

- Pre-flight: the category text lives at `app.attestations.category` instead
  of the spec's `app.kh3.category` (one lang block for the milestone).
- Task 3: `index()` called `listed()` twice (plan defect) — fixed in a fix round.
- Task 4: the implementer split the index view's `Route::has()` guard so the
  download links waited for their own route — accepted; the brief's test count
  (14) was a miscount (13).
- Task 5: zero-week attestations print one blank row instead of raw
  placeholders (a month whose window has no Sunday–Thursday day is possible);
  the vacuous "blank not 0" test was replaced by a row-scoped assertion; the
  test helper reuses the term (unique key); the template has 50 placeholders,
  not the brief's 46.
- Task 6: the explicit `getenv()` process environment was reverted (it
  bypassed PHP-FPM request-context filtering) and the failure test sets
  `$_SERVER` instead; the duplicated convert block became `toPdfOrNull()`; a
  `markExported()` failure after conversion now unlinks the PDF and the
  combined loop runs in a transaction.
- Task 7: the brief's `assertDontSee('مزاولة')` was unsatisfiable (the nav
  link contains the word) — replaced by the alert phrases; its docs finding
  was routed to the final fix wave.
- Final review "declined to judge" items, all accepted per spec: GET downloads
  change state (an undoable lock); header values are read live at each export
  (spec-level question for milestone 4); regenerate nulls `exported_at`;
  holidays or term dates edited after generation require a regenerate;
  Letter page size inherited from the official file; byte-order name sort.

## Deferred minors (carry into milestone 4)

- Task 1: `months()`/`monthIndex()` not memoised; `Term::attestations()` unused.
- Task 2: no explicit `seats_registered = null` test; TOCTOU between the
  existing-attestation lookup and the transaction in `generate()`.
- Task 3: `generateMissing()` runs one `exists()` per application; a month
  outside the term or a term without months gives a bare 404; `listed()` sort
  is byte order, not locale-aware.
- Task 4: hard-coded "—" placeholder in the show view; a forged PUT on a locked
  page shows a validation error rather than the domain error; repeated FQCN
  `hoursForForm` calls in the view.
- Task 5: total assertions collide with week values in the document test; civil
  ID length unchecked in the builder (intake validates 12 digits);
  `Settings::setOutputEscapingEnabled` is global; `combinedDocx([])` yields an
  empty body (the controller guards it); build-script hygiene (`assert`,
  `next()`, unclosed zip, no `src == out` guard, the "2025-2026" needle);
  PhpWord is 1.4.0 not 1.3; unused `$courses` in a test; one `sections()`
  query per page; the week-note cell inherits the official form's underline
  (decide at the visual check).
- Task 6: none open (both routed into the fix wave).
- Task 7: none open (routed into the fix wave).
- Final review minors left open: `update()` has no transaction/lock and
  silently ignores stale week ids; `PdfConverter` relies on PATH unless
  `SOFFICE_PATH` is set (documented); a term ending on a Friday/Saturday that
  is the 1st or 2nd of a month prints no last-day line; regenerate is reachable
  for an unlisted application's attestation (empty weeks); a `.pdf` written by
  a non-zero-exit `soffice` run stays in tmp; nested `$${{` in free text.

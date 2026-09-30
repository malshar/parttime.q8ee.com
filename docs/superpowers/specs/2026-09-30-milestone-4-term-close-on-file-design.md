# parttime.q8ee.com — Milestone 4 Design: Term Close, On-File Documents, Parked Fixes

Date: 2026-09-30
Owner: Dr. Mishal E. AlSharidah
Status: approved in conversation; awaiting review of this written spec
Extends: `2026-09-28-parttime-system-design.md` (system spec, "After the
term" items 14–15) and the milestone 1–3 specs. Where this document and the
system spec differ, this document wins for milestone 4.

## 1. Purpose

Milestones 1–3 are live: intake, committee workflow, section assignment and
the monthly (خ-3) attestation. Milestone 4 closes the term lifecycle and
makes the second term cheap for everyone:

1. **Term close with clear semantics.** Today "close" flips a flag and every
   flow already refuses writes on a closed term. What is undefined is what
   happens to applications that are not final at that moment.
2. **On-file documents (على الملف).** A returning instructor should not
   upload a degree certificate again. Documents the department already
   accepted in an earlier term satisfy the new application's checklist,
   unless the item renews each term or the civil ID has expired, and the
   admin can still demand a fresh copy.
3. **Parked fixes from the milestone 3 final review**, folded in so the
   attestation area is tidy before the first real month is printed.

## 2. Decisions (locked 2026-09-30)

1. **Closing is blocked while applications are unfinished.** The close
   action refuses if any application in the term is `submitted`,
   `under_review`, `incomplete` or `complete`, and lists them. Nothing is
   decided in bulk. Never-submitted `draft` applications are withdrawn
   automatically at close (audited, no email).
2. **On-file source = any document the department accepted in an earlier
   term** of the same instructor, whatever that application's outcome.
3. **Admin override exists:** "طلب نسخة جديدة" per on-file row, with a
   reason shown to the applicant.
4. **Derived, not copied.** On-file rows are computed when the checklist is
   read; no document rows are duplicated. Overrides are the only new state.
5. **Parked milestone 3 items are included** (section 6).
6. The unused `archived` term status is removed from the code.

## 3. Term close

### 3.1 Rules

`ApplicationWorkflow::closeTerm(Term $term, User $admin): int`:

- Refuses (`DomainException`, key `app.terms.close_blocked`) when the term
  has applications in `submitted`, `under_review`, `incomplete` or
  `complete`. The controller shows the message and the terms page lists the
  blocking applications (instructor name, status, link to the review page).
- Otherwise, inside one transaction: every `draft` application of the term
  becomes `withdrawn` (`decided_at = now()`), the term becomes `closed`, one
  audit row `close_term` on the term with details `drafts_withdrawn=<n>`.
- Returns the number of drafts withdrawn; the flash message states it.
- Reopening a closed term is not a feature (unchanged from milestone 1).

### 3.2 What closed means (unchanged, restated)

Instructors cannot start, edit, upload, submit or withdraw; admins cannot
review, decide, reopen, assign, import, generate, edit or unlock
attestations, or edit the term. Reads and downloads (documents, Check List,
attestation Word/PDF, combined PDF) stay available. `Term::STATUS_ARCHIVED`
and its lang key are removed; the `status` column keeps its string type.

## 4. On-file documents

### 4.1 Data model

New table `checklist_renewals` ("request a new copy" overrides):

- `application_id` (FK, `cascadeOnDelete`), `checklist_item_id` (FK,
  `restrictOnDelete`), `reason` (string 500), `requested_by` (users FK,
  `nullOnDelete`), `requested_at`; unique `(application_id, checklist_item_id)`.

No change to `documents`. The checklist row shape gains one state and two
fields:

```
['item' => ChecklistItem, 'document' => ?Document, 'state' => string,
 'source' => ?Document,      // the earlier accepted document when state = on_file
 'renewal' => ?ChecklistRenewal]
```

`state` ∈ `missing | pending | accepted | rejected | on_file`.

### 4.2 Derivation (in `ApplicationWorkflow::checklist()`)

For each required item of the plan, in this order:

1. A document in **this** application → its status (`pending`, `accepted`,
   `rejected`), as today. A rejected document with no newer version stays
   `rejected`; on-file is not consulted.
2. A `checklist_renewals` row for this application and item → `missing`,
   with `renewal` set so the screens can show the reason.
3. `renews_each_term = true` (salary certificate, employer approval,
   undertaking) → `missing`.
4. Item `civil_id` and `instructor.civil_id_expires_on <= today` → `missing`
   (skipped for final applications, so an approved record does not change
   once the card expires).
5. The latest accepted document of this item in any **other** application of
   the same instructor whose term `teaching_starts_on` is earlier than this
   application's term → `on_file`, `source` = that document.
6. Otherwise `missing`.

**Rule 4b (implementation note, ruled in the final fix wave).** A source
found by rule 5 is dropped (the row falls through to `missing`) when a
profile field the item certifies changed after the source was accepted. The
mapping is `ChecklistItem::PROFILE_FIELDS` (civil_id: civil ID and expiry;
degree and equivalency: degree fields; experience: experience years and
highest degree; social_insurance: employer and sector; iban: IBAN, bank
and branch; other items have no profile dependency). Changes are read from
the audit log: `edit_profile` (instructor self-edit, now audited) and
`admin_edit_profile` rows whose subject is the instructor or one of the
instructor's applications, with `created_at` after the source's
`reviewed_at` and whose `details` (a comma list of field names, never
values) names a mapped field. `onFileDocuments()` reads all such rows for
the instructor in one query and filters in PHP. Like rule 4, rule 4b is
skipped for final applications, so an approved record does not change after
a later profile edit.

"Latest" = highest `reviewed_at`, then highest id. Only `accepted` documents
qualify; `pending`/`rejected` never do. Drafts from other terms can hold
accepted documents only if they were reviewed, which cannot happen, so the
rule needs no status filter on the source application.

`on_file` counts as satisfied everywhere `accepted` does:
`allRequiredUploaded()`, `allRequiredAccepted()`, `markComplete()`, the
committee step, and the printed Check List (☑). `pendingRejectionNotices()`
and the incomplete flow ignore on-file rows.

### 4.3 Uploading over an on-file item

`DocumentPolicy::create` already allows uploading any required item while
the application is editable; that stays. An upload for an on-file item
creates a version-1 document in this application, and rule 1 then takes
precedence. Nothing is deleted.

### 4.4 Admin override — "طلب نسخة جديدة"

`ApplicationWorkflow::requestFreshCopy(Application, ChecklistItem, User $admin, string $reason): void`:

- Preconditions: term open; application status in `submitted`,
  `under_review`, `incomplete`, `complete`; the row's current state is
  `on_file`.
- Creates the `checklist_renewals` row (upsert on the unique key), sets the
  application to `incomplete` (from `under_review` or `complete`, like a
  document rejection does; `submitted` first becomes `under_review` then
  `incomplete` through the existing transition), clears `complete_at`, audits
  `request_fresh_copy` on the application with details = the item code.
- The applicant is informed through the existing consolidated rejection
  notice: `pendingRejectionNotices()` includes renewal rows not yet
  notified (add `notified_at` to `checklist_renewals`), and the
  `DocumentsRejected` mail lists them under the same list with the reason.
- Withdrawing the request: not a feature. The admin accepts the fresh upload
  when it arrives; if none is needed after all, the admin accepts the
  application's state by other means (out of scope).

### 4.5 Screens

- **Instructor application page:** an on-file row shows the badge
  `على الملف` and the source term's label (`app.documents.on_file_from`
  with `:term`), no "required" marker, and the upload control labelled
  "رفع نسخة أحدث (اختياري)". A renewal row shows the reason above the upload
  control (`app.documents.renewal_requested` + reason).
- **Admin application page:** an on-file row shows the badge, a link to the
  earlier document (existing admin download route, policy-checked), the
  source term, and, while the term is open and the application is in a
  reviewable status, a small form "طلب نسخة جديدة" with a required reason
  (max 500). A renewal row shows the reason and who requested it.
- **Attention list / dashboard:** unchanged; an application with only
  on-file and accepted items is "complete" as today.
- **Printed Check List:** on-file rows print ☑ like accepted rows.
- **Terms page:** the close form shows the blockers when closing was refused
  (rendered from the flash, listing name, status and link).

## 5. Security

- Renewal rows and the close action are admin-only through the existing
  policies (`ApplicationPolicy::review` for the renewal action; the terms
  controller keeps its `role:admin` guard). Instructors get 403.
- Audit: `close_term` (details `drafts_withdrawn=<n>`), `request_fresh_copy`
  (details = item code). No sensitive values in details.
- The earlier document is only ever served through the existing download
  routes, which already check ownership (instructor) or admin. An on-file
  row never exposes a path.
- Withdrawn drafts at close are ordinary withdrawals: no email, no data
  deleted.

## 6. Parked milestone 3 items (all included)

1. `AttestationService::update()` runs in a transaction, re-reads the
   attestation with `lockForUpdate()`, re-checks the editable state, refuses
   (`app.attestations.stale_form`) when no posted week id belongs to the
   attestation, and skips the audit row when nothing changed (already done).
2. `AttestationDocument::plain()` strips `${` repeatedly until stable.
3. `PdfConverter::convert()` sets the process working directory to the
   output directory and deletes a `.pdf` left behind when `soffice` exits
   non-zero.
4. `AttestationGenerator`: the "آخر يوم دراسي" line is printed in the last
   block that has any day inside the term's window, even when
   `teaching_ends_on` is a Friday or Saturday on the 1st/2nd of a month
   (the line goes to the previous month's last block).
5. `AttestationService::regenerate()` refuses (`app.attestations.no_assignments`)
   when the application has no assignments; the show page hides the button.
6. Template: the week-note cell no longer carries the official form's
   underline (build script clears `<w:u>` in that cell) — unless Dr. Mishal's
   visual check says to keep it; the plan makes it a one-line switch.
7. `Term::attestations()` (unused) removed.

## 7. Testing

- Term close: refused with the list when a submitted/under_review/
  incomplete/complete application exists; drafts withdrawn, others
  untouched, audit row with the count, flash message with the count;
  closing an already closed term is 403 as today.
- Derivation, one test per rule in §4.2, plus: two earlier terms with
  accepted copies (the latest wins); an earlier `pending` copy (not on file);
  an earlier accepted copy in a term that starts **later** (not on file);
  civil ID expiring today (not on file) and tomorrow (on file); a renewal row
  wins over an earlier copy; a document uploaded in this application wins
  over both.
- Submission and completeness: an application whose only satisfied items are
  on-file can be submitted and marked complete; the committee can approve
  it; the Check List prints ☑ for the on-file rows (document test).
- Renewal action: creates the row, moves the status to `incomplete`, audits,
  is refused on a non-on-file row, on a closed term and for instructors
  (403); the notice email lists the renewal with its reason once.
- Screens: instructor page shows the badge and source term; admin page shows
  the link and the form only when allowed.
- Parked items: a regression test each (concurrent save refused on stale
  ids; nested `$${{` printed literally; a fake `soffice` that exits 1 after
  writing a PDF leaves nothing; term ending Saturday 2026-08-01 puts the
  last-day line in July; regenerate refused without assignments; the note
  cell has no `<w:u>` in the template).
- Lang parity and no tashkeel, as in every milestone.

## 8. Delivery

One plan, subagent-driven like milestones 1–3, in this order: term close;
`checklist_renewals` + derivation + completeness rules; renewal action +
notice; screens + Check List; parked attestation items; docs. Ends deployed
with `./deploy/deploy.sh` (one new migration, no server steps).

## 9. Out of scope

- Reopening a closed term; archiving.
- Expiring or bulk-deciding unfinished applications (decision 1).
- Instructor-level document library (approach C).
- Withdrawing a renewal request.
- Anything in the milestone 2 and 3 deferred-minor lists not named in §6.

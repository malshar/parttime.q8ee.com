# parttime.q8ee.com — Milestone 5b Design: Two-Stage Documents

Date: 2026-10-02
Owner: Dr. Mishal E. AlSharidah
Status: approved in conversation; awaiting review of this written spec
Extends: `2026-09-28-parttime-system-design.md` (system spec) and the
milestone 1–5a specs. Where this document and an earlier spec differ, this
document wins for milestone 5b.

## 1. Purpose

The committee (لجنة التوظيف والانتداب) decides on a reduced file: identity,
degrees, transcripts and equivalency. Everything else is collected only after
the committee approves, and one item (the employer approval) cannot even be
requested before approval, because PAAET first writes to the employer.
Today the system demands every document before submission and before the
committee step, which blocks real applicants (feedback items 4, 6, 7, 12
and 13 of 2026-10-02).

Milestone 5b:

1. Splits the applicant's checklist into **stage 1** (before the committee)
   and **stage 2** (after approval), and moves the submission and
   completeness gates to stage 1.
2. Lets an applicant **request an exemption** from a transcript instead of
   uploading it, for the committee to accept or reject.
3. Collects the **salary fields after approval**.
4. Makes stage 2 the condition for the monthly (خ-3) attestation.
5. Allows **several files per upload**.
6. Folds in the small items parked from the 5a review.

## 2. Decisions (locked 2026-10-02)

1. The status pipeline is unchanged. `approved` remains the committee's
   final status; "stage 2 complete" is **derived** from the checklist and
   the profile, never stored as a status.
2. Stage 1 items: civil ID, degree certificate, equivalency (foreign
   degree), experience certificate (bachelor holders), bachelor transcript,
   master transcript. Stage 2 items: social insurance certificate (private
   sector), recent salary certificate, IBAN letter, employer approval,
   undertaking form.
3. Transcripts are **required** again (reverting the 2026-10-02 "all
   optional" stopgap) and are the only **exemptable** items.
4. Salary fields (basic, total) are optional until the instructor has an
   approved application in an open term; from then on they are required and
   editable through a dedicated form, even though the rest of the profile is
   locked.
5. The employer request letter is written outside the system. The item only
   unlocks after approval and carries a note saying the college requests it.
6. Attestations are generated only for approved applications whose stage 2
   is complete. Section assignment is not blocked.
7. Multi-file upload: up to 10 files per upload, stored as parts of one
   version; review applies to the version.
8. The official printed Check List keeps its 12 items; it is driven by a new
   `official` flag, not by `optional`.

## 3. Data model

### 3.1 `checklist_items` (two new columns, one repurposed)

- `stage` unsigned tinyint: `1` committee, `2` after approval; department
  items keep `0` (they are never uploaded).
- `exemptable` boolean, default false: the applicant may request an
  exemption instead of uploading. Seeded true for `transcript_bachelor` and
  `transcript_master`.
- `official` boolean, default true: printed on the Check List. Seeded false
  for the two transcripts.
- `optional` stays as introduced on 2026-10-02 (rows that never block and
  are not printed) but is seeded **false for every item**; the column is
  kept for future use and `official` now decides printing.

Seeder (order and `sort_order` unchanged from today; new columns only):

| code | stage | condition | exemptable | official |
|---|---|---|---|---|
| schedule, assignment_letter, attestation | 0 | always (department) | no | yes |
| civil_id | 1 | always | no | yes |
| degree | 1 | always | no | yes |
| transcript_bachelor | 1 | always | **yes** | no |
| transcript_master | 1 | master_or_above | **yes** | no |
| equivalency | 1 | foreign_degree | no | yes |
| social_insurance | 2 | private_sector | no | yes |
| experience | 1 | bachelor_only | no | yes |
| salary_cert | 2 | always | no | yes |
| iban | 2 | always | no | yes |
| employer_approval | 2 | always | no | yes |
| undertaking | 2 | always | no | yes |

`note_ar` keeps the official wording of every item (it prints on the Check
List). The stage-2 explanation is a lang string shown under the stage-2
section on the instructor page, `app.documents.stage2_hint` ("تطلب بعد
اعتماد اللجنة"), and the employer approval row gets one extra line,
`app.documents.employer_letter_hint` ("تطلبها الكلية من جهة العمل بعد
الاعتماد، ثم ترفع نسخة الموافقة هنا").

### 3.2 `checklist_exemptions` (new)

- `application_id` FK cascade, `checklist_item_id` FK restrict, unique pair.
- `reason` string 500 (applicant's), `requested_at`.
- `status` string 10: `pending | accepted | rejected`.
- `decided_by` users FK null-on-delete, `decided_at`, `decision_note`
  string 500 nullable (shown to the applicant when rejected).
- `notified_at` nullable: a rejected exemption is included in the
  consolidated rejection notice once.

A row exists only for exemptable items. Re-requesting after a rejection
updates the same row back to `pending` with the new reason (`decided_*`
cleared), so the history lives in the audit log, not in rows.

### 3.3 `documents` (one new column)

- `part` unsigned smallint, default 1. Unique key becomes
  `(application_id, checklist_item_id, version, part)`.
- A multi-file upload creates N rows with the same new `version` and
  `part` 1..N. `status`, `rejection_reason`, `reviewed_*`, `notified_at` are
  kept identical on all parts of a version; `part = 1` is the **head** and
  is the row the review routes receive.
- `Application::latestDocuments()` keeps returning one `Document` per item
  code (the head of the highest version) and each head gets a loaded
  relation `parts` (all rows of that version, ordered by part).

### 3.4 `instructors`

No schema change. `basic_salary` and `total_salary` are already nullable
encrypted columns; only validation changes (section 6).

## 4. Checklist derivation

`ApplicationWorkflow::checklist()` returns one row per applicable applicant
item, stage 1 rows first, then stage 2, in `sort_order` within each stage.
Row shape:

```
['item' => ChecklistItem, 'document' => ?Document (head, with parts),
 'state' => string, 'source' => ?Document, 'renewal' => ?ChecklistRenewal,
 'exemption' => ?ChecklistExemption, 'stage' => 1|2, 'optional' => bool]
```

`state` ∈ `missing | pending | accepted | rejected | on_file |
exemption_requested | exempted`.

Order of rules per item (extends M4 §4.2):

1. A document in this application → its status.
2. An **accepted** exemption → `exempted`.
3. A **pending** exemption → `exemption_requested`.
4. A renewal row → `missing` (as M4).
5. `renews_each_term` → `missing`.
6. Civil ID expired → `missing` (as M4, skipped for final applications).
7. Latest accepted earlier copy → `on_file` (M4 rules 5 and 4b).
8. Otherwise `missing`. A rejected exemption is `missing` with
   `exemption` set, so the pages can show the decision note.

`ChecklistPlan` gains `stage1` and `stage2` collections (applicable
applicant items by stage); `required` = `stage1 ∪ stage2` minus optional,
`isUploadable()` unchanged in meaning.

## 5. Gates and transitions

Satisfied states: `accepted`, `on_file`, `exempted`.

- **Submit** (`allRequiredUploaded`, stage 1 only): every stage-1 row is
  not `missing` and not `rejected`. `exemption_requested` passes.
- **Mark complete** (`allRequiredAccepted`, stage 1 only): every stage-1 row
  is satisfied. `pending` and `exemption_requested` block, with the existing
  message plus a new one, `app.review.complete_blocked_exemptions`, when the
  only blockers are undecided exemptions.
- **Committee decision**: unchanged (requires `complete`).
- **Stage 2 complete** (`stageTwoComplete(Application): bool`): status is
  `approved`, every stage-2 row is satisfied, and the instructor's
  `basic_salary` and `total_salary` are both non-null. Optional rows are
  ignored. `stageTwoMissing(Application): list<string>` returns the missing
  item labels plus `app.profile.salary_missing` when the salary is missing
  (for the dashboard and the attestation page).
- **Uploads after approval**: `DocumentPolicy::create` allows an upload when
  the application is `approved`, the term is open, and the item is stage 2
  (or an optional item). `Application::isEditable()` is unchanged (it still
  means draft/incomplete); a new `acceptsStageTwoUploads()` expresses the
  approved-and-open case and the views use it.
- **Review after approval**: `reviewDocument()` allows accepting or
  rejecting a stage-2 document while the application is `approved` and the
  term is open. A stage-2 rejection does **not** change the status; it is
  announced through the existing consolidated notice (`notifyRejections`),
  which now also works in `approved` status. Stage-1 documents of an
  approved application stay read-only.
- **`afterUpload()`**: unchanged for `incomplete`; no status change in
  `approved`.
- **Fresh copy** (M4 §4.4) stays limited to the unfinished statuses; stage-2
  on-file rows of an approved application can be renewed through the same
  action without a status change (precondition widened to `approved`
  without setting `incomplete`).
- **Term close**: unchanged; approved applications never block it,
  whatever their stage-2 state.
- **Withdraw**: unchanged.

## 6. Exemption requests

`ApplicationWorkflow::requestExemption(Application, ChecklistItem, string $reason): void`
(applicant): preconditions — the application is editable (draft or
incomplete, term open), the item is stage 1, exemptable, applicable, and the
row is `missing` or `rejected`-exemption. Upserts the row as `pending`,
audits `request_exemption` (details = item code). Uploading a document for
the item later does not delete the row; rule 1 wins and the admin page shows
the request as superseded.

`ApplicationWorkflow::decideExemption(ChecklistExemption, User $admin, string $status, ?string $note): void`
(admin): preconditions — term open, application in a reviewable status
(`under_review`, `incomplete`, `complete`) and the row `pending`. Sets the
decision; a rejection moves the application to `incomplete` (like a
document rejection) and clears `complete_at`; audits
`exemption_accepted` / `exemption_rejected` (details = item code, never the
reason). `pendingRejectionNotices()` includes rejected exemptions not yet
notified; `DocumentsRejected` lists them with the decision note under the
same list as documents and renewals.

## 7. Salary after approval

- `ProfileRequest`: `basic_salary` and `total_salary` become `nullable`
  (`total_salary` keeps `gte:basic_salary` when both present). The profile
  form keeps the fields; before approval the labels carry
  `app.profile.salary_later` ("يمكن تعبئتها بعد اعتماد اللجنة").
- New route `PUT /my/salary` (`instructor.salary.update`,
  `SalaryRequest`: both required numeric, same bounds) allowed only when
  `Instructor::hasApprovedApplicationInOpenTerm()`; the profile lock does
  not apply to it. Audited as `edit_profile` with details
  `basic_salary,total_salary` (names only). The form lives on the instructor
  application page inside the stage-2 section, shown while the salary is
  missing or until the term closes, pre-filled masked like the profile
  (values never echoed back after an error).
- Admin profile edit keeps full access (unchanged).
- Rule 4b (M4) is unaffected: no checklist item maps to the salary fields.

## 8. Multi-file upload

- The upload control gets `multiple`; `UploadDocumentRequest` validates
  `files` as an array of 1..10 files with today's per-file rules (PDF, JPG,
  PNG, DOCX, 10 MB, ZIP refused). Old single-file posts (`file`) keep working
  by normalising into `files`.
- `DocumentStore::store()` takes a list, allocates one version, writes the
  parts in one transaction, returns the head.
- Review routes keep receiving the head document; `reviewDocument()` updates
  all parts of the version together. Download and view routes accept any
  part (policy unchanged: owner or admin).
- On-file sources carry their parts; the admin page links every part.
- Pages: each row shows "الملفات (n)" with a view/download link per part,
  in part order; the history table shows one line per version with the
  part count.

## 9. Screens

- **Instructor application page**: two headed sections,
  "المرحلة الأولى: مستندات اللجنة" and "المرحلة الثانية: بعد الاعتماد".
  Before approval the stage-2 table is listed without upload controls and
  with `app.documents.stage2_hint`. After approval the stage-1 table is
  read-only (badges and files only) and the stage-2 table offers uploads,
  the salary form (section 7) and, when stage 2 is complete, the line
  `app.applications.stage2_complete`. Exemptable rows show a "طلب إعفاء"
  button opening a one-field form (reason, max 500); a pending request shows
  the badge "طلب إعفاء قيد النظر"; an accepted one "معفى"; a rejected one the
  decision note and the upload control again.
- **Admin application page**: the same two sections; stage-2 rows carry a
  "المرحلة الثانية" badge. An exemption row shows the reason and the
  accept/reject form (note required on reject). The decision block's
  message distinguishes "undecided exemptions" from "documents not
  accepted". After approval the decision block shows the stage-2 state:
  complete, or the missing list from `stageTwoMissing()`.
- **Dashboard**: a fourth group "معتمد، بانتظار المستندات" (approved
  applications of the current term with stage 2 incomplete), each with its
  missing items. The attestation "missing" alert counts listed applications
  only, as today, so it does not count these.
- **Attestations page**: the listing uses `listed()` filtered by stage 2
  complete; a second list "بانتظار استكمال المستندات" names the approved
  applications that are assigned but not yet listed, with their missing
  items. "Generate missing" skips them.
- **Printed Check List**: iterates `official = true` items only; exempted
  transcripts are not on it anyway. Nothing else changes.
- **Approval email**: adds the stage-2 list (labels, with the employer
  letter line) and the salary reminder.

## 10. Security

- Exemption and salary routes are instructor-owned (policy `update` on the
  application / own instructor) and refused outside the stated statuses;
  admins decide exemptions through `ApplicationPolicy::review`.
- Audit: `request_exemption`, `exemption_accepted`, `exemption_rejected`
  (details = item code), `edit_profile` with field names for the salary
  form. Reasons and notes are stored in `checklist_exemptions`, not in the
  audit log. Salary values never appear in logs, flashes or details.
- Multi-file upload keeps the per-file validation and the private disk; the
  total request size stays under the server's `post_max_size` (10 files ×
  10 MB; `DEPLOY.md` notes the PHP-FPM values to check: `upload_max_filesize
  = 10M`, `post_max_size = 110M`, `max_file_uploads ≥ 10`).

## 11. Parked items folded in

1. `submit()` takes the term row lock so a submission cannot race the term
   close (M4 note).
2. `degree_country` validated with `Rule::in(array_keys(__('app.countries')))`.
3. `set_tcw()` substitution-count assertion in `AttestationDocumentTest`.
4. The admin header test asserts the admin's own name.

## 12. Testing

- Seeder: 14 items, stages, flags, `official` false only for transcripts.
- Derivation: each new state (`exemption_requested`, `exempted`, rejected
  exemption → `missing` with the note); stage ordering of rows; stage-2
  rows ignored by both stage-1 gates; a pending exemption blocks "complete"
  with the specific message; an accepted one satisfies it.
- Submission with stage-1 only; committee decision then stage-2 upload by
  the applicant (allowed) and a stage-1 upload after approval (403); admin
  accepts and rejects a stage-2 document after approval without a status
  change; rejection notice in `approved` status lists it once.
- `stageTwoComplete()`: false until every stage-2 row is satisfied and both
  salary values exist; on-file stage-2 copies count; renews-each-term items
  do not.
- Salary: profile saves without salary before approval; `PUT /my/salary`
  403 before approval and for strangers, 200 after approval even with the
  profile locked, audited with names only, value never echoed.
- Exemptions: request on a non-exemptable item 403; on a stage-2 item 403;
  re-request after rejection; admin decide on non-pending 422; instructor
  cannot decide (403).
- Multi-file: 3 files → one version, parts 1..3, head reviewed → all parts
  updated; 11 files refused; a ZIP among them refused; legacy single `file`
  post still works; on-file source lists its parts.
- Attestations: `listed()` excludes approved-but-incomplete applications;
  dashboard group and attestation page list them with missing items;
  "generate missing" skips them.
- Printed Check List unchanged (12 lines, no transcripts).
- Lang parity and no tashkeel.

## 13. Delivery

One plan, subagent-driven, in this order: schema + seeder + model flags;
derivation + gates; exemptions (workflow, routes, notice); approval-phase
uploads and review; salary form; multi-file upload; attestation gating,
dashboard and pages; mail; parked items; docs. Deploy = `deploy.sh` (two
migrations, seeder) plus the PHP-FPM upload limits check in `DEPLOY.md`.

## 14. Out of scope

- Generating the employer request letter.
- Changing the official Check List content.
- Reopening or amending approved applications.
- Exemptions for items other than the transcripts.
- A stored "onboarding complete" timestamp or status.

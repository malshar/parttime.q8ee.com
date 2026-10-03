# parttime.q8ee.com — Milestone 6 Design: Year Approval, Continuation, Renewal

Date: 2026-10-03
Owner: Dr. Mishal E. AlSharidah
Status: approved in conversation; awaiting review of this written spec
Extends: `2026-09-28-parttime-system-design.md` (system spec) and the
milestone 2–5b specs. Where this document and an earlier spec differ, this
document wins for milestone 6.

## 1. Purpose

The committee (لجنة التوظيف والانتداب) approves a part-time instructor for a
whole **academic year** (first, second and summer terms). Within that year
the later terms need only the per-term papers, processed by the department,
with no committee decision. The following year the instructor does not
apply again: the department submits the names to the committee for
**renewal**; the committee may ask to see the academic documents, which the
system must be able to hand over. At the end of each term the department
processes the closing papers of the ending term and the per-term papers of
the next one.

Today every term is a fresh application with a fresh committee decision.
Milestone 6 makes the approval a yearly object and adds continuation,
renewal, the academic bundle and a term-end page.

## 2. Decisions (locked 2026-10-03)

1. **Approach A, explicit year approval.** A `committee_approvals` row per
   instructor per academic year (initial or renewal). Applications stay one
   per instructor per term and link to the approval.
2. **Continuation** (same year, later term): per-term papers only
   (employer approval, salary certificate, undertaking, social insurance for
   private sector), no committee decision; the department's "file complete"
   approves it. Academic documents (degree, transcripts, equivalency) are
   never asked for again; a civil ID that expired is.
3. **Summer counts**: an approval covers first, second and summer of the
   same academic year.
4. **Renewal is a batch**: one committee meeting, a list of names, renewed
   or not renewed per name, recorded once by the admin. The system produces
   the list for the committee and creates the new year's first-term
   continuation applications for renewed instructors.
5. **Academic bundle**: one downloadable ZIP per instructor with the
   accepted academic documents and a summary sheet, audit-logged.
6. **Term-end page** per term: attestation months and next-term
   continuation state per approved instructor. Term close is unchanged.
7. Approval rows are not edited. A wrong renewal row may be deleted by the
   admin only while its continuation application is still a draft.

## 3. Data model

### 3.1 `committee_approvals` (new)

- `instructor_id` FK cascade; `academic_year` string 9 (same format as
  `terms.academic_year`); unique `(instructor_id, academic_year)`.
- `kind` string 12: `initial | renewal`.
- `outcome` string 12: `approved | not_renewed`.
- `committee_met_on` date, `committee_reference` string 60, `note` string 500
  nullable, `decided_by` users FK null-on-delete, timestamps.
- Model `CommitteeApproval` with `isApproved()`; `Instructor::approvals()`;
  `Instructor::approvalFor(string $academicYear): ?CommitteeApproval`.

### 3.2 `applications` (two new columns)

- `kind` string 12, default `initial`: `initial | continuation`. Set once in
  `start()` (section 4.2) and never changed.
- `approval_id` nullable FK to `committee_approvals`, null-on-delete. Set
  when the application is approved (initial: the row created by the
  decision; continuation: the year's existing row) or when a renewal
  creates the draft.

Existing committee columns on `applications` (`committee_outcome`,
`committee_met_on`, `committee_reference`, `committee_note`) stay as the
record of the initial decision; the approval row copies them.

### 3.3 Seeded items

No change. "Per-term papers" = applicant items with `renews_each_term = true`
(today: `social_insurance`, `salary_cert`, `employer_approval`,
`undertaking`). The stage flags keep their 5b meaning for initial
applications.

## 4. Application kinds

### 4.1 Validity

`Instructor::hasApprovalFor(string $academicYear): bool` = an approval row
for that year with `outcome = approved`. A `rejected` initial application
creates no row; the instructor may apply again next term as initial. A
`not_renewed` row does not block a fresh initial application; approving it
replaces the row (the one exception to "rows are never updated").

### 4.2 Start

`ApplicationWorkflow::start(Instructor, Term)`: if the instructor has an
approval for `$term->academic_year` and no application in that term, the
new application is `kind = continuation` with `approval_id` set; otherwise
`initial` (unchanged behaviour). The instructor home shows "تقديم طلب
استمرار للفصل الحالي" instead of "تقديم طلب" when the next application would
be a continuation.

### 4.3 Continuation checklist

`checklist()` for a continuation application:

- Stage-1 rows are listed but **informational**: every stage-1 item resolves
  through the normal rules; accepted copies resolve `on_file` and accepted
  exemptions resolve `exempted` across applications. The only stage-1 row
  that can become `missing` is `civil_id` when the card expired (M4 rule 4),
  and it is then required.
- Required rows = stage-2 items with `renews_each_term = true` + any stage-1
  row that is not satisfied. Stage-2 items that do not renew (`iban`) resolve
  `on_file` as today and are satisfied.
- Exemption requests are not offered on continuation applications (stage-1
  academic rows are already satisfied).

Gates:

- Submit: every required row uploaded (not `missing`, not `rejected`).
- File complete (`markComplete`): every required row satisfied. On a
  continuation, `markComplete()` goes straight to `approved` with
  `decided_at = now()`, `approval_id` = the year's row, audit
  `approve_continuation`, mail `ContinuationApproved` (department wording,
  no committee reference). `committeeDecision()` refuses continuation
  applications (`app.review.committee_not_needed`).
- Stage-2 readiness, attestations, assignments, fresh copies, notices and
  term close: unchanged. Since the per-term papers were the file, a
  continuation is stage-2 complete on approval provided the salary fields
  exist (they carry over on the profile).

### 4.4 Initial applications

Unchanged from 5b, with one addition: `committeeDecision('approved')` creates
the `committee_approvals` row (`kind = initial`, `outcome = approved`, copied
meeting date, reference and note) in the same transaction and sets
`approval_id`. A second initial application of the same instructor in the
same year cannot exist: `start()` makes it a continuation.

## 5. Renewal batch

### 5.1 Screen

`GET /admin/renewals?year=YYYY-YYYY` (admin only). The target year defaults
to the newest `first` term's academic year when that year still has renewal
candidates, otherwise to the year after the current term's year. The page lists instructors with
an `approved` row for the previous academic year and no row for the target
year: name, employer, highest degree, last term taught (link), a checkbox,
an outcome select (renewed / not renewed) and an optional note. One form
header: meeting date and reference (required). Buttons: "تسجيل قرار اللجنة"
and "قائمة الأسماء للجنة" (section 5.3).

### 5.2 Save

`ApplicationWorkflow::recordRenewals(string $year, array $rows, string $metOn, string $reference, User $admin): array`
(`rows` = instructor id → outcome + note), in one transaction:

- Preconditions: at least one selected row; every selected instructor has an
  approved row for the previous year and none for the target year; the
  target year's **first term exists** (`terms` row with `type = first`),
  otherwise refuse with `app.renewals.no_first_term`.
- For each selected instructor: create the approval row (`kind = renewal`,
  the given outcome, note, meeting date, reference, decided by); if
  `renewed`: create a draft application in the target year's first term
  (`kind = continuation`, `approval_id` set) unless one exists; audit
  `renewal_approved` / `renewal_refused` on the instructor (details = the
  academic year).
- After commit: mail `RenewalApproved` (renewed: year, link to the
  application, the per-term papers to bring) or `RenewalRefused` (not
  renewed: year, note if any) to each instructor.
- Returns counts (renewed, refused) for the flash.

Deleting a renewal row: `DELETE /admin/renewals/{approval}` allowed only
while `kind = renewal` and the linked application (if any) is still `draft`;
deletes the draft too; audited `delete_renewal`.

### 5.3 Names list for the committee

`GET /admin/renewals/list?year=YYYY-YYYY` streams a Word document
(PhpWord, same style as the Check List): title "قائمة المنتدبين المرشحين
لتجديد الاعتماد للعام الدراسي YYYY-YYYY", a table of the candidates listed on
the screen (serial, full name, civil ID, employer, highest degree and title,
terms taught in the previous year), the department and date. Audited
`export_renewal_list` with details = the year (the document carries civil
IDs; the audit row does not).

## 6. Academic bundle

`GET /admin/instructors/{instructor}/academic-bundle` (admin only) streams a
ZIP `academic-<instructor id>-<date>.zip` containing:

- `00-summary.docx`: name, civil ID and expiry, nationality, highest degree,
  degree title, country, date obtained, equivalency status (accepted on
  <date> / not applicable), transcripts status, employer and job title, the
  approvals held (year, kind, date, reference), generated date.
- The latest accepted copy (all parts, original file names prefixed with the
  item code and part number) of `civil_id`, `degree`, `transcript_bachelor`,
  `transcript_master`, `equivalency`, taken across all the instructor's
  applications (highest `reviewed_at`, then id). An accepted exemption
  appears in the summary instead of a file.

Built in `storage/app/private/generated/tmp`, deleted after send (same
pattern as the Check List). Audited `export_academic_bundle` on the
instructor. No salary or IBAN in the bundle.

## 7. Term-end page

`GET /admin/terms/{term}/closing` (admin only, open or closed term). One row
per approved application of the term: instructor (link), weekly hours,
attestation months as a sequence of badges (exported / generated / missing,
per `Term::months()`), the next term's continuation state (the term with the
next `teaching_starts_on`, if it exists: none / draft / submitted or under
review / incomplete / approved, with a link) and the per-term papers still
missing on that continuation. A header shows the counts (approved
instructors, months not exported, continuations not started) and the
existing close-term button when the term is open. The terms index links to
it.

## 8. Screens and copy

- Instructor home: continuation button wording; the application page title
  "طلب استمرار — <term>" for continuations; the stage-1 section is headed
  "المستندات الأكاديمية (على الملف)" and shows badges only; the stage-2
  section is headed "أوراق الفصل"; no exemption controls.
- Admin application page: a "استمرار" badge next to the status; the decision
  block offers "اعتماد الاستمرار" (the file-complete action) instead of the
  committee form; after approval it shows the year approval (kind, date,
  reference).
- Admin instructor record: the approvals held and the bundle button. (The
  admin reaches the record from the application page's profile card.)
- Admin dashboard: no new group; the term-end page is linked from the terms
  index.
- Mails: `ContinuationApproved`, `RenewalApproved`, `RenewalRefused`.
- All copy formal undiacritized Arabic with `en` twins.

## 9. Security

- New admin routes behind `role:admin`; instructor routes unchanged.
- Audit: `approve_continuation` (application), `renewal_approved` /
  `renewal_refused` / `delete_renewal` (instructor, details = year),
  `export_renewal_list` (details = year), `export_academic_bundle`
  (instructor). Never values from the profile in details.
- The renewal list and the bundle expose civil IDs by design; both are
  audited and admin-only. Salary and IBAN never appear in either.
- `recordRenewals()` runs in one transaction; the unique key makes a double
  submit fail cleanly with the refusal message.

## 10. Testing

- Decision creates the approval row with copied fields; `hasApprovalFor()`
  true for the year, false for other years and for rejected outcomes.
- `start()`: continuation when approved this year (first → second, second →
  summer), initial when not, initial next year without renewal; a draft
  created by a renewal is reused by `start()`.
- Continuation checklist: required = renewing items (+ `civil_id` when
  expired); `iban` on file; no exemption route (403); submit and complete
  gates on those rows only; `markComplete()` approves directly with audit and
  mail; `committeeDecision()` refused; stage-2 complete right after approval
  when salary exists; attestations listed once assigned.
- Renewal: candidate list correct (previous-year approved, no target row,
  excludes rejected/withdrawn-only instructors); save creates rows, drafts
  and mails; refuses when no first term, when a row exists, when nothing is
  selected; not renewed → no draft, refusal mail; delete allowed only on a
  draft; audits with the year only. Names list document contains the
  candidates and is audited.
- Bundle: ZIP contains the summary and the latest accepted parts, nothing
  from salary or IBAN; accepted exemption shows in the summary; audited;
  temp files removed.
- Term-end page: badges per month, continuation states, counts; works on a
  closed term.
- Lang parity, no tashkeel.

## 11. Delivery

One plan, subagent-driven: schema + models + decision hook; start() kinds +
continuation checklist/gates + pages; renewal batch + list document + mails;
academic bundle; term-end page; docs. Deploy = `deploy.sh` (two migrations).

## 12. Out of scope

- Generating the assignment letter (كشف التكليف) or any committee minutes.
- Approvals spanning more than one academic year; editing approval rows.
- Auto-creating continuation applications when a later term opens (the
  instructor starts it; the renewal batch creates only the first-term one).
- Reopening a closed term; changes to term close rules.

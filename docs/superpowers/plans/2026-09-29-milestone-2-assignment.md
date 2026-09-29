# Milestone 2 — Committee Workflow, Sections Import, Assignments — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the committee approval path explicit (new `complete` status, recorded committee decision, carry-overs), then import the term's jadawil timetable export and assign sections to approved instructors with computed weekly hours.

**Architecture:** Same Laravel 12 monolith. Group 1 extends `ApplicationWorkflow` and the admin review screens. Group 2 adds a pure `JadawilParser` (file → value objects), a transactional `SectionImporter` (value objects → DB with a computed diff), an `AssignmentService` (rules + hours recompute), and admin screens. Sections/meetings/assignments are new tables; nothing is typed by hand.

**Tech Stack:** PHP ≥ 8.2, Laravel 12, MySQL/SQLite, PHPUnit, Bootstrap 5 RTL (CDN), `phpoffice/phpspreadsheet` for XLSX reading (the spec names `maatwebsite/excel`, which is a wrapper around PhpSpreadsheet; the parser needs only cell reading, so the plan uses PhpSpreadsheet directly — same capability, one dependency fewer).

**Spec:** `docs/superpowers/specs/2026-09-29-milestone-2-assignment-design.md` (binding), which extends `docs/superpowers/specs/2026-09-28-parttime-system-design.md`.

## Global Constraints

- Laravel 12; SQLite `:memory:` in tests; PHPUnit; TDD per task.
- **Arabic-first, RTL.** Every new UI string in `lang/ar/app.php` with an English twin in `lang/en/app.php`; formal undiacritized Arabic; no hard-coded UI strings in views/controllers.
- Roles `admin`/`instructor`; all new admin routes under the existing `admin` group (`auth`, `role:admin`) and additionally checked by `$this->authorize(...)` where a model is involved. Instructors never reach section/assignment/import routes (403).
- Every state-changing admin action writes `AuditLog::record($userId, $action, $subject)`; audit rows never contain sensitive values.
- Status pipeline after this milestone: `draft → submitted → under_review ⇄ incomplete → complete → approved | rejected`; `withdrawn` from any non-final state; `withdrawn → draft` by admin reopen. `complete` is neither editable nor final.
- Weekly hours are scheduled contact time: `weekly_minutes` (sum of assigned meetings) is the only stored value; hours are derived for display (`round(minutes / 60, 1)`); never typed.
- Import accepts jadawil CSV/XLSX only; required headers (exact Arabic text): رقم المقرر, اسم المقرر, الشعبة, النشاط, من, الى, الأيام. Activity mapping: محاضرة → theory; مختبر, ورشة, عملي → practical; ميداني → field; other → theory + warning.
- Uploaded import files are parsed in memory and never stored; the parsed payload lives in the session only between preview and confirm.
- Closed terms are read-only for every action in this milestone.
- Commit after every task; messages end with the `Co-Authored-By:` trailer the environment specifies.
- Never read, copy or reference `../part-time/`.

## Review Focus

1. **A CSV where the same section appears with two lecture rows on the same day and time** (jadawil duplicates). Expected: the parser reports a warning and keeps one meeting, never a unique-constraint crash on import. Pinned in Task 7.
2. **Re-import after an instructor was assigned, where the section's meetings changed.** Expected: meetings are replaced, the assignment is kept, and the instructor's weekly hours are recomputed. Pinned in Task 9.
3. **Committee decision submitted twice from two tabs.** Expected: the second submit is refused with "already final" and no second email. Pinned in Task 2.
4. **The applicant's name in the export has different hamza/ta-marbuta spelling than the profile.** Expected: still suggested (never auto-assigned). Pinned in Task 7 (normaliser) and Task 12.
5. **Day list containing an unexpected day name (e.g. الجمعة) or an empty day cell.** Expected: an error row with the row number in the preview, confirm blocked. Pinned in Task 7.

## File Structure

```
app/
  Models/Section.php, SectionMeeting.php, Assignment.php           (new)
  Models/Application.php, Document.php                             (modified)
  Services/ApplicationWorkflow.php                                 (modified: markComplete, committeeDecision, notifyRejections, reopen; approve/reject removed)
  Services/Sections/JadawilParser.php                              (file contents → ParsedTimetable)
  Services/Sections/ParsedTimetable.php, ParsedSection.php, ParsedMeeting.php  (value objects)
  Services/Sections/SectionImporter.php                            (plan() diff + apply() transaction)
  Services/Sections/AssignmentService.php                          (assign/unassign/recompute/suggestions)
  Support/ArabicNameNormaliser.php
  Http/Controllers/Admin/ApplicationController.php                 (modified: complete, committee, reopen, notifyRejections; approve/reject removed)
  Http/Controllers/Admin/ProfileController.php                     (admin edit of an instructor profile)
  Http/Controllers/Admin/SectionImportController.php               (form/preview/confirm)
  Http/Controllers/Admin/SectionController.php                     (index)
  Http/Controllers/Admin/AssignmentController.php                  (index/store/destroy)
  Http/Controllers/Admin/DashboardController.php                   (modified: three groups)
  Http/Requests/CommitteeDecisionRequest.php, AdminProfileRequest.php, ImportSectionsRequest.php
  Mail/ApplicationReopened.php
database/migrations/2026_09_29_*                                    (5 migrations)
database/factories/SectionFactory.php, SectionMeetingFactory.php, AssignmentFactory.php
resources/views/admin/applications/show.blade.php                  (modified: decision card, rejections button, reopen, profile edit link, sections card)
resources/views/admin/applications/profile.blade.php               (new)
resources/views/admin/dashboard.blade.php                          (modified)
resources/views/admin/sections/import.blade.php, preview.blade.php, index.blade.php
resources/views/admin/assignments/index.blade.php
resources/views/instructor/home.blade.php                          (modified: sections card)
resources/views/emails/application-reopened.blade.php
tests/Fixtures/jadawil-sample.csv
tests/Unit/JadawilParserTest.php, ArabicNameNormaliserTest.php
tests/Feature/Admin/{CompleteStatusTest,CommitteeDecisionTest,RejectionNotifyTest,ReopenTest,AdminProfileEditTest,SectionImportTest,AssignmentTest,AssignmentScreensTest}.php
tests/Feature/Admin/ReviewTest.php                                  (modified where approve/reject were used)
```

---

### Task 1: Status `complete`, "الملف مكتمل" action, attention list groups

**Files:**
- Create: `database/migrations/2026_09_29_100000_add_complete_at_to_applications_table.php`, `tests/Feature/Admin/CompleteStatusTest.php`
- Modify: `app/Models/Application.php`, `app/Services/ApplicationWorkflow.php`, `app/Http/Controllers/Admin/ApplicationController.php`, `app/Http/Controllers/Admin/DashboardController.php`, `routes/web.php`, `resources/views/admin/applications/show.blade.php`, `resources/views/admin/dashboard.blade.php`, `lang/ar/app.php`, `lang/en/app.php`, `database/factories/ApplicationFactory.php`

**Interfaces:**
- Consumes: `ApplicationWorkflow::allRequiredAccepted()`, `reviewDocument()`, `Application::isFinal()`, `Term::isOpen()`.
- Produces: `Application::STATUS_COMPLETE = 'complete'`, `Application::REVIEWABLE_STATUSES = [under_review, incomplete, complete]`, `complete_at` datetime; `ApplicationWorkflow::markComplete(Application, User): void` (throws `\DomainException` unless status is `under_review|incomplete`, term open, all required accepted); rejecting a document from `complete` returns to `incomplete` and nulls `complete_at`; route `admin.applications.complete` (POST); `DashboardController` passes `$department`, `$committee`, `$alerts` collections; factory state `Application::factory()->complete()`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Admin/CompleteStatusTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CompleteStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(ChecklistItemSeeder::class);
        $this->admin = User::factory()->admin()->create();
        $instructor = Instructor::factory()->for(User::factory()->instructor())->create();
        $this->application = Application::factory()->for(Term::factory()->open())->for($instructor)
            ->create(['status' => Application::STATUS_UNDER_REVIEW]);
        foreach (['civil_id', 'degree', 'salary_cert', 'iban', 'employer_approval', 'undertaking'] as $code) {
            Document::factory()->for($this->application)->forItem($code)->accepted()->create();
        }
    }

    public function test_mark_complete_when_all_required_accepted(): void
    {
        $this->actingAs($this->admin)->post(route('admin.applications.complete', $this->application))->assertRedirect();

        $fresh = $this->application->fresh();
        $this->assertSame(Application::STATUS_COMPLETE, $fresh->status);
        $this->assertNotNull($fresh->complete_at);
        $this->assertDatabaseHas('audit_log', ['action' => 'mark_complete', 'subject_id' => $this->application->id]);
    }

    public function test_mark_complete_refused_when_a_document_is_pending(): void
    {
        Document::factory()->for($this->application)->forItem('iban')->create(['version' => 2]); // pending v2

        $this->actingAs($this->admin)->post(route('admin.applications.complete', $this->application))
            ->assertSessionHasErrors('complete');
        $this->assertSame(Application::STATUS_UNDER_REVIEW, $this->application->fresh()->status);
    }

    public function test_mark_complete_refused_on_closed_term_and_on_wrong_status(): void
    {
        $this->application->term->update(['status' => 'closed']);
        $this->actingAs($this->admin)->post(route('admin.applications.complete', $this->application))->assertSessionHasErrors('complete');

        $this->application->term->update(['status' => 'open']);
        $this->application->update(['status' => Application::STATUS_DRAFT]);
        $this->actingAs($this->admin)->post(route('admin.applications.complete', $this->application))->assertSessionHasErrors('complete');
    }

    public function test_rejecting_a_document_from_complete_returns_to_incomplete(): void
    {
        $this->application->update(['status' => Application::STATUS_COMPLETE, 'complete_at' => now()]);
        $doc = $this->application->latestDocuments()->get('iban');

        $this->actingAs($this->admin)->post(route('admin.documents.review', $doc), ['status' => 'rejected', 'reason' => 'x'])->assertRedirect();

        $fresh = $this->application->fresh();
        $this->assertSame(Application::STATUS_INCOMPLETE, $fresh->status);
        $this->assertNull($fresh->complete_at);
    }

    public function test_dashboard_shows_three_groups(): void
    {
        $this->application->update(['status' => Application::STATUS_COMPLETE, 'complete_at' => now()->subDays(3)]);
        Application::factory()->submitted()->for($this->application->term)->create();

        $r = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();
        $r->assertSee(__('app.review.group_department'));
        $r->assertSee(__('app.review.group_committee'));
        $r->assertSee(__('app.review.group_alerts'));
        $r->assertSee(__('app.review.waiting_days', ['days' => 3]));
    }

    public function test_complete_is_not_editable_by_instructor_and_upload_is_refused(): void
    {
        $this->application->update(['status' => Application::STATUS_COMPLETE, 'complete_at' => now()]);
        $this->assertFalse($this->application->fresh()->isEditable());
        $this->assertFalse($this->application->fresh()->isFinal());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter CompleteStatusTest`
Expected: FAIL (route `admin.applications.complete` not defined; `complete_at` column missing).

- [ ] **Step 3: Implement**

Migration `2026_09_29_100000_add_complete_at_to_applications_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->timestamp('complete_at')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('applications', fn (Blueprint $table) => $table->dropColumn('complete_at'));
    }
};
```

`app/Models/Application.php` — add after `STATUS_INCOMPLETE`:

```php
public const STATUS_COMPLETE = 'complete';

/** Statuses in which the admin may still act on documents. */
public const REVIEWABLE_STATUSES = [self::STATUS_UNDER_REVIEW, self::STATUS_INCOMPLETE, self::STATUS_COMPLETE];
```

add `'complete_at'` to `$fillable` and `'complete_at' => 'datetime'` to `casts()`. `EDITABLE_STATUSES` and `FINAL_STATUSES` unchanged.

`database/factories/ApplicationFactory.php` — add:

```php
public function complete(): static
{
    return $this->state(fn () => ['status' => Application::STATUS_COMPLETE, 'complete_at' => now()]);
}

public function approved(): static
{
    return $this->state(fn () => ['status' => Application::STATUS_APPROVED, 'decided_at' => now()]);
}
```

`app/Services/ApplicationWorkflow.php` — add:

```php
public function markComplete(Application $application, User $admin): void
{
    if (! in_array($application->status, [Application::STATUS_UNDER_REVIEW, Application::STATUS_INCOMPLETE], true)) {
        throw new \DomainException(__('app.review.complete_wrong_status'));
    }
    if (! $application->term->isOpen()) {
        throw new \DomainException(__('app.applications.term_closed'));
    }
    if (! $this->allRequiredAccepted($application)) {
        throw new \DomainException(__('app.review.complete_blocked'));
    }
    $application->update(['status' => Application::STATUS_COMPLETE, 'complete_at' => now()]);
    AuditLog::record($admin->id, 'mark_complete', $application);
}
```

and in `reviewDocument()`, replace the block after `$application = $document->application->fresh();` with:

```php
$application = $document->application->fresh();
if ($status === Document::STATUS_REJECTED && ! $application->isFinal()) {
    $application->update(['status' => Application::STATUS_INCOMPLETE, 'complete_at' => null]);
    $rejected = $this->checklist($application);
    $rejected = array_filter($rejected, fn ($row) => $row['state'] === 'rejected');
    $this->safeSend($application->instructor->user->email, new DocumentsRejected($application, array_values($rejected)));
}
```

(Task 3 removes the send here.)

`app/Http/Controllers/Admin/ApplicationController.php` — add:

```php
public function complete(Request $request, Application $application): RedirectResponse
{
    $this->authorize('review', $application);
    try {
        $this->workflow->markComplete($application, $request->user());
    } catch (\DomainException $e) {
        return back()->withErrors(['complete' => $e->getMessage()]);
    }

    return back()->with('status', __('app.review.completed'));
}
```

and in `show()` add to the view data: `'canComplete' => in_array($application->status, [Application::STATUS_UNDER_REVIEW, Application::STATUS_INCOMPLETE], true) && $application->term->isOpen() && $this->workflow->allRequiredAccepted($application),`.

`routes/web.php` — in the admin group after the `reveal` route:

```php
Route::post('applications/{application}/complete', [AdminApplicationController::class, 'complete'])->name('applications.complete');
```

`app/Http/Controllers/Admin/DashboardController.php` — replace `index()`:

```php
public function index(): View
{
    $term = Term::current();
    $base = Application::with(['instructor', 'term'])->when($term, fn ($q) => $q->where('term_id', $term->id));

    $department = (clone $base)->whereIn('status', [Application::STATUS_SUBMITTED, Application::STATUS_UNDER_REVIEW])->orderBy('submitted_at')->get();
    $committee = (clone $base)->where('status', Application::STATUS_COMPLETE)->orderBy('complete_at')->get();
    $alerts = collect(); // Task 12 fills: approved without assignments, flagged sections

    $counts = Application::whereHas('term', fn ($q) => $q->open())
        ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

    return view('admin.dashboard', compact('department', 'committee', 'alerts', 'counts', 'term'));
}
```

`resources/views/admin/dashboard.blade.php` — replace the single attention table with three blocks. Keep the counts badge row but iterate over `['submitted', 'under_review', 'incomplete', 'complete', 'approved', 'rejected', 'withdrawn']`. Then:

```blade
<h2 class="h6">{{ __('app.review.group_department') }}</h2>
@include('admin._attention_table', ['rows' => $department, 'dateField' => 'submitted_at'])

<h2 class="h6 mt-4">{{ __('app.review.group_committee') }}</h2>
@if ($committee->isEmpty())
    <div class="alert alert-info">{{ __('app.review.no_attention') }}</div>
@else
    <div class="table-responsive">
        <table class="table table-striped align-middle">
            <thead><tr>
                <th>{{ __('app.review.applicant') }}</th>
                <th>{{ __('app.review.term') }}</th>
                <th>{{ __('app.review.complete_at') }}</th>
                <th>{{ __('app.review.waiting') }}</th>
                <th>{{ __('app.common.actions') }}</th>
            </tr></thead>
            <tbody>
            @foreach ($committee as $application)
                <tr>
                    <td>{{ $application->instructor->full_name }}</td>
                    <td>{{ $application->term->label() }}</td>
                    <td>{{ format_date($application->complete_at) }}</td>
                    <td>{{ __('app.review.waiting_days', ['days' => (int) $application->complete_at->diffInDays(now())]) }}</td>
                    <td><a href="{{ route('admin.applications.show', $application) }}" class="btn btn-sm btn-eet">{{ __('app.review.open') }}</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif

<h2 class="h6 mt-4">{{ __('app.review.group_alerts') }}</h2>
@if ($alerts->isEmpty())
    <div class="alert alert-info">{{ __('app.review.no_alerts') }}</div>
@else
    <ul class="list-group">
        @foreach ($alerts as $alert)
            <li class="list-group-item d-flex justify-content-between">
                <span>{{ $alert['text'] }}</span>
                <a href="{{ $alert['url'] }}" class="btn btn-sm btn-outline-secondary">{{ __('app.review.open') }}</a>
            </li>
        @endforeach
    </ul>
@endif
```

Create `resources/views/admin/_attention_table.blade.php` holding the existing attention table markup, parameterised by `$rows` and `$dateField` (the status column label uses `__('app.terms.status')`, fixing the milestone 1 header-key nit).

`resources/views/admin/applications/show.blade.php` — in the decision card, inside the `@else` (term open, not final) branch, BEFORE the approve form, add:

```blade
@if ($application->status === \App\Models\Application::STATUS_COMPLETE)
    <div class="alert alert-info py-2">{{ __('app.review.awaiting_committee') }}</div>
@elseif ($canComplete)
    <form method="post" action="{{ route('admin.applications.complete', $application) }}" class="mb-3">
        @csrf
        <button type="submit" class="btn btn-eet btn-sm">{{ __('app.review.mark_complete') }}</button>
    </form>
@else
    <div class="alert alert-warning py-2">{{ __('app.review.complete_blocked') }}</div>
@endif
```

(Task 2 replaces the approve/reject forms in this card with the committee form.)

`lang/ar/app.php` — add to `review`:

```php
'group_department' => 'بانتظار مراجعة القسم',
'group_committee' => 'بانتظار اللجنة',
'group_alerts' => 'تنبيهات',
'no_alerts' => 'لا توجد تنبيهات.',
'complete_at' => 'تاريخ اكتمال الملف',
'waiting' => 'مدة الانتظار',
'waiting_days' => ':days يوم',
'mark_complete' => 'الملف مكتمل',
'completed' => 'تم تحديد الملف كمكتمل وبانتظار اللجنة.',
'complete_blocked' => 'لا يمكن اعتبار الملف مكتملا قبل قبول جميع المستندات المطلوبة.',
'complete_wrong_status' => 'لا يمكن تحديد الملف كمكتمل في حالته الحالية.',
'awaiting_committee' => 'الملف مكتمل وبانتظار قرار لجنة التوظيف والانتداب.',
```

add to `applications.statuses`: `'complete' => 'مكتمل بانتظار اللجنة'`. English twins in `lang/en/app.php` (`group_department` → 'Awaiting department review', `group_committee` → 'Awaiting committee', `group_alerts` → 'Alerts', `waiting_days` → ':days days', `mark_complete` → 'File complete', statuses.complete → 'Complete, awaiting committee', etc.).

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter CompleteStatusTest` then `php artisan test`
Expected: PASS; full suite green (existing `ReviewTest::test_dashboard_lists_submitted_applications` still passes because the department group lists submitted applications).

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: complete status, mark-complete action and three-group attention list"
```

---

### Task 2: Committee decision replaces direct approve/reject

**Files:**
- Create: `database/migrations/2026_09_29_100100_add_committee_fields_to_applications_table.php`, `app/Http/Requests/CommitteeDecisionRequest.php`, `tests/Feature/Admin/CommitteeDecisionTest.php`
- Modify: `app/Models/Application.php`, `app/Services/ApplicationWorkflow.php` (remove `approve()`/`reject()`, add `committeeDecision()`), `app/Http/Controllers/Admin/ApplicationController.php` (remove `approve()`/`reject()`, add `committee()`), `routes/web.php`, `resources/views/admin/applications/show.blade.php`, `lang/ar/app.php`, `lang/en/app.php`, `tests/Feature/Admin/ReviewTest.php`

**Interfaces:**
- Consumes: `Application::STATUS_COMPLETE`, `ApplicationApproved`, `ApplicationRejected` mailables, `safeSend()`.
- Produces: columns `committee_outcome` (`approved|rejected`), `committee_met_on` (date), `committee_reference` (string 60), `committee_note` (text); `ApplicationWorkflow::committeeDecision(Application, User $admin, string $outcome, string $metOn, string $reference, ?string $note): void` (throws `\DomainException` unless status `complete`, term open, not final; rejected requires note); route `admin.applications.committee` (POST); audit `committee_decision`. The routes/methods `admin.applications.approve` and `admin.applications.reject` no longer exist.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Admin/CommitteeDecisionTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Mail\ApplicationApproved;
use App\Mail\ApplicationRejected;
use App\Models\Application;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CommitteeDecisionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $instructorUser;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(ChecklistItemSeeder::class);
        $this->admin = User::factory()->admin()->create();
        $this->instructorUser = User::factory()->instructor()->create();
        $instructor = Instructor::factory()->for($this->instructorUser)->create();
        $this->application = Application::factory()->complete()->for(Term::factory()->open())->for($instructor)->create();
    }

    private function payload(array $o = []): array
    {
        return array_merge(['outcome' => 'approved', 'committee_met_on' => now()->toDateString(), 'committee_reference' => 'ل.ت 12/2026', 'committee_note' => ''], $o);
    }

    public function test_approved_outcome_sets_status_and_mails(): void
    {
        $this->actingAs($this->admin)->post(route('admin.applications.committee', $this->application), $this->payload())->assertRedirect();

        $f = $this->application->fresh();
        $this->assertSame(Application::STATUS_APPROVED, $f->status);
        $this->assertSame('approved', $f->committee_outcome);
        $this->assertSame('ل.ت 12/2026', $f->committee_reference);
        $this->assertNotNull($f->decided_at);
        $this->assertDatabaseHas('audit_log', ['action' => 'committee_decision', 'subject_id' => $f->id]);
        Mail::assertSent(ApplicationApproved::class, fn ($m) => $m->hasTo($this->instructorUser->email));
    }

    public function test_rejected_outcome_requires_note_and_mails(): void
    {
        $this->actingAs($this->admin)->post(route('admin.applications.committee', $this->application), $this->payload(['outcome' => 'rejected']))
            ->assertSessionHasErrors('committee_note');

        $this->actingAs($this->admin)->post(route('admin.applications.committee', $this->application), $this->payload(['outcome' => 'rejected', 'committee_note' => 'لا يستوفي الشروط']))
            ->assertRedirect();

        $f = $this->application->fresh();
        $this->assertSame(Application::STATUS_REJECTED, $f->status);
        $this->assertSame('لا يستوفي الشروط', $f->rejection_reason);
        Mail::assertSent(ApplicationRejected::class);
    }

    public function test_refused_unless_complete(): void
    {
        $this->application->update(['status' => Application::STATUS_UNDER_REVIEW]);
        $this->actingAs($this->admin)->post(route('admin.applications.committee', $this->application), $this->payload())->assertSessionHasErrors('committee');
        $this->assertSame(Application::STATUS_UNDER_REVIEW, $this->application->fresh()->status);
    }

    public function test_second_submission_is_refused_and_sends_no_second_mail(): void
    {
        $this->actingAs($this->admin)->post(route('admin.applications.committee', $this->application), $this->payload());
        $this->actingAs($this->admin)->post(route('admin.applications.committee', $this->application), $this->payload(['outcome' => 'rejected', 'committee_note' => 'x']))
            ->assertSessionHasErrors('committee');

        $this->assertSame(Application::STATUS_APPROVED, $this->application->fresh()->status);
        Mail::assertSent(ApplicationApproved::class, 1);
        Mail::assertNotSent(ApplicationRejected::class);
    }

    public function test_refused_on_closed_term_and_future_meeting_date(): void
    {
        $this->actingAs($this->admin)->post(route('admin.applications.committee', $this->application), $this->payload(['committee_met_on' => now()->addDay()->toDateString()]))
            ->assertSessionHasErrors('committee_met_on');

        $this->application->term->update(['status' => 'closed']);
        $this->actingAs($this->admin)->post(route('admin.applications.committee', $this->application), $this->payload())->assertSessionHasErrors('committee');
    }

    public function test_old_approve_and_reject_routes_are_gone(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('admin.applications.approve'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('admin.applications.reject'));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter CommitteeDecisionTest`
Expected: FAIL (route missing, columns missing, old routes still exist).

- [ ] **Step 3: Implement**

Migration `2026_09_29_100100_add_committee_fields_to_applications_table.php`:

```php
Schema::table('applications', function (Blueprint $table) {
    $table->string('committee_outcome', 10)->nullable()->after('decided_at');
    $table->date('committee_met_on')->nullable()->after('committee_outcome');
    $table->string('committee_reference', 60)->nullable()->after('committee_met_on');
    $table->text('committee_note')->nullable()->after('committee_reference');
});
```

(with the matching `down()` dropping the four columns).

`app/Models/Application.php` — add the four columns to `$fillable`; add `'committee_met_on' => 'date'` to casts.

`app/Http/Requests/CommitteeDecisionRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CommitteeDecisionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'outcome' => ['required', Rule::in(['approved', 'rejected'])],
            'committee_met_on' => ['required', 'date', 'before_or_equal:today'],
            'committee_reference' => ['required', 'string', 'max:60'],
            'committee_note' => ['nullable', 'string', 'max:1000', Rule::requiredIf(fn () => $this->outcome === 'rejected')],
        ];
    }
}
```

`app/Services/ApplicationWorkflow.php` — DELETE `approve()` and `reject()`; ADD:

```php
public function committeeDecision(Application $application, User $admin, string $outcome, string $metOn, string $reference, ?string $note): void
{
    if ($application->isFinal()) {
        throw new \DomainException(__('app.review.already_final'));
    }
    if ($application->status !== Application::STATUS_COMPLETE) {
        throw new \DomainException(__('app.review.committee_wrong_status'));
    }
    if (! $application->term->isOpen()) {
        throw new \DomainException(__('app.applications.term_closed'));
    }

    $approved = $outcome === 'approved';
    $application->update([
        'status' => $approved ? Application::STATUS_APPROVED : Application::STATUS_REJECTED,
        'decided_at' => now(),
        'committee_outcome' => $outcome,
        'committee_met_on' => $metOn,
        'committee_reference' => $reference,
        'committee_note' => $note,
        'rejection_reason' => $approved ? null : $note,
    ]);
    AuditLog::record($admin->id, 'committee_decision', $application);
    $this->safeSend(
        $application->instructor->user->email,
        $approved ? new ApplicationApproved($application) : new ApplicationRejected($application),
    );
}
```

`app/Http/Controllers/Admin/ApplicationController.php` — DELETE `approve()` and `reject()`; ADD:

```php
public function committee(CommitteeDecisionRequest $request, Application $application): RedirectResponse
{
    $this->authorize('review', $application);
    try {
        $this->workflow->committeeDecision($application, $request->user(), $request->outcome, $request->committee_met_on, $request->committee_reference, $request->committee_note ?: null);
    } catch (\DomainException $e) {
        return back()->withErrors(['committee' => $e->getMessage()]);
    }

    return back()->with('status', __('app.review.committee_saved'));
}
```

Remove `'canApprove' => …` from `show()`'s view data.

`routes/web.php` — REMOVE the `applications.approve` and `applications.reject` routes; ADD:

```php
Route::post('applications/{application}/committee', [AdminApplicationController::class, 'committee'])->name('applications.committee');
```

`resources/views/admin/applications/show.blade.php` — in the decision card, replace everything inside the `@else` (open term, not final) branch with:

```blade
@if ($application->status === \App\Models\Application::STATUS_COMPLETE)
    <div class="alert alert-info py-2">{{ __('app.review.awaiting_committee') }}</div>
    <form method="post" action="{{ route('admin.applications.committee', $application) }}" class="row g-2 align-items-end">
        @csrf
        <div class="col-md-3">
            <label class="form-label">{{ __('app.review.committee_outcome') }}</label>
            <select name="outcome" class="form-select form-select-sm" required>
                <option value="approved">{{ __('app.review.outcomes.approved') }}</option>
                <option value="rejected">{{ __('app.review.outcomes.rejected') }}</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">{{ __('app.review.committee_met_on') }}</label>
            <input type="date" name="committee_met_on" value="{{ old('committee_met_on') }}" class="form-control form-control-sm" required>
        </div>
        <div class="col-md-3">
            <label class="form-label">{{ __('app.review.committee_reference') }}</label>
            <input name="committee_reference" value="{{ old('committee_reference') }}" class="form-control form-control-sm" maxlength="60" required>
        </div>
        <div class="col-md-12">
            <label class="form-label">{{ __('app.review.committee_note') }}</label>
            <textarea name="committee_note" class="form-control form-control-sm">{{ old('committee_note') }}</textarea>
            <div class="form-text">{{ __('app.review.committee_note_hint') }}</div>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-eet btn-sm" onclick="return confirm(@js(__('app.review.committee_confirm')))">{{ __('app.review.committee_save') }}</button>
        </div>
    </form>
@elseif ($canComplete)
    <form method="post" action="{{ route('admin.applications.complete', $application) }}" class="mb-3">
        @csrf
        <button type="submit" class="btn btn-eet btn-sm">{{ __('app.review.mark_complete') }}</button>
    </form>
@else
    <div class="alert alert-warning py-2 mb-0">{{ __('app.review.complete_blocked') }}</div>
@endif
```

In the `@if ($application->isFinal())` branch, after the status badge, show the committee record when present:

```blade
@if ($application->committee_outcome)
    <div class="small text-muted mt-2">
        {{ __('app.review.committee_record', ['outcome' => __('app.review.outcomes.'.$application->committee_outcome), 'date' => format_date($application->committee_met_on), 'ref' => $application->committee_reference]) }}
        @if ($application->committee_note)<div>{{ $application->committee_note }}</div>@endif
    </div>
@endif
```

`lang/ar/app.php` — add to `review`:

```php
'committee_outcome' => 'قرار اللجنة',
'outcomes' => ['approved' => 'موافقة', 'rejected' => 'رفض'],
'committee_met_on' => 'تاريخ اجتماع اللجنة',
'committee_reference' => 'رقم/مرجع القرار',
'committee_note' => 'ملاحظات اللجنة',
'committee_note_hint' => 'إلزامية في حال الرفض، وتظهر للمتقدم كسبب الرفض.',
'committee_save' => 'تسجيل قرار اللجنة',
'committee_confirm' => 'هل تريد تسجيل قرار اللجنة؟ لا يمكن التراجع.',
'committee_saved' => 'تم تسجيل قرار اللجنة وإبلاغ المتقدم.',
'committee_wrong_status' => 'لا يمكن تسجيل قرار اللجنة قبل اكتمال الملف.',
'committee_record' => 'قرار اللجنة: :outcome بتاريخ :date (المرجع :ref)',
```

English twins. Remove now-unused keys `approve`, `approve_blocked`, `approved`, `reject`, `reject_confirm`, `rejected` from BOTH lang files only if nothing else references them (grep `app.review.approve` / `app.review.reject` across `resources/` and `app/`; the document accept/reject buttons use `app.documents.*`, not these).

`tests/Feature/Admin/ReviewTest.php` — update the tests that posted to `admin.applications.approve` / `admin.applications.reject`:
- `test_approve_blocked_until_latest_versions_all_accepted` → rename `test_complete_blocked_until_latest_versions_all_accepted`; post to `admin.applications.complete`, assert errors on `complete`, then after accepting v2 assert status `complete` (not approved).
- `test_approve_blocked_on_closed_term` → `test_complete_blocked_on_closed_term` (post to `complete`, errors on `complete`).
- `test_reject_application_with_reason_mails_instructor`, `test_approve_refused_on_final_application`, `test_reject_refused_on_final_application`, `test_reject_blocked_on_closed_term` → DELETE (covered by `CommitteeDecisionTest`).
- `test_decision_can_be_set_after_approval`: replace the approve POST with `$this->application->update(['status' => Application::STATUS_APPROVED, 'decided_at' => now()])`.
- Remove the now-unused `ApplicationApproved`/`ApplicationRejected` imports if no test in the file still uses them.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter "CommitteeDecisionTest|ReviewTest"` then `php artisan test`
Expected: PASS; full suite green.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: committee decision replaces direct approve/reject"
```

---

### Task 3: One consolidated rejection email ("إنهاء المراجعة وإبلاغ المتقدم")

**Files:**
- Create: `database/migrations/2026_09_29_100200_add_notified_at_to_documents_table.php`, `tests/Feature/Admin/RejectionNotifyTest.php`
- Modify: `app/Models/Document.php`, `app/Services/ApplicationWorkflow.php`, `app/Http/Controllers/Admin/ApplicationController.php`, `routes/web.php`, `resources/views/admin/applications/show.blade.php`, `lang/ar/app.php`, `lang/en/app.php`, `tests/Feature/Admin/ReviewTest.php`

**Interfaces:**
- Consumes: `DocumentsRejected` mailable (unchanged), `checklist()`.
- Produces: `documents.notified_at` (nullable timestamp, fillable, datetime cast); `ApplicationWorkflow::pendingRejectionNotices(Application): array` (checklist rows whose latest document is rejected and `notified_at` null); `ApplicationWorkflow::notifyRejections(Application, User $admin): int` (sends one `DocumentsRejected` with ALL currently rejected latest documents, stamps every rejected latest document's `notified_at`, audit `notify_rejections`, returns the count; throws `\DomainException` when nothing is pending or the term is closed); route `admin.applications.notify_rejections` (POST). `reviewDocument()` no longer sends mail.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Admin/RejectionNotifyTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Mail\DocumentsRejected;
use App\Models\Application;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RejectionNotifyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $instructorUser;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(ChecklistItemSeeder::class);
        $this->admin = User::factory()->admin()->create();
        $this->instructorUser = User::factory()->instructor()->create();
        $instructor = Instructor::factory()->for($this->instructorUser)->create();
        $this->application = Application::factory()->for(Term::factory()->open())->for($instructor)->create(['status' => Application::STATUS_UNDER_REVIEW]);
        foreach (['civil_id', 'degree', 'salary_cert', 'iban', 'employer_approval', 'undertaking'] as $code) {
            Document::factory()->for($this->application)->forItem($code)->create();
        }
    }

    private function reject(string $code, string $reason = 'غير واضح'): void
    {
        $doc = $this->application->latestDocuments()->get($code);
        $this->actingAs($this->admin)->post(route('admin.documents.review', $doc), ['status' => 'rejected', 'reason' => $reason]);
    }

    public function test_rejecting_documents_sends_nothing_until_notify(): void
    {
        $this->reject('iban');
        $this->reject('degree');

        Mail::assertNothingSent();
        $this->assertSame(Application::STATUS_INCOMPLETE, $this->application->fresh()->status);

        $this->actingAs($this->admin)->post(route('admin.applications.notify_rejections', $this->application))->assertRedirect();

        Mail::assertSent(DocumentsRejected::class, 1);
        Mail::assertSent(DocumentsRejected::class, fn ($m) => $m->hasTo($this->instructorUser->email) && count($m->rows) === 2);
        $this->assertSame(2, Document::where('status', 'rejected')->whereNotNull('notified_at')->count());
        $this->assertDatabaseHas('audit_log', ['action' => 'notify_rejections', 'subject_id' => $this->application->id]);
    }

    public function test_notify_refused_when_nothing_pending(): void
    {
        $this->actingAs($this->admin)->post(route('admin.applications.notify_rejections', $this->application))->assertSessionHasErrors('notify');
        Mail::assertNothingSent();
    }

    public function test_show_offers_button_only_when_pending(): void
    {
        $this->actingAs($this->admin)->get(route('admin.applications.show', $this->application))->assertDontSee(__('app.review.notify_rejections'));
        $this->reject('iban');
        $this->actingAs($this->admin)->get(route('admin.applications.show', $this->application))->assertSee(__('app.review.notify_rejections'));
        $this->actingAs($this->admin)->post(route('admin.applications.notify_rejections', $this->application));
        $this->actingAs($this->admin)->get(route('admin.applications.show', $this->application))->assertDontSee(__('app.review.notify_rejections'));
    }

    public function test_reupload_after_notice_makes_new_rejection_pending_again(): void
    {
        $this->reject('iban');
        $this->actingAs($this->admin)->post(route('admin.applications.notify_rejections', $this->application));
        Document::factory()->for($this->application)->forItem('iban')->create(['version' => 2]);
        $this->reject('iban', 'ما زال غير واضح');

        $this->actingAs($this->admin)->post(route('admin.applications.notify_rejections', $this->application))->assertRedirect();
        Mail::assertSent(DocumentsRejected::class, 2);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter RejectionNotifyTest`
Expected: FAIL (first test: mail IS sent on reject; route missing).

- [ ] **Step 3: Implement**

Migration `2026_09_29_100200_add_notified_at_to_documents_table.php`: `$table->timestamp('notified_at')->nullable()->after('reviewed_at');` with matching `down()`.

`app/Models/Document.php` — add `'notified_at'` to `$fillable` and `'notified_at' => 'datetime'` to casts.

`app/Services/ApplicationWorkflow.php`:
- In `reviewDocument()`, replace the trailing block so it only flips the status:

```php
$application = $document->application->fresh();
if ($status === Document::STATUS_REJECTED && ! $application->isFinal()) {
    $application->update(['status' => Application::STATUS_INCOMPLETE, 'complete_at' => null]);
}
```

- Add:

```php
/** @return array<int, array{item: ChecklistItem, document: ?Document, state: string}> */
public function pendingRejectionNotices(Application $application): array
{
    return array_values(array_filter(
        $this->checklist($application),
        fn ($row) => $row['state'] === 'rejected' && $row['document']?->notified_at === null,
    ));
}

public function notifyRejections(Application $application, User $admin): int
{
    if (! $application->term->isOpen()) {
        throw new \DomainException(__('app.applications.term_closed'));
    }
    if ($this->pendingRejectionNotices($application) === []) {
        throw new \DomainException(__('app.review.nothing_to_notify'));
    }
    $rejected = array_values(array_filter($this->checklist($application), fn ($row) => $row['state'] === 'rejected'));
    foreach ($rejected as $row) {
        $row['document']->update(['notified_at' => now()]);
    }
    AuditLog::record($admin->id, 'notify_rejections', $application);
    $this->safeSend($application->instructor->user->email, new DocumentsRejected($application, $rejected));

    return count($rejected);
}
```

- Remove the `DocumentsRejected` send from `reviewDocument()` (done above); keep the import since `notifyRejections()` uses it.

`app/Http/Controllers/Admin/ApplicationController.php` — add:

```php
public function notifyRejections(Request $request, Application $application): RedirectResponse
{
    $this->authorize('review', $application);
    try {
        $n = $this->workflow->notifyRejections($application, $request->user());
    } catch (\DomainException $e) {
        return back()->withErrors(['notify' => $e->getMessage()]);
    }

    return back()->with('status', __('app.review.notified', ['count' => $n]));
}
```

and add `'pendingNotices' => $this->workflow->pendingRejectionNotices($application),` to `show()`'s view data.

`routes/web.php` (admin group): `Route::post('applications/{application}/notify-rejections', [AdminApplicationController::class, 'notifyRejections'])->name('applications.notify_rejections');`

`resources/views/admin/applications/show.blade.php` — directly under the checklist `<h2>`, add:

```blade
@if ($pendingNotices !== [] && $termOpen && ! $application->isFinal())
    <form method="post" action="{{ route('admin.applications.notify_rejections', $application) }}" class="mb-2">
        @csrf
        <button type="submit" class="btn btn-warning btn-sm">{{ __('app.review.notify_rejections') }} ({{ count($pendingNotices) }})</button>
        <span class="form-text d-inline">{{ __('app.review.notify_hint') }}</span>
    </form>
@endif
```

`lang/ar/app.php` `review` group — add: `'notify_rejections' => 'إنهاء المراجعة وإبلاغ المتقدم'`, `'notify_hint' => 'يرسل رسالة واحدة تتضمن جميع المستندات المرفوضة.'`, `'notified' => 'تم إبلاغ المتقدم بـ :count مستند مرفوض.'`, `'nothing_to_notify' => 'لا توجد مستندات مرفوضة لم يبلغ بها المتقدم.'`. English twins.

`tests/Feature/Admin/ReviewTest.php` — `test_rejecting_a_document_marks_incomplete_and_mails_instructor`: rename to `test_rejecting_a_document_marks_incomplete_without_mailing` and replace `Mail::assertSent(DocumentsRejected::class, …)` with `Mail::assertNothingSent()`. Also `test_document_review_refused_on_final_application` and `test_superseded_document_version_cannot_be_reviewed` keep their `Mail::assertNotSent(DocumentsRejected::class)` (still true).

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter "RejectionNotifyTest|ReviewTest"` then `php artisan test`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: consolidated rejection notice sent once by the admin"
```

---

### Task 4: Reopen a withdrawn application

**Files:**
- Create: `app/Mail/ApplicationReopened.php`, `resources/views/emails/application-reopened.blade.php`, `tests/Feature/Admin/ReopenTest.php`
- Modify: `app/Services/ApplicationWorkflow.php`, `app/Http/Controllers/Admin/ApplicationController.php`, `routes/web.php`, `resources/views/admin/applications/show.blade.php`, `lang/ar/app.php`, `lang/en/app.php`

**Interfaces:**
- Produces: `ApplicationWorkflow::reopen(Application, User $admin): void` (throws `\DomainException` unless status `withdrawn` and term open; sets `draft`, `decided_at = null`, audit `reopen_application`, sends `ApplicationReopened`); route `admin.applications.reopen` (POST).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Admin/ReopenTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Mail\ApplicationReopened;
use App\Models\Application;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ReopenTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $instructorUser;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(ChecklistItemSeeder::class);
        $this->admin = User::factory()->admin()->create();
        $this->instructorUser = User::factory()->instructor()->create();
        $instructor = Instructor::factory()->for($this->instructorUser)->create();
        $this->application = Application::factory()->for(Term::factory()->open())->for($instructor)
            ->create(['status' => Application::STATUS_WITHDRAWN, 'decided_at' => now()]);
    }

    public function test_reopen_returns_to_draft_and_mails(): void
    {
        $this->actingAs($this->admin)->post(route('admin.applications.reopen', $this->application))->assertRedirect();

        $f = $this->application->fresh();
        $this->assertSame(Application::STATUS_DRAFT, $f->status);
        $this->assertNull($f->decided_at);
        $this->assertTrue($f->isEditable());
        $this->assertDatabaseHas('audit_log', ['action' => 'reopen_application', 'subject_id' => $f->id]);
        Mail::assertSent(ApplicationReopened::class, fn ($m) => $m->hasTo($this->instructorUser->email));
    }

    public function test_reopen_refused_unless_withdrawn_or_when_term_closed(): void
    {
        $this->application->update(['status' => Application::STATUS_REJECTED]);
        $this->actingAs($this->admin)->post(route('admin.applications.reopen', $this->application))->assertSessionHasErrors('reopen');

        $this->application->update(['status' => Application::STATUS_WITHDRAWN]);
        $this->application->term->update(['status' => 'closed']);
        $this->actingAs($this->admin)->post(route('admin.applications.reopen', $this->application))->assertSessionHasErrors('reopen');
        Mail::assertNothingSent();
    }

    public function test_show_offers_reopen_only_when_withdrawn_on_open_term(): void
    {
        $this->actingAs($this->admin)->get(route('admin.applications.show', $this->application))->assertSee(__('app.review.reopen'));
        $this->application->update(['status' => Application::STATUS_APPROVED]);
        $this->actingAs($this->admin)->get(route('admin.applications.show', $this->application))->assertDontSee(__('app.review.reopen'));
    }

    public function test_instructor_cannot_reopen(): void
    {
        $this->actingAs($this->instructorUser)->post(route('admin.applications.reopen', $this->application))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter ReopenTest`
Expected: FAIL (route missing).

- [ ] **Step 3: Implement**

`app/Mail/ApplicationReopened.php` — same shape as `ApplicationApproved`: subject `__('app.mail.reopened_subject', [], 'ar')`, view `emails.application-reopened` with `name`, `term`, `url` (instructor application show).

`resources/views/emails/application-reopened.blade.php`:

```blade
@extends('emails._shell')
@section('body')
    <p>{{ __('app.mail.reopened_body', ['name' => $name, 'term' => $term], 'ar') }}</p>
    <p><a href="{{ $url }}">{{ __('app.mail.open_application', [], 'ar') }}</a></p>
@endsection
```

`app/Services/ApplicationWorkflow.php` — add:

```php
public function reopen(Application $application, User $admin): void
{
    if ($application->status !== Application::STATUS_WITHDRAWN) {
        throw new \DomainException(__('app.review.reopen_wrong_status'));
    }
    if (! $application->term->isOpen()) {
        throw new \DomainException(__('app.applications.term_closed'));
    }
    $application->update(['status' => Application::STATUS_DRAFT, 'decided_at' => null]);
    AuditLog::record($admin->id, 'reopen_application', $application);
    $this->safeSend($application->instructor->user->email, new ApplicationReopened($application));
}
```

Controller method `reopen()` following the `complete()` pattern (errors under key `reopen`, success `app.review.reopened`). Route: `Route::post('applications/{application}/reopen', [AdminApplicationController::class, 'reopen'])->name('applications.reopen');`

View: in the decision card's `@if ($application->isFinal())` branch, after the badge/committee record:

```blade
@if ($application->status === \App\Models\Application::STATUS_WITHDRAWN && $termOpen)
    <form method="post" action="{{ route('admin.applications.reopen', $application) }}" class="mt-2" onsubmit="return confirm(@js(__('app.review.reopen_confirm')))">
        @csrf
        <button type="submit" class="btn btn-outline-primary btn-sm">{{ __('app.review.reopen') }}</button>
    </form>
@endif
```

`lang/ar/app.php`: `review.reopen` 'إعادة فتح الطلب', `review.reopen_confirm` 'سيعود الطلب إلى مسودة ويبلغ المتقدم. هل تريد المتابعة؟', `review.reopened` 'تمت إعادة فتح الطلب وإبلاغ المتقدم.', `review.reopen_wrong_status` 'يمكن إعادة فتح الطلبات المسحوبة فقط.'; `mail.reopened_subject` 'إعادة فتح طلب الانتداب', `mail.reopened_body` 'أعاد القسم فتح طلب انتدابك للفصل :term. يمكنك استكمال الطلب وتقديمه.'. English twins.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter ReopenTest` then `php artisan test`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: admin can reopen a withdrawn application"
```

---

### Task 5: Admin edits an instructor profile on request (audited)

**Files:**
- Create: `app/Http/Requests/AdminProfileRequest.php`, `app/Http/Controllers/Admin/ProfileController.php`, `resources/views/admin/applications/profile.blade.php`, `tests/Feature/Admin/AdminProfileEditTest.php`
- Modify: `app/Http/Requests/ProfileRequest.php` (make the owner id overridable), `routes/web.php`, `resources/views/admin/applications/show.blade.php`, `lang/ar/app.php`, `lang/en/app.php`

**Interfaces:**
- Consumes: `ProfileRequest` rules; `Instructor` fillable + encrypted casts; the instructor profile form markup in `resources/views/instructor/profile.blade.php` (copy the field markup; do not include it).
- Produces: `ProfileRequest::ownerUserId(): ?int` (default `$this->user()->id`) used by the civil-ID uniqueness check; `AdminProfileRequest extends ProfileRequest` overriding `ownerUserId()` to return the routed application's instructor's `user_id`; routes `admin.applications.profile.edit` (GET) and `admin.applications.profile.update` (PUT); audit action `admin_edit_profile` whose row is followed by a second `AuditLog` write with `action = 'admin_edit_profile:'.implode(',', $changedFields)` truncated to 60 chars (field names only, never values).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Admin/AdminProfileEditTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Instructor\ProfileTest;
use Tests\TestCase;

class AdminProfileEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChecklistItemSeeder::class);
        $this->admin = User::factory()->admin()->create();
        $instructor = Instructor::factory()->for(User::factory()->instructor())->create();
        $this->application = Application::factory()->approved()->for(Term::factory()->open())->for($instructor)->create();
    }

    public function test_admin_updates_profile_and_audit_lists_changed_fields(): void
    {
        $payload = ProfileTest::payload(['civil_id' => $this->application->instructor->civil_id, 'job_title' => 'مهندس أول', 'mobile' => '99887766']);

        $this->actingAs($this->admin)->put(route('admin.applications.profile.update', $this->application), $payload)
            ->assertRedirect(route('admin.applications.show', $this->application));

        $i = $this->application->instructor->fresh();
        $this->assertSame('مهندس أول', $i->job_title);
        $this->assertSame('99887766', $i->mobile);
        $this->assertDatabaseHas('audit_log', ['action' => 'admin_edit_profile', 'subject_id' => $i->id]);
        $detail = AuditLog::where('action', 'like', 'admin_edit_profile:%')->latest('id')->first();
        $this->assertNotNull($detail);
        $this->assertStringContainsString('job_title', $detail->action);
        $this->assertStringNotContainsString('99887766', $detail->action);
    }

    public function test_works_even_when_profile_is_locked_for_the_instructor(): void
    {
        $this->assertTrue($this->application->instructor->hasLockedApplication());
        $payload = ProfileTest::payload(['civil_id' => $this->application->instructor->civil_id, 'employer' => 'وزارة الدفاع']);
        $this->actingAs($this->admin)->put(route('admin.applications.profile.update', $this->application), $payload)->assertSessionHasNoErrors();
        $this->assertSame('وزارة الدفاع', $this->application->instructor->fresh()->employer);
    }

    public function test_civil_id_uniqueness_is_checked_against_the_edited_instructor(): void
    {
        $other = Instructor::factory()->for(User::factory()->instructor())->create();
        $payload = ProfileTest::payload(['civil_id' => $other->civil_id]);
        $this->actingAs($this->admin)->put(route('admin.applications.profile.update', $this->application), $payload)->assertSessionHasErrors('civil_id');

        $own = ProfileTest::payload(['civil_id' => $this->application->instructor->civil_id]);
        $this->actingAs($this->admin)->put(route('admin.applications.profile.update', $this->application), $own)->assertSessionHasNoErrors();
    }

    public function test_instructor_cannot_use_admin_profile_routes(): void
    {
        $this->actingAs($this->application->instructor->user)->get(route('admin.applications.profile.edit', $this->application))->assertForbidden();
    }

    public function test_sensitive_fields_are_not_flashed_on_validation_failure(): void
    {
        $payload = ProfileTest::payload(['civil_id' => $this->application->instructor->civil_id, 'mobile' => 'bad']);
        $r = $this->actingAs($this->admin)->put(route('admin.applications.profile.update', $this->application), $payload);
        $r->assertSessionHasErrors('mobile');
        $r->assertSessionMissing('_old_input.civil_id');
        $r->assertSessionMissing('_old_input.iban');
    }
}
```

(`ProfileTest::payload()` already exists as a public static helper from milestone 1.)

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter AdminProfileEditTest`
Expected: FAIL (routes missing).

- [ ] **Step 3: Implement**

`app/Http/Requests/ProfileRequest.php` — add and use:

```php
/** The user who owns the profile being edited; overridden by the admin request. */
protected function ownerUserId(): ?int
{
    return $this->user()?->id;
}
```

and in `withValidator()` replace `$existing->user_id !== $this->user()->id` with `$existing->user_id !== $this->ownerUserId()`.

`app/Http/Requests/AdminProfileRequest.php`:

```php
<?php

namespace App\Http\Requests;

class AdminProfileRequest extends ProfileRequest
{
    protected function ownerUserId(): ?int
    {
        return $this->route('application')?->instructor?->user_id;
    }
}
```

`app/Http/Controllers/Admin/ProfileController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminProfileRequest;
use App\Models\Application;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Application $application): View
    {
        $this->authorize('review', $application);

        return view('admin.applications.profile', ['application' => $application, 'instructor' => $application->instructor]);
    }

    public function update(AdminProfileRequest $request, Application $application): RedirectResponse
    {
        $this->authorize('review', $application);
        $instructor = $application->instructor;
        $data = $request->validated();
        if ($data['highest_degree'] !== 'bachelor') {
            $data['experience_years'] = null;
        }

        $instructor->fill($data);
        $changed = array_keys($instructor->getDirty());
        $instructor->save();

        AuditLog::record($request->user()->id, 'admin_edit_profile', $instructor);
        if ($changed !== []) {
            AuditLog::record($request->user()->id, mb_substr('admin_edit_profile:'.implode(',', $changed), 0, 60), $instructor);
        }

        return redirect()->route('admin.applications.show', $application)->with('status', __('app.common.saved'));
    }
}
```

(Encrypted casts compare decrypted values, so unchanged civil ID / IBAN do not appear in `getDirty()`; `civil_id_hash` is set by the model's saving hook.)

`routes/web.php` (admin group):

```php
Route::get('applications/{application}/profile', [AdminProfileController::class, 'edit'])->name('applications.profile.edit');
Route::put('applications/{application}/profile', [AdminProfileController::class, 'update'])->name('applications.profile.update');
```

with `use App\Http\Controllers\Admin\ProfileController as AdminProfileController;`.

`resources/views/admin/applications/profile.blade.php` — extends `admin.layout`; a PUT form to `admin.applications.profile.update` with the same fields and lang keys as `resources/views/instructor/profile.blade.php` (copy the field markup; sensitive inputs prefilled from the model, `autocomplete="off"`), an `alert-warning` `__('app.review.profile_edit_warning')` at the top, and a cancel link back to the application.

`show.blade.php` — in the profile card header, next to the reveal button: `<a href="{{ route('admin.applications.profile.edit', $application) }}" class="btn btn-sm btn-outline-secondary">{{ __('app.review.edit_profile') }}</a>`.

`lang/ar/app.php` `review`: `'edit_profile' => 'تعديل البيانات'`, `'profile_edit_warning' => 'تعديل بيانات المتقدم بناء على طلبه. يسجل كل تعديل في سجل التدقيق.'`. English twins.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter "AdminProfileEditTest|ProfileTest"` then `php artisan test`
Expected: PASS (the instructor `ProfileTest` still passes with the `ownerUserId()` refactor).

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: audited admin edit of an instructor profile on request"
```

---

### Task 6: Sections, meetings and assignments — migrations, models, factories

**Files:**
- Create: `database/migrations/2026_09_29_110000_create_sections_table.php`, `…_110001_create_section_meetings_table.php`, `…_110002_create_assignments_table.php`, `…_110003_change_weekly_hours_on_applications_table.php`, `app/Models/Section.php`, `app/Models/SectionMeeting.php`, `app/Models/Assignment.php`, `database/factories/SectionFactory.php`, `SectionMeetingFactory.php`, `AssignmentFactory.php`, `tests/Unit/SectionModelTest.php`
- Modify: `app/Models/Application.php`, `app/Models/Term.php`

**Interfaces:**
- Produces: `Section` (`term_id`, `course_code`, `course_name_ar`, `section_number`, `reference_number`, `seats_capacity`, `seats_registered`, `seats_remaining`, `scheduled_instructor`, `imported_at`, `missing_since_import`; relations `term()`, `meetings()`, `assignment()` HasOne; `weeklyMinutesByType(): array{theory:int,practical:int,field:int}`, `weeklyMinutes(): int`, `meetingSummary(): string` (Arabic, e.g. "محاضرة: الأحد/الثلاثاء 8:00-9:15؛ مختبر: الاثنين 9:30-11:10"), static `hoursFromMinutes(int): string` ("2.5")); `SectionMeeting` (`day_of_week`, `type`, `starts_at`, `ends_at`, `minutes`, `activity_ar`, `building`, `room`; const `TYPES`, `DAY_NAMES_AR = [0=>'الأحد',1=>'الاثنين',2=>'الثلاثاء',3=>'الأربعاء',4=>'الخميس']`); `Assignment` (`application_id`, `section_id`, `created_by`; relations); `Application::assignments()`, `Application::sections()` (hasManyThrough), `weekly_minutes` int column (the only stored load value; the M1 integer `weekly_hours` is dropped); `Term::sections()`. Factory states: `Section::factory()->for($term)`, `->withMeetings()` (adds theory Sun+Tue 8:00-9:15 and practical Mon 9:30-11:10 → 150+100 = 250 min).

- [ ] **Step 1: Write the failing test**

`tests/Unit/SectionModelTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\Section;
use App\Models\Term;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SectionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_weekly_minutes_by_type_and_summary(): void
    {
        $section = Section::factory()->for(Term::factory()->open())->withMeetings()->create();

        $this->assertSame(['theory' => 150, 'practical' => 100, 'field' => 0], $section->weeklyMinutesByType());
        $this->assertSame(250, $section->weeklyMinutes());
        $this->assertSame('2.5', Section::hoursFromMinutes(150));
        $this->assertSame('4.2', Section::hoursFromMinutes(250));
        $this->assertStringContainsString('محاضرة: الأحد/الثلاثاء 8:00-9:15', $section->meetingSummary());
        $this->assertStringContainsString('مختبر: الاثنين 9:30-11:10', $section->meetingSummary());
    }

    public function test_unique_section_per_term_and_meeting_uniqueness(): void
    {
        $term = Term::factory()->open()->create();
        Section::factory()->for($term)->create(['course_code' => '7220220', 'section_number' => '1']);
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        Section::factory()->for($term)->create(['course_code' => '7220220', 'section_number' => '1']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter SectionModelTest`
Expected: FAIL (class `Section` not found).

- [ ] **Step 3: Implement**

Migration `create_sections_table`:

```php
Schema::create('sections', function (Blueprint $table) {
    $table->id();
    $table->foreignId('term_id')->constrained()->cascadeOnDelete();
    $table->string('course_code', 12);
    $table->string('course_name_ar', 150);
    $table->string('section_number', 6);
    $table->string('reference_number', 20)->nullable();
    $table->unsignedSmallInteger('seats_capacity')->nullable();
    $table->unsignedSmallInteger('seats_registered')->nullable();
    $table->unsignedSmallInteger('seats_remaining')->nullable();
    $table->string('scheduled_instructor', 150)->nullable();
    $table->timestamp('imported_at')->nullable();
    $table->boolean('missing_since_import')->default(false);
    $table->timestamps();
    $table->unique(['term_id', 'course_code', 'section_number']);
});
```

Migration `create_section_meetings_table`:

```php
Schema::create('section_meetings', function (Blueprint $table) {
    $table->id();
    $table->foreignId('section_id')->constrained()->cascadeOnDelete();
    $table->unsignedTinyInteger('day_of_week');      // 0 Sunday … 4 Thursday
    $table->string('type', 10);                      // theory|practical|field
    $table->time('starts_at');
    $table->time('ends_at');
    $table->unsignedSmallInteger('minutes');
    $table->string('activity_ar', 30);
    $table->string('building', 20)->nullable();
    $table->string('room', 20)->nullable();
    $table->timestamps();
    $table->unique(['section_id', 'day_of_week', 'starts_at', 'type']);
});
```

Migration `create_assignments_table`:

```php
Schema::create('assignments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('application_id')->constrained()->cascadeOnDelete();
    $table->foreignId('section_id')->constrained()->cascadeOnDelete();
    $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();
    $table->unique('section_id');
    $table->unique(['application_id', 'section_id']);
});
```

Migration `change_weekly_hours_on_applications_table` (final-review fix: minutes are the single source of truth; `down()` mirrors it):

```php
Schema::table('applications', function (Blueprint $table) {
    $table->dropColumn('weekly_hours');
});
Schema::table('applications', function (Blueprint $table) {
    $table->unsignedInteger('weekly_minutes')->default(0)->after('assignment_decision_date');
});
```

and in `Application` display hours via `weeklyHoursLabel(): string` (`Section::hoursFromMinutes($this->weekly_minutes)`). Replace `weekly_hours` with `weekly_minutes` in `$fillable`.

`app/Models/SectionMeeting.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SectionMeeting extends Model
{
    use HasFactory;

    public const TYPES = ['theory', 'practical', 'field'];

    public const DAY_NAMES_AR = [0 => 'الأحد', 1 => 'الاثنين', 2 => 'الثلاثاء', 3 => 'الأربعاء', 4 => 'الخميس'];

    protected $fillable = ['day_of_week', 'type', 'starts_at', 'ends_at', 'minutes', 'activity_ar', 'building', 'room'];

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** "8:00" style, no seconds, no leading zero on the hour. */
    public function timeRange(): string
    {
        $fmt = fn (string $t) => ltrim(substr($t, 0, 5), '0') ?: '0'.substr($t, 1, 4);

        return $fmt($this->starts_at).'-'.$fmt($this->ends_at);
    }
}
```

`app/Models/Section.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Section extends Model
{
    use HasFactory;

    protected $fillable = ['term_id', 'course_code', 'course_name_ar', 'section_number', 'reference_number',
        'seats_capacity', 'seats_registered', 'seats_remaining', 'scheduled_instructor', 'imported_at', 'missing_since_import'];

    protected function casts(): array
    {
        return ['imported_at' => 'datetime', 'missing_since_import' => 'boolean'];
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(SectionMeeting::class)->orderBy('day_of_week')->orderBy('starts_at');
    }

    public function assignment(): HasOne
    {
        return $this->hasOne(Assignment::class);
    }

    /** @return array{theory:int, practical:int, field:int} */
    public function weeklyMinutesByType(): array
    {
        $out = ['theory' => 0, 'practical' => 0, 'field' => 0];
        foreach ($this->meetings as $m) {
            $out[$m->type] += $m->minutes;
        }

        return $out;
    }

    public function weeklyMinutes(): int
    {
        return array_sum($this->weeklyMinutesByType());
    }

    public static function hoursFromMinutes(int $minutes): string
    {
        return number_format($minutes / 60, 1, '.', '');
    }

    /** Groups meetings by activity + time: "محاضرة: الأحد/الثلاثاء 8:00-9:15؛ مختبر: الاثنين 9:30-11:10" */
    public function meetingSummary(): string
    {
        $groups = [];
        foreach ($this->meetings as $m) {
            $key = $m->activity_ar.'|'.$m->timeRange();
            $groups[$key]['activity'] = $m->activity_ar;
            $groups[$key]['time'] = $m->timeRange();
            $groups[$key]['days'][] = SectionMeeting::DAY_NAMES_AR[$m->day_of_week];
        }

        return implode('؛ ', array_map(fn ($g) => $g['activity'].': '.implode('/', $g['days']).' '.$g['time'], $groups));
    }

    public function label(): string
    {
        return $this->course_code.' / '.$this->section_number.' — '.$this->course_name_ar;
    }
}
```

`app/Models/Assignment.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Assignment extends Model
{
    use HasFactory;

    protected $fillable = ['application_id', 'section_id', 'created_by'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }
}
```

`Application` — add:

```php
public function assignments(): HasMany
{
    return $this->hasMany(Assignment::class);
}

public function sections(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
{
    return $this->hasManyThrough(Section::class, Assignment::class, 'application_id', 'id', 'id', 'section_id');
}

public function weeklyHoursLabel(): string
{
    return Section::hoursFromMinutes((int) $this->weekly_minutes);
}
```

`Term` — add `public function sections(): HasMany { return $this->hasMany(Section::class); }`.

Factories:

```php
// SectionFactory
public function definition(): array
{
    return [
        'term_id' => Term::factory()->open(),
        'course_code' => '72'.$this->faker->unique()->numerify('#####'),
        'course_name_ar' => 'الدوائر الكهربائية',
        'section_number' => '1',
        'reference_number' => (string) $this->faker->numberBetween(10000, 99999),
        'scheduled_instructor' => null,
        'imported_at' => now(),
    ];
}

public function withMeetings(): static
{
    return $this->afterCreating(function (Section $s) {
        foreach ([0, 2] as $day) {
            $s->meetings()->create(['day_of_week' => $day, 'type' => 'theory', 'starts_at' => '08:00', 'ends_at' => '09:15', 'minutes' => 75, 'activity_ar' => 'محاضرة', 'building' => '04A', 'room' => 'D-101']);
        }
        $s->meetings()->create(['day_of_week' => 1, 'type' => 'practical', 'starts_at' => '09:30', 'ends_at' => '11:10', 'minutes' => 100, 'activity_ar' => 'مختبر', 'building' => '04A', 'room' => 'L-12']);
    });
}

// SectionMeetingFactory: definition = day 0, theory, 08:00-09:15, 75 min, 'محاضرة'
// AssignmentFactory: definition = ['application_id' => Application::factory()->approved(), 'section_id' => Section::factory()]
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter SectionModelTest` then `php artisan test`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: sections, meetings and assignments schema and models"
```

---

### Task 7: `ArabicNameNormaliser` and `JadawilParser` (CSV) with fixture

**Files:**
- Create: `app/Support/ArabicNameNormaliser.php`, `app/Services/Sections/ParsedMeeting.php`, `ParsedSection.php`, `ParsedTimetable.php`, `JadawilParser.php`, `tests/Fixtures/jadawil-sample.csv`, `tests/Unit/ArabicNameNormaliserTest.php`, `tests/Unit/JadawilParserTest.php`

**Interfaces:**
- Produces: `ArabicNameNormaliser::normalise(?string): string`; `JadawilParser::parseCsv(string $contents): ParsedTimetable` and `JadawilParser::parse(string $contents, string $extension): ParsedTimetable` (`extension` `csv|xlsx`; xlsx added in Task 8). `ParsedTimetable { sections: array<string, ParsedSection> keyed by "code|section", warnings: string[], errors: string[] }`, `ParsedSection { courseCode, courseName, sectionNumber, referenceNumber?, scheduledInstructor?, seatsCapacity?, seatsRegistered?, seatsRemaining?, meetings: ParsedMeeting[] }`, `ParsedMeeting { dayOfWeek:int, type:string, startsAt:'HH:MM', endsAt:'HH:MM', minutes:int, activityAr, building?, room? }`; `ParsedSection::minutesByType(): array`; `ParsedTimetable::hasErrors(): bool`.

- [ ] **Step 1: Write the fixture and failing tests**

`tests/Fixtures/jadawil-sample.csv` (UTF-8 with BOM, exactly this content; note the two duplicate lecture rows for 7210110/1 and the unknown day on the last data row):

```csv
"رقم المقرر","اسم المقرر","النوع","النشاط","من","الى","المبنى","القاعة","الأيام","المدرس","الرقم المرجعي","الشعبة"
"7220220","الإلكترونيات الصناعية","L","محاضرة","8:00","9:15","04A","D-101","الأحد / الثلاثاء","د. فلان الفلاني","10231","1"
"7220220","الإلكترونيات الصناعية","B","مختبر","9:30","11:10","04A","L-12","الإثنين","م. علان","10231","1"
"7220220","الإلكترونيات الصناعية","L","محاضرة","11:00","12:15","04A","D-102","الأحد / الثلاثاء","د. فلان الفلاني","10232","2"
"7210110","الدوائر الكهربائية 1","L","محاضرة","8:00","9:15","04B","D-201","الإثنين / الأربعاء","محمد أحمد علي الفهد","10310","1"
"7210110","الدوائر الكهربائية 1","L","محاضرة","8:00","9:15","04B","D-201","الإثنين / الأربعاء","محمد أحمد علي الفهد","10310","1"
"7210110","الدوائر الكهربائية 1","F","ميداني","13:00","15:00","","","الخميس","محمد أحمد علي الفهد","10310","1"
"7230330","تدريب عملي","W","ورشة","8:00","11:00","04A","W-1","الجمعة","","10400","1"
"Powered by jadawil.q8ee.com",,,,,,,,,,,
```

`tests/Unit/ArabicNameNormaliserTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Support\ArabicNameNormaliser;
use PHPUnit\Framework\TestCase;

class ArabicNameNormaliserTest extends TestCase
{
    public function test_folds_hamza_ta_marbuta_alef_maqsura_tashkeel_and_spaces(): void
    {
        $this->assertSame('احمد مصطفي عبدالله', ArabicNameNormaliser::normalise('  أحمد   مُصطفى عبدالله '));
        $this->assertSame('د. فاطمه', ArabicNameNormaliser::normalise('د. فاطمة'));
        $this->assertSame(ArabicNameNormaliser::normalise('الإثنين'), ArabicNameNormaliser::normalise('الاثنين'));
        $this->assertSame('', ArabicNameNormaliser::normalise(null));
    }
}
```

`tests/Unit/JadawilParserTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\Sections\JadawilParser;
use PHPUnit\Framework\TestCase;

class JadawilParserTest extends TestCase
{
    private function csv(): string
    {
        return file_get_contents(base_path('tests/Fixtures/jadawil-sample.csv'));
    }

    public function test_groups_rows_into_sections_and_meetings(): void
    {
        $t = (new JadawilParser)->parseCsv($this->csv());

        $this->assertCount(3, $t->sections); // 7230330/1 is excluded by its error row
        $s = $t->sections['7220220|1'];
        $this->assertSame('الإلكترونيات الصناعية', $s->courseName);
        $this->assertSame('10231', $s->referenceNumber);
        $this->assertSame('د. فلان الفلاني', $s->scheduledInstructor);
        $this->assertCount(3, $s->meetings); // Sun+Tue lecture, Mon lab
        $this->assertSame(['theory' => 150, 'practical' => 100, 'field' => 0], $s->minutesByType());
        $lab = $s->meetings[2];
        $this->assertSame(1, $lab->dayOfWeek);
        $this->assertSame('practical', $lab->type);
        $this->assertSame('09:30', $lab->startsAt);
        $this->assertSame(100, $lab->minutes);
        $this->assertSame('L-12', $lab->room);
    }

    public function test_duplicate_meeting_rows_are_collapsed_with_a_warning(): void
    {
        $t = (new JadawilParser)->parseCsv($this->csv());
        $s = $t->sections['7210110|1'];

        $this->assertCount(3, $s->meetings); // Mon+Wed lecture once, Thu field
        $this->assertSame(['theory' => 150, 'practical' => 0, 'field' => 120], $s->minutesByType());
        $this->assertNotEmpty(array_filter($t->warnings, fn ($w) => str_contains($w, '7210110') && str_contains($w, 'مكرر')));
    }

    public function test_unknown_day_is_an_error_with_row_number_and_blocks(): void
    {
        $t = (new JadawilParser)->parseCsv($this->csv());

        $this->assertTrue($t->hasErrors());
        $this->assertNotEmpty(array_filter($t->errors, fn ($e) => str_contains($e, 'الجمعة') && str_contains($e, '8')));
        $this->assertArrayNotHasKey('7230330|1', $t->sections);
    }

    public function test_unknown_activity_maps_to_theory_with_warning_and_empty_day_is_error(): void
    {
        $csv = "\xEF\xBB\xBF\"رقم المقرر\",\"اسم المقرر\",\"النشاط\",\"من\",\"الى\",\"الأيام\",\"الشعبة\"\n"
            ."\"7200100\",\"مقرر\",\"سمنار\",\"8:00\",\"9:00\",\"الأحد\",\"1\"\n"
            ."\"7200101\",\"مقرر\",\"محاضرة\",\"8:00\",\"9:00\",\"\",\"1\"\n";
        $t = (new JadawilParser)->parseCsv($csv);

        $this->assertSame('theory', $t->sections['7200100|1']->meetings[0]->type);
        $this->assertNotEmpty(array_filter($t->warnings, fn ($w) => str_contains($w, 'سمنار')));
        $this->assertNotEmpty(array_filter($t->errors, fn ($e) => str_contains($e, '3')));
    }

    public function test_missing_required_header_is_an_error(): void
    {
        $t = (new JadawilParser)->parseCsv("\"رقم المقرر\",\"اسم المقرر\"\n\"1\",\"x\"\n");
        $this->assertTrue($t->hasErrors());
        $this->assertSame([], $t->sections);
    }

    public function test_malformed_time_and_end_before_start_are_errors(): void
    {
        $csv = "\"رقم المقرر\",\"اسم المقرر\",\"النشاط\",\"من\",\"الى\",\"الأيام\",\"الشعبة\"\n"
            ."\"7200100\",\"مقرر\",\"محاضرة\",\"8h\",\"9:00\",\"الأحد\",\"1\"\n"
            ."\"7200101\",\"مقرر\",\"محاضرة\",\"9:00\",\"8:00\",\"الأحد\",\"1\"\n";
        $t = (new JadawilParser)->parseCsv($csv);
        $this->assertCount(2, $t->errors);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter "ArabicNameNormaliserTest|JadawilParserTest"`
Expected: FAIL (classes not found).

- [ ] **Step 3: Implement**

`app/Support/ArabicNameNormaliser.php`:

```php
<?php

namespace App\Support;

class ArabicNameNormaliser
{
    public static function normalise(?string $value): string
    {
        $v = trim((string) $value);
        $v = preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u', '', $v); // tashkeel + tatweel
        $v = str_replace(['أ', 'إ', 'آ'], 'ا', $v);
        $v = str_replace('ة', 'ه', $v);
        $v = str_replace('ى', 'ي', $v);
        $v = preg_replace('/\s+/u', ' ', $v);

        return trim($v);
    }
}
```

Value objects (`app/Services/Sections/`):

```php
// ParsedMeeting.php
final class ParsedMeeting
{
    public function __construct(
        public readonly int $dayOfWeek, public readonly string $type,
        public readonly string $startsAt, public readonly string $endsAt, public readonly int $minutes,
        public readonly string $activityAr, public readonly ?string $building = null, public readonly ?string $room = null,
    ) {}

    public function key(): string { return $this->dayOfWeek.'|'.$this->startsAt.'|'.$this->type; }
}

// ParsedSection.php
final class ParsedSection
{
    /** @param ParsedMeeting[] $meetings */
    public function __construct(
        public readonly string $courseCode, public readonly string $courseName, public readonly string $sectionNumber,
        public ?string $referenceNumber = null, public ?string $scheduledInstructor = null,
        public ?int $seatsCapacity = null, public ?int $seatsRegistered = null, public ?int $seatsRemaining = null,
        public array $meetings = [],
    ) {}

    public function key(): string { return $this->courseCode.'|'.$this->sectionNumber; }

    /** @return array{theory:int, practical:int, field:int} */
    public function minutesByType(): array
    {
        $out = ['theory' => 0, 'practical' => 0, 'field' => 0];
        foreach ($this->meetings as $m) { $out[$m->type] += $m->minutes; }
        return $out;
    }
}

// ParsedTimetable.php
final class ParsedTimetable
{
    /** @param array<string, ParsedSection> $sections */
    public function __construct(public array $sections = [], public array $warnings = [], public array $errors = []) {}

    public function hasErrors(): bool { return $this->errors !== []; }
}
```

`app/Services/Sections/JadawilParser.php`:

```php
<?php

namespace App\Services\Sections;

use App\Support\ArabicNameNormaliser;

class JadawilParser
{
    public const REQUIRED = ['رقم المقرر', 'اسم المقرر', 'الشعبة', 'النشاط', 'من', 'الى', 'الأيام'];

    private const ACTIVITY = ['محاضرة' => 'theory', 'مختبر' => 'practical', 'ورشة' => 'practical', 'عملي' => 'practical', 'ميداني' => 'field'];

    /** Normalised day name → 0..4 */
    private const DAYS = ['الاحد' => 0, 'الاثنين' => 1, 'الثلاثاء' => 2, 'الاربعاء' => 3, 'الخميس' => 4];

    public function parse(string $contents, string $extension): ParsedTimetable
    {
        return match (strtolower($extension)) {
            'csv' => $this->parseCsv($contents),
            'xlsx' => $this->parseXlsx($contents), // Task 8
            default => new ParsedTimetable(errors: [__('app.sections.unsupported_file')]),
        };
    }

    public function parseCsv(string $contents): ParsedTimetable
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        $lines = preg_split('/\r\n|\r|\n/', trim($contents)) ?: [];
        $rows = [];
        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $rows[] = ['n' => $i + 1, 'cells' => array_map('trim', str_getcsv($line))];
        }

        return $this->fromRows($rows);
    }

    /**
     * @param  array<int, array{n:int, cells:string[]}>  $rows  first row = header
     */
    protected function fromRows(array $rows): ParsedTimetable
    {
        $t = new ParsedTimetable;
        if ($rows === []) {
            $t->errors[] = __('app.sections.no_header');

            return $t;
        }
        $header = array_map(fn ($h) => trim((string) $h), array_shift($rows)['cells']);
        $idx = array_flip($header);
        foreach (self::REQUIRED as $req) {
            if (! isset($idx[$req])) {
                $t->errors[] = __('app.sections.missing_column', ['column' => $req]);
            }
        }
        if ($t->hasErrors()) {
            return $t;
        }
        $cell = fn (array $cells, string $name) => isset($idx[$name]) ? trim((string) ($cells[$idx[$name]] ?? '')) : '';

        foreach ($rows as $row) {
            $c = $row['cells'];
            $n = $row['n'];
            $code = $cell($c, 'رقم المقرر');
            if ($code === '' || str_contains($code, 'Powered by')) {
                continue; // footer / blank
            }
            $section = $cell($c, 'الشعبة');
            if ($section === '') {
                $t->errors[] = __('app.sections.row_error', ['row' => $n, 'message' => __('app.sections.missing_section')]);
                continue;
            }
            $from = $this->time($cell($c, 'من'));
            $to = $this->time($cell($c, 'الى'));
            if ($from === null || $to === null || $to <= $from) {
                $t->errors[] = __('app.sections.row_error', ['row' => $n, 'message' => __('app.sections.bad_time', ['from' => $cell($c, 'من'), 'to' => $cell($c, 'الى')])]);
                continue;
            }
            $dayCell = $cell($c, 'الأيام');
            $days = [];
            $badDay = null;
            foreach (preg_split('/\s*\/\s*/u', $dayCell) as $d) {
                $k = ArabicNameNormaliser::normalise($d);
                if ($k === '') {
                    continue;
                }
                if (! isset(self::DAYS[$k])) {
                    $badDay = $d;
                    break;
                }
                $days[] = self::DAYS[$k];
            }
            if ($badDay !== null || $days === []) {
                $t->errors[] = __('app.sections.row_error', ['row' => $n, 'message' => __('app.sections.bad_day', ['day' => $badDay ?? '—'])]);
                continue;
            }
            $activity = $cell($c, 'النشاط');
            $type = self::ACTIVITY[$activity] ?? null;
            if ($type === null) {
                $type = 'theory';
                $t->warnings[] = __('app.sections.unknown_activity', ['row' => $n, 'activity' => $activity]);
            }

            $key = $code.'|'.$section;
            $ps = $t->sections[$key] ??= new ParsedSection($code, $cell($c, 'اسم المقرر'), $section);
            $ps->referenceNumber ??= $cell($c, 'الرقم المرجعي') ?: null;
            $ps->scheduledInstructor ??= $cell($c, 'المدرس') ?: null;
            $ps->seatsCapacity ??= $this->int($cell($c, 'الحد الأقصى'));
            $ps->seatsRegistered ??= $this->int($cell($c, 'مسجلة'));
            $ps->seatsRemaining ??= $this->int($cell($c, 'متبقية'));

            $minutes = $this->minutes($to) - $this->minutes($from);
            foreach ($days as $day) {
                $m = new ParsedMeeting($day, $type, $from, $to, $minutes, $activity, $cell($c, 'المبنى') ?: null, $cell($c, 'القاعة') ?: null);
                $dup = array_filter($ps->meetings, fn ($x) => $x->key() === $m->key());
                if ($dup !== []) {
                    $t->warnings[] = __('app.sections.duplicate_meeting', ['row' => $n, 'course' => $code, 'section' => $section]);
                    continue;
                }
                $ps->meetings[] = $m;
            }
        }

        ksort($t->sections);
        $t->warnings = array_values(array_unique($t->warnings));

        return $t;
    }

    /** "8:00" / "13:05" → "08:00" / "13:05"; null when malformed. */
    private function time(string $v): ?string
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $v, $m) || (int) $m[1] > 23 || (int) $m[2] > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $m[1], $m[2]);
    }

    private function minutes(string $hhmm): int
    {
        [$h, $m] = explode(':', $hhmm);

        return (int) $h * 60 + (int) $m;
    }

    private function int(string $v): ?int
    {
        return preg_match('/^\d+$/', $v) ? (int) $v : null;
    }

    protected function parseXlsx(string $contents): ParsedTimetable
    {
        return new ParsedTimetable(errors: [__('app.sections.unsupported_file')]); // replaced in Task 8
    }
}
```

`lang/ar/app.php` — new group:

```php
'sections' => [
    'unsupported_file' => 'نوع الملف غير مدعوم. المقبول: CSV أو XLSX من نظام الجداول.',
    'no_header' => 'الملف فارغ أو لا يحتوي على صف العناوين.',
    'missing_column' => 'العمود المطلوب ":column" غير موجود في الملف.',
    'row_error' => 'السطر :row: :message',
    'missing_section' => 'رقم الشعبة فارغ.',
    'bad_time' => 'وقت غير صحيح (:from - :to).',
    'bad_day' => 'يوم غير معروف ":day".',
    'unknown_activity' => 'السطر :row: النشاط ":activity" غير معروف، اعتبر نظريا.',
    'duplicate_meeting' => 'السطر :row: لقاء مكرر للمقرر :course شعبة :section، تم تجاهله.',
],
```

English twins in `lang/en/app.php`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter "ArabicNameNormaliserTest|JadawilParserTest"`
Expected: PASS (11 tests). If the fixture's BOM was lost by the editor, re-add it: `printf '\xEF\xBB\xBF' | cat - tests/Fixtures/jadawil-sample.csv > /tmp/f && mv /tmp/f tests/Fixtures/jadawil-sample.csv`.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: jadawil CSV parser with Arabic name normaliser and fixture"
```

---

### Task 8: XLSX support in `JadawilParser`

**Files:**
- Modify: `composer.json` (require `phpoffice/phpspreadsheet:^2.0` or the latest 1.x/2.x/3.x that installs on PHP 8.5 — pick the newest that `composer require phpoffice/phpspreadsheet` resolves without conflicts and record the version in the report), `app/Services/Sections/JadawilParser.php`, `tests/Unit/JadawilParserTest.php`

**Interfaces:**
- Produces: `JadawilParser::parseXlsx(string $contents): ParsedTimetable` reading the first worksheet; the first non-empty row is the header (jadawil's XLSX header includes a leading `#` column and `الحالة`, `الرابط`, `الحد الأقصى`, `مسجلة`, `متبقية`, `الوحدات`, `الفرع` — all optional/ignored except the seats columns). Cells may be numeric (course codes, section numbers, times as Excel fractions): course code and section number are cast to string; a time cell that is a numeric fraction is converted to `H:MM`.

- [ ] **Step 1: Write the failing test** (append to `tests/Unit/JadawilParserTest.php`)

```php
public function test_parses_xlsx_with_numeric_cells_and_seats(): void
{
    $sheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
    $ws = $sheet->getActiveSheet();
    $ws->fromArray(['#', 'رقم المقرر', 'الرقم المرجعي', 'الشعبة', 'اسم المقرر', 'الحالة', 'الرابط', 'الحد الأقصى', 'مسجلة', 'متبقية', 'الوحدات', 'النشاط', 'من', 'الى', 'المبنى', 'القاعة', 'الأيام', 'المدرس', 'الفرع'], null, 'A1');
    $ws->fromArray([1, 7220220, 10231, 1, 'الإلكترونيات الصناعية', 'مفتوحة', 'A', 25, 20, 5, 3, 'محاضرة', '8:00', '9:15', '04A', 'D-101', 'الأحد / الثلاثاء', 'د. فلان', 'ش'], null, 'A2');
    $ws->fromArray([2, 7220220, 10231, 1, 'الإلكترونيات الصناعية', 'مفتوحة', 'A', 25, 20, 5, 3, 'مختبر', 9.5 / 24, 11.0 / 24, '04A', 'L-12', 'الإثنين', 'م. علان', 'ش'], null, 'A3');
    $path = tempnam(sys_get_temp_dir(), 'jad').'.xlsx';
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sheet))->save($path);

    $t = (new JadawilParser)->parse(file_get_contents($path), 'xlsx');
    unlink($path);

    $this->assertFalse($t->hasErrors(), implode("\n", $t->errors));
    $s = $t->sections['7220220|1'];
    $this->assertSame(25, $s->seatsCapacity);
    $this->assertSame(5, $s->seatsRemaining);
    $this->assertCount(3, $s->meetings);
    $this->assertSame('09:30', $s->meetings[2]->startsAt);
    $this->assertSame(90, $s->meetings[2]->minutes);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer require phpoffice/phpspreadsheet && php artisan test --filter test_parses_xlsx`
Expected: FAIL (`unsupported_file` error from the stub).

- [ ] **Step 3: Implement** — replace the `parseXlsx()` stub:

```php
protected function parseXlsx(string $contents): ParsedTimetable
{
    $tmp = tempnam(sys_get_temp_dir(), 'jadawil');
    file_put_contents($tmp, $contents);
    try {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($tmp)->getSheet(0);
        $rows = [];
        foreach ($sheet->toArray(null, true, false, false) as $i => $cells) {
            $cells = array_map(fn ($v) => $this->cellToString($v), $cells);
            if (implode('', $cells) === '') {
                continue;
            }
            $rows[] = ['n' => $i + 1, 'cells' => $cells];
        }
    } catch (\Throwable $e) {
        return new ParsedTimetable(errors: [__('app.sections.unreadable_xlsx')]);
    } finally {
        @unlink($tmp);
    }

    return $this->fromRows($rows);
}

/** Numeric time fractions (0.5 = 12:00) become H:MM; other scalars become trimmed strings. */
private function cellToString(mixed $v): string
{
    if ($v === null) {
        return '';
    }
    if (is_float($v) && $v > 0 && $v < 1) {
        $minutes = (int) round($v * 24 * 60);

        return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
    if (is_float($v) && floor($v) == $v) {
        return (string) (int) $v;
    }

    return trim((string) $v);
}
```

Add `'unreadable_xlsx' => 'تعذر قراءة ملف XLSX.'` (+ English) to the `sections` lang group.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter JadawilParserTest`
Expected: PASS. Commit `composer.json` and `composer.lock`.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: XLSX support in the jadawil parser"
```

---

### Task 9: `SectionImporter` — diff plan and transactional apply

**Files:**
- Create: `app/Services/Sections/SectionImporter.php`, `app/Services/Sections/ImportPlan.php`, `tests/Feature/Admin/SectionImportServiceTest.php`

**Interfaces:**
- Consumes: `ParsedTimetable`, `Section`, `SectionMeeting`, `Assignment`, `AssignmentService::recomputeHours(Application)` (Task 11 — until then, this task ships a private `recompute()` helper inside the importer; Task 11 replaces the call).
- Produces: `SectionImporter::plan(Term, ParsedTimetable): ImportPlan` (no writes) with `insert: string[]`, `update: string[]`, `unchanged: string[]`, `delete: string[]`, `flag: string[]` (keys `code|section`) and `counts(): array`; `SectionImporter::apply(Term, ParsedTimetable, User $admin): ImportPlan` (transaction; throws `\DomainException` when `$timetable->hasErrors()` or the term is closed; audit `import_sections` with the counts encoded in the action string suffix, e.g. `import_sections:+3/~2/=1/-1/!1`).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Admin/SectionImportServiceTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use App\Services\Sections\JadawilParser;
use App\Services\Sections\SectionImporter;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SectionImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private Term $term;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChecklistItemSeeder::class);
        $this->term = Term::factory()->open()->create();
        $this->admin = User::factory()->admin()->create();
    }

    private function timetable(string $csv)
    {
        return (new JadawilParser)->parseCsv($csv);
    }

    private const HEADER = "\"رقم المقرر\",\"اسم المقرر\",\"النشاط\",\"من\",\"الى\",\"الأيام\",\"المدرس\",\"الرقم المرجعي\",\"الشعبة\"\n";

    private function csvA(): string
    {
        return self::HEADER
            ."\"7220220\",\"الإلكترونيات\",\"محاضرة\",\"8:00\",\"9:15\",\"الأحد / الثلاثاء\",\"د. فلان\",\"10231\",\"1\"\n"
            ."\"7210110\",\"الدوائر\",\"محاضرة\",\"8:00\",\"9:15\",\"الإثنين\",\"\",\"10310\",\"1\"\n";
    }

    public function test_first_import_inserts_and_is_idempotent(): void
    {
        $importer = app(SectionImporter::class);
        $plan = $importer->apply($this->term, $this->timetable($this->csvA()), $this->admin);

        $this->assertSame(['insert' => 2, 'update' => 0, 'unchanged' => 0, 'delete' => 0, 'flag' => 0], $plan->counts());
        $this->assertDatabaseCount('sections', 2);
        $this->assertDatabaseCount('section_meetings', 3);
        $this->assertDatabaseHas('audit_log', ['action' => 'import_sections:+2/~0/=0/-0/!0']);

        $plan2 = $importer->apply($this->term, $this->timetable($this->csvA()), $this->admin);
        $this->assertSame(['insert' => 0, 'update' => 0, 'unchanged' => 2, 'delete' => 0, 'flag' => 0], $plan2->counts());
        $this->assertDatabaseCount('section_meetings', 3);
    }

    public function test_reimport_updates_meetings_deletes_unassigned_and_flags_assigned(): void
    {
        $importer = app(SectionImporter::class);
        $importer->apply($this->term, $this->timetable($this->csvA()), $this->admin);
        $assigned = Section::where('course_code', '7220220')->firstOrFail();
        $application = Application::factory()->approved()->for($this->term)->create();
        Assignment::create(['application_id' => $application->id, 'section_id' => $assigned->id]);

        // 7220220/1 now meets on Wednesday only (changed), 7210110/1 absent, new 7230330/1
        $csvB = self::HEADER
            ."\"7220220\",\"الإلكترونيات\",\"محاضرة\",\"8:00\",\"9:15\",\"الأربعاء\",\"د. فلان\",\"10231\",\"1\"\n"
            ."\"7230330\",\"ورشة\",\"ورشة\",\"8:00\",\"11:00\",\"الخميس\",\"\",\"10400\",\"1\"\n";
        $plan = $importer->plan($this->term, $this->timetable($csvB));
        $this->assertSame(['insert' => 1, 'update' => 1, 'unchanged' => 0, 'delete' => 1, 'flag' => 0], $plan->counts());
        $this->assertDatabaseCount('sections', 2); // plan() wrote nothing

        $importer->apply($this->term, $this->timetable($csvB), $this->admin);
        $this->assertDatabaseMissing('sections', ['course_code' => '7210110']);
        $this->assertSame([3], $assigned->fresh()->meetings->pluck('day_of_week')->all());
        $this->assertSame(75, $application->fresh()->weekly_minutes);

        // Assigned section disappears from the next file → kept + flagged; reappears → unflagged.
        $csvC = self::HEADER."\"7230330\",\"ورشة\",\"ورشة\",\"8:00\",\"11:00\",\"الخميس\",\"\",\"10400\",\"1\"\n";
        $plan = $importer->apply($this->term, $this->timetable($csvC), $this->admin);
        $this->assertSame(1, $plan->counts()['flag']);
        $this->assertTrue($assigned->fresh()->missing_since_import);
        $this->assertDatabaseHas('assignments', ['section_id' => $assigned->id]);

        $importer->apply($this->term, $this->timetable($csvB), $this->admin);
        $this->assertFalse($assigned->fresh()->missing_since_import);
    }

    public function test_apply_refuses_errors_and_closed_terms_and_rolls_back(): void
    {
        $importer = app(SectionImporter::class);
        $bad = $this->timetable(self::HEADER."\"7220220\",\"x\",\"محاضرة\",\"8:00\",\"9:15\",\"الجمعة\",\"\",\"1\",\"1\"\n");
        $this->expectException(\DomainException::class);
        $importer->apply($this->term, $bad, $this->admin);
    }

    public function test_apply_on_closed_term_is_refused(): void
    {
        $this->term->update(['status' => 'closed']);
        $this->expectException(\DomainException::class);
        app(SectionImporter::class)->apply($this->term, $this->timetable($this->csvA()), $this->admin);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter SectionImportServiceTest`
Expected: FAIL (class not found).

- [ ] **Step 3: Implement**

`app/Services/Sections/ImportPlan.php`:

```php
<?php

namespace App\Services\Sections;

final class ImportPlan
{
    /** @var string[] */
    public array $insert = [];
    /** @var string[] */
    public array $update = [];
    /** @var string[] */
    public array $unchanged = [];
    /** @var string[] */
    public array $delete = [];
    /** @var string[] */
    public array $flag = [];

    /** @return array{insert:int, update:int, unchanged:int, delete:int, flag:int} */
    public function counts(): array
    {
        return ['insert' => count($this->insert), 'update' => count($this->update), 'unchanged' => count($this->unchanged), 'delete' => count($this->delete), 'flag' => count($this->flag)];
    }

    public function auditSuffix(): string
    {
        $c = $this->counts();

        return "+{$c['insert']}/~{$c['update']}/={$c['unchanged']}/-{$c['delete']}/!{$c['flag']}";
    }
}
```

`app/Services/Sections/SectionImporter.php`:

```php
<?php

namespace App\Services\Sections;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SectionImporter
{
    public function plan(Term $term, ParsedTimetable $timetable): ImportPlan
    {
        $plan = new ImportPlan;
        $existing = $term->sections()->with(['meetings', 'assignment'])->get()
            ->keyBy(fn (Section $s) => $s->course_code.'|'.$s->section_number);

        foreach ($timetable->sections as $key => $parsed) {
            $current = $existing->get($key);
            if (! $current) {
                $plan->insert[] = $key;
            } elseif ($this->fingerprint($current) === $this->fingerprintParsed($parsed)) {
                $plan->unchanged[] = $key;
            } else {
                $plan->update[] = $key;
            }
        }
        foreach ($existing as $key => $section) {
            if (isset($timetable->sections[$key])) {
                continue;
            }
            $section->assignment ? $plan->flag[] = $key : $plan->delete[] = $key;
        }

        return $plan;
    }

    public function apply(Term $term, ParsedTimetable $timetable, User $admin): ImportPlan
    {
        if (! $term->isOpen()) {
            throw new \DomainException(__('app.applications.term_closed'));
        }
        if ($timetable->hasErrors()) {
            throw new \DomainException(__('app.sections.import_has_errors'));
        }

        return DB::transaction(function () use ($term, $timetable, $admin) {
            $plan = $this->plan($term, $timetable);
            $touchedApplications = [];

            foreach ($timetable->sections as $key => $parsed) {
                if (in_array($key, $plan->unchanged, true)) {
                    $term->sections()->where('course_code', $parsed->courseCode)->where('section_number', $parsed->sectionNumber)
                        ->update(['imported_at' => now(), 'missing_since_import' => false]);
                    continue;
                }
                $section = $term->sections()->updateOrCreate(
                    ['course_code' => $parsed->courseCode, 'section_number' => $parsed->sectionNumber],
                    [
                        'course_name_ar' => $parsed->courseName, 'reference_number' => $parsed->referenceNumber,
                        'seats_capacity' => $parsed->seatsCapacity, 'seats_registered' => $parsed->seatsRegistered, 'seats_remaining' => $parsed->seatsRemaining,
                        'scheduled_instructor' => $parsed->scheduledInstructor, 'imported_at' => now(), 'missing_since_import' => false,
                    ],
                );
                $section->meetings()->delete();
                foreach ($parsed->meetings as $m) {
                    $section->meetings()->create([
                        'day_of_week' => $m->dayOfWeek, 'type' => $m->type, 'starts_at' => $m->startsAt, 'ends_at' => $m->endsAt,
                        'minutes' => $m->minutes, 'activity_ar' => $m->activityAr, 'building' => $m->building, 'room' => $m->room,
                    ]);
                }
                if ($section->assignment) {
                    $touchedApplications[$section->assignment->application_id] = true;
                }
            }

            foreach ($plan->delete as $key) {
                [$code, $num] = explode('|', $key, 2);
                $term->sections()->where('course_code', $code)->where('section_number', $num)->delete();
            }
            foreach ($plan->flag as $key) {
                [$code, $num] = explode('|', $key, 2);
                $term->sections()->where('course_code', $code)->where('section_number', $num)->update(['missing_since_import' => true]);
            }
            foreach (array_keys($touchedApplications) as $appId) {
                $this->recompute(Application::findOrFail($appId));
            }

            AuditLog::record($admin->id, 'import_sections:'.$plan->auditSuffix(), $term);

            return $plan;
        });
    }

    /** Replaced by AssignmentService::recomputeHours() in Task 11. */
    private function recompute(Application $application): void
    {
        $minutes = 0;
        foreach ($application->sections()->with('meetings')->get() as $s) {
            $minutes += $s->weeklyMinutes();
        }
        $application->update(['weekly_minutes' => $minutes]);
    }

    private function fingerprint(Section $s): string
    {
        $meetings = $s->meetings->map(fn ($m) => "{$m->day_of_week}|{$m->type}|".substr($m->starts_at, 0, 5).'|'.substr($m->ends_at, 0, 5)."|{$m->activity_ar}|{$m->building}|{$m->room}")->sort()->values()->all();

        return md5(json_encode([$s->course_name_ar, $s->reference_number, $s->scheduled_instructor, $s->seats_capacity, $s->seats_registered, $s->seats_remaining, $meetings]));
    }

    private function fingerprintParsed(ParsedSection $p): string
    {
        $meetings = array_map(fn ($m) => "{$m->dayOfWeek}|{$m->type}|{$m->startsAt}|{$m->endsAt}|{$m->activityAr}|{$m->building}|{$m->room}", $p->meetings);
        sort($meetings);

        return md5(json_encode([$p->courseName, $p->referenceNumber, $p->scheduledInstructor, $p->seatsCapacity, $p->seatsRegistered, $p->seatsRemaining, $meetings]));
    }
}
```

Add `'import_has_errors' => 'لا يمكن الاستيراد قبل تصحيح الأخطاء في الملف.'` (+ English) to `sections`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter SectionImportServiceTest` then `php artisan test`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: transactional section importer with diff plan and assignment-safe deletes"
```

---

### Task 10: Import screens (form → preview → confirm) and section list

**Files:**
- Create: `app/Http/Requests/ImportSectionsRequest.php`, `app/Http/Controllers/Admin/SectionImportController.php`, `app/Http/Controllers/Admin/SectionController.php`, `resources/views/admin/sections/import.blade.php`, `preview.blade.php`, `index.blade.php`, `tests/Feature/Admin/SectionImportTest.php`
- Modify: `routes/web.php`, `resources/views/admin/layout.blade.php`, `lang/ar/app.php`, `lang/en/app.php`

**Interfaces:**
- Consumes: `JadawilParser::parse()`, `SectionImporter::plan()/apply()`, `Term::current()`.
- Produces: routes `admin.sections.index` (GET `admin/sections`, optional `?term=`), `admin.sections.import.form` (GET `admin/sections/import`), `admin.sections.import.preview` (POST), `admin.sections.import.confirm` (POST). Session key `sections_import` holds `['term_id' => int, 'timetable' => serialize(ParsedTimetable)]` between preview and confirm and is forgotten after confirm.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Admin/SectionImportTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\Term;
use App\Models\User;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class SectionImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Term $term;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChecklistItemSeeder::class);
        $this->admin = User::factory()->admin()->create();
        $this->term = Term::factory()->open()->create();
    }

    private function upload(string $name = 'jadawil-sample.csv'): UploadedFile
    {
        return new UploadedFile(base_path('tests/Fixtures/'.$name), $name, 'text/csv', null, true);
    }

    public function test_preview_shows_counts_and_errors_and_writes_nothing(): void
    {
        $r = $this->actingAs($this->admin)->post(route('admin.sections.import.preview'), ['file' => $this->upload()])->assertOk();
        $r->assertSee(__('app.sections.preview_insert', ['n' => 3]));
        $r->assertSee('الجمعة');                       // error row shown
        $r->assertSee(__('app.sections.confirm_blocked'));
        $r->assertDontSee(__('app.sections.confirm'));  // button hidden while errors exist
        $this->assertDatabaseCount('sections', 0);
    }

    public function test_confirm_imports_from_session_and_clears_it(): void
    {
        $csv = "\xEF\xBB\xBF\"رقم المقرر\",\"اسم المقرر\",\"النشاط\",\"من\",\"الى\",\"الأيام\",\"الشعبة\"\n\"7220220\",\"الإلكترونيات\",\"محاضرة\",\"8:00\",\"9:15\",\"الأحد\",\"1\"\n";
        $file = UploadedFile::fake()->createWithContent('t.csv', $csv);

        $this->actingAs($this->admin)->post(route('admin.sections.import.preview'), ['file' => $file])->assertOk()->assertSee(__('app.sections.confirm'));
        $this->actingAs($this->admin)->post(route('admin.sections.import.confirm'))->assertRedirect(route('admin.sections.index'));

        $this->assertDatabaseHas('sections', ['term_id' => $this->term->id, 'course_code' => '7220220']);
        $this->assertNull(session('sections_import'));
        $this->actingAs($this->admin)->post(route('admin.sections.import.confirm'))->assertStatus(419);
    }

    public function test_rejects_wrong_file_types_and_requires_open_term(): void
    {
        $this->actingAs($this->admin)->post(route('admin.sections.import.preview'), ['file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('file');

        $this->term->update(['status' => 'closed']);
        $this->actingAs($this->admin)->get(route('admin.sections.import.form'))->assertSee(__('app.terms.none_open'));
        $this->actingAs($this->admin)->post(route('admin.sections.import.preview'), ['file' => $this->upload()])->assertSessionHasErrors('file');
    }

    public function test_index_lists_sections_with_hours_and_summary(): void
    {
        \App\Models\Section::factory()->for($this->term)->withMeetings()->create(['course_code' => '7220220', 'course_name_ar' => 'الإلكترونيات']);
        $r = $this->actingAs($this->admin)->get(route('admin.sections.index'))->assertOk();
        $r->assertSee('7220220')->assertSee('2.5')->assertSee('1.7')->assertSee('محاضرة: الأحد/الثلاثاء 8:00-9:15');
    }

    public function test_instructor_gets_403_on_all_section_routes(): void
    {
        $u = User::factory()->instructor()->create();
        $this->actingAs($u)->get(route('admin.sections.index'))->assertForbidden();
        $this->actingAs($u)->get(route('admin.sections.import.form'))->assertForbidden();
        $this->actingAs($u)->post(route('admin.sections.import.preview'), ['file' => $this->upload()])->assertForbidden();
        $this->actingAs($u)->post(route('admin.sections.import.confirm'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter SectionImportTest`
Expected: FAIL (routes missing).

- [ ] **Step 3: Implement**

`app/Http/Requests/ImportSectionsRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\Term;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ImportSectionsRequest extends FormRequest
{
    public function rules(): array
    {
        return ['file' => ['required', 'file', 'max:10240', 'extensions:csv,xlsx']];
    }

    public function withValidator(Validator $v): void
    {
        $v->after(function (Validator $v) {
            if (! Term::current()) {
                $v->errors()->add('file', __('app.terms.none_open'));
            }
        });
    }
}
```

`app/Http/Controllers/Admin/SectionImportController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportSectionsRequest;
use App\Models\Term;
use App\Services\Sections\JadawilParser;
use App\Services\Sections\ParsedTimetable;
use App\Services\Sections\SectionImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SectionImportController extends Controller
{
    public function __construct(private JadawilParser $parser, private SectionImporter $importer) {}

    public function form(): View
    {
        return view('admin.sections.import', ['term' => Term::current()]);
    }

    public function preview(ImportSectionsRequest $request): View
    {
        $term = Term::current();
        $file = $request->file('file');
        $timetable = $this->parser->parse($file->get(), $file->getClientOriginalExtension());
        $plan = $this->importer->plan($term, $timetable);

        session(['sections_import' => ['term_id' => $term->id, 'timetable' => serialize($timetable)]]);

        return view('admin.sections.preview', ['term' => $term, 'timetable' => $timetable, 'plan' => $plan]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $payload = session('sections_import');
        abort_if(! $payload, 419);
        $term = Term::findOrFail($payload['term_id']);
        /** @var ParsedTimetable $timetable */
        $timetable = unserialize($payload['timetable'], ['allowed_classes' => [ParsedTimetable::class, \App\Services\Sections\ParsedSection::class, \App\Services\Sections\ParsedMeeting::class]]);
        try {
            $plan = $this->importer->apply($term, $timetable, $request->user());
        } catch (\DomainException $e) {
            return redirect()->route('admin.sections.import.form')->withErrors(['file' => $e->getMessage()]);
        }
        session()->forget('sections_import');
        $c = $plan->counts();

        return redirect()->route('admin.sections.index')->with('status', __('app.sections.imported', $c));
    }
}
```

`app/Http/Controllers/Admin/SectionController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Term;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SectionController extends Controller
{
    public function index(Request $request): View
    {
        $term = $request->filled('term') ? Term::findOrFail($request->term) : Term::current();
        $sections = $term
            ? $term->sections()->with(['meetings', 'assignment.application.instructor'])
                ->when($request->filled('course'), fn ($q) => $q->where('course_code', 'like', $request->course.'%'))
                ->when($request->boolean('unassigned'), fn ($q) => $q->doesntHave('assignment'))
                ->orderBy('course_code')->orderBy('section_number')->get()
            : collect();

        return view('admin.sections.index', ['term' => $term, 'sections' => $sections, 'terms' => Term::orderByDesc('teaching_starts_on')->get()]);
    }
}
```

`routes/web.php` (admin group):

```php
Route::get('sections', [SectionController::class, 'index'])->name('sections.index');
Route::get('sections/import', [SectionImportController::class, 'form'])->name('sections.import.form');
Route::post('sections/import/preview', [SectionImportController::class, 'preview'])->name('sections.import.preview');
Route::post('sections/import/confirm', [SectionImportController::class, 'confirm'])->name('sections.import.confirm');
```

Views (all extend `admin.layout`):
- `import.blade.php`: if `! $term` → alert `app.terms.none_open`; else a multipart form with one file input (`accept=".csv,.xlsx"`), help text `app.sections.import_help`, submit `app.sections.preview`.
- `preview.blade.php`: term label; a counts row from `$plan->counts()` using `app.sections.preview_insert|update|unchanged|delete|flag` (each takes `:n`); a warnings list; an errors list (red); a table of parsed sections (course, section, name, instructor, meetings summarised as `activity: days time` per meeting group, hours by type from `minutesByType()` via `Section::hoursFromMinutes`); then `@if ($timetable->hasErrors()) alert app.sections.confirm_blocked @else` a POST form to `admin.sections.import.confirm` with button `app.sections.confirm` `@endif`, plus a cancel link.
- `index.blade.php`: term selector (GET) + course filter + "unassigned only" checkbox; table: course code, section, name, `meetingSummary()`, hours theory/practical/field (`hoursFromMinutes` of `weeklyMinutesByType()`), scheduled instructor, assignee (`$s->assignment?->application->instructor->full_name`), a `badge bg-danger` `app.sections.missing_badge` when `missing_since_import`. Link to the import form.

`admin/layout.blade.php` nav: add links to `admin.sections.index` (`app.sections.title`) and, in Task 12, `admin.assignments.index`.

`lang/ar/app.php` `sections` group — add: `'title' => 'الشعب'`, `'import' => 'استيراد الجدول'`, `'import_help' => 'ارفع ملف CSV أو XLSX المصدر من نظام الجداول (جداول) للفصل الحالي.'`, `'preview' => 'معاينة'`, `'confirm' => 'تأكيد الاستيراد'`, `'confirm_blocked' => 'يوجد أخطاء في الملف، صححها في نظام الجداول ثم أعد الرفع.'`, `'preview_insert' => 'جديدة: :n'`, `'preview_update' => 'محدثة: :n'`, `'preview_unchanged' => 'بدون تغيير: :n'`, `'preview_delete' => 'ستحذف: :n'`, `'preview_flag' => 'مسندة وغير موجودة في الملف (ستبقى مع تنبيه): :n'`, `'imported' => 'تم الاستيراد: :insert جديدة، :update محدثة، :unchanged بدون تغيير، :delete محذوفة، :flag مع تنبيه.'`, `'missing_badge' => 'غير موجودة في آخر استيراد'`, `'course' => 'المقرر'`, `'section' => 'الشعبة'`, `'meetings' => 'اللقاءات'`, `'hours_theory' => 'نظري'`, `'hours_practical' => 'عملي'`, `'hours_field' => 'ميداني'`, `'scheduled_instructor' => 'المدرس في الجدول'`, `'assignee' => 'المنتدب المسند'`, `'unassigned_only' => 'غير المسندة فقط'`, `'warnings' => 'تنبيهات'`, `'errors' => 'أخطاء'`. English twins.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter SectionImportTest` then `php artisan test`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: section import screens with preview/confirm and section list"
```

---

### Task 11: `AssignmentService` — assign, unassign, recompute hours, suggestions; routes

**Files:**
- Create: `app/Services/Sections/AssignmentService.php`, `app/Http/Controllers/Admin/AssignmentController.php` (store/destroy only; index in Task 12), `tests/Feature/Admin/AssignmentTest.php`
- Modify: `app/Services/Sections/SectionImporter.php` (call `AssignmentService::recomputeHours()` instead of the private helper), `routes/web.php`, `lang/ar/app.php`, `lang/en/app.php`

**Interfaces:**
- Produces: `AssignmentService::assign(Section, Application, User $admin): Assignment` (throws `\DomainException` unless application `approved`, same term as the section, term open, section unassigned); `unassign(Section, User $admin): void` (term open); `recomputeHours(Application): void` (sets `weekly_minutes`); `suggestionsFor(Term): array<int section_id, int application_id>` (normalised `scheduled_instructor` == normalised approved instructor `full_name`, only unassigned sections, only when the match is unique); routes `admin.assignments.store` (POST `admin/sections/{section}/assign`, body `application_id`) and `admin.assignments.destroy` (DELETE `admin/sections/{section}/assign`). Audit `assign_section` / `unassign_section` on the Section.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Admin/AssignmentTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\Instructor;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use App\Services\Sections\AssignmentService;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Term $term;

    private Application $application;

    private Section $section;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChecklistItemSeeder::class);
        $this->admin = User::factory()->admin()->create();
        $this->term = Term::factory()->open()->create();
        $instructor = Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'محمد أحمد علي الفهد']);
        $this->application = Application::factory()->approved()->for($this->term)->for($instructor)->create();
        $this->section = Section::factory()->for($this->term)->withMeetings()->create();
    }

    public function test_assign_and_unassign_recompute_hours_and_audit(): void
    {
        $this->actingAs($this->admin)->post(route('admin.assignments.store', $this->section), ['application_id' => $this->application->id])->assertRedirect();

        $this->assertDatabaseHas('assignments', ['section_id' => $this->section->id, 'application_id' => $this->application->id, 'created_by' => $this->admin->id]);
        $f = $this->application->fresh();
        $this->assertSame(250, $f->weekly_minutes);
        $this->assertSame('4.2', $f->weeklyHoursLabel());
        $this->assertDatabaseHas('audit_log', ['action' => 'assign_section', 'subject_id' => $this->section->id]);

        $this->actingAs($this->admin)->delete(route('admin.assignments.destroy', $this->section))->assertRedirect();
        $this->assertDatabaseMissing('assignments', ['section_id' => $this->section->id]);
        $this->assertSame(0, $this->application->fresh()->weekly_minutes);
        $this->assertDatabaseHas('audit_log', ['action' => 'unassign_section', 'subject_id' => $this->section->id]);
    }

    public function test_rules_only_approved_same_term_open_term_unassigned(): void
    {
        $svc = app(AssignmentService::class);

        $draft = Application::factory()->for($this->term)->create();
        try { $svc->assign($this->section, $draft, $this->admin); $this->fail('draft assigned'); } catch (\DomainException) {}

        $otherTerm = Application::factory()->approved()->for(Term::factory()->create(['academic_year' => '2025-2026', 'type' => 'summer', 'status' => 'closed']))->create();
        try { $svc->assign($this->section, $otherTerm, $this->admin); $this->fail('cross-term assigned'); } catch (\DomainException) {}

        $svc->assign($this->section, $this->application, $this->admin);
        $second = Application::factory()->approved()->for($this->term)->create();
        try { $svc->assign($this->section, $second, $this->admin); $this->fail('double assigned'); } catch (\DomainException) {}

        $this->term->update(['status' => 'closed']);
        try { $svc->unassign($this->section->fresh(), $this->admin); $this->fail('unassigned on closed term'); } catch (\DomainException $e) { $this->assertTrue(true); }
    }

    public function test_controller_maps_rule_violations_to_errors(): void
    {
        $draft = Application::factory()->for($this->term)->create();
        $this->actingAs($this->admin)->post(route('admin.assignments.store', $this->section), ['application_id' => $draft->id])->assertSessionHasErrors('assign');
        $this->assertDatabaseCount('assignments', 0);
    }

    public function test_suggestions_match_normalised_names_only_when_unique(): void
    {
        $this->section->update(['scheduled_instructor' => 'محمد احمد علي الفهد ']); // hamza dropped, trailing space
        $noMatch = Section::factory()->for($this->term)->create(['scheduled_instructor' => 'د. فلان الفلاني']);
        $twin = Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'محمد أحمد علي الفهد']);
        $ambiguous = Section::factory()->for($this->term)->create(['scheduled_instructor' => 'محمد أحمد علي الفهد']);
        Application::factory()->approved()->for($this->term)->for($twin)->create();

        $s = app(AssignmentService::class)->suggestionsFor($this->term);

        $this->assertArrayNotHasKey($this->section->id, $s);   // two approved instructors share the name → ambiguous
        $this->assertArrayNotHasKey($noMatch->id, $s);
        $this->assertArrayNotHasKey($ambiguous->id, $s);

        $twin->delete();
        $s = app(AssignmentService::class)->suggestionsFor($this->term);
        $this->assertSame($this->application->id, $s[$this->section->id]);
    }

    public function test_instructor_cannot_assign(): void
    {
        $this->actingAs($this->application->instructor->user)->post(route('admin.assignments.store', $this->section), ['application_id' => $this->application->id])->assertForbidden();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter "Admin\\\\AssignmentTest"`
Expected: FAIL (routes/class missing).

- [ ] **Step 3: Implement**

`app/Services/Sections/AssignmentService.php`:

```php
<?php

namespace App\Services\Sections;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use App\Support\ArabicNameNormaliser;
use Illuminate\Support\Facades\DB;

class AssignmentService
{
    public function assign(Section $section, Application $application, User $admin): Assignment
    {
        if ($application->status !== Application::STATUS_APPROVED) {
            throw new \DomainException(__('app.assignments.not_approved'));
        }
        if ($application->term_id !== $section->term_id) {
            throw new \DomainException(__('app.assignments.other_term'));
        }
        if (! $section->term->isOpen()) {
            throw new \DomainException(__('app.applications.term_closed'));
        }
        if ($section->assignment()->exists()) {
            throw new \DomainException(__('app.assignments.already_assigned'));
        }

        return DB::transaction(function () use ($section, $application, $admin) {
            $a = Assignment::create(['application_id' => $application->id, 'section_id' => $section->id, 'created_by' => $admin->id]);
            $this->recomputeHours($application);
            AuditLog::record($admin->id, 'assign_section', $section);

            return $a;
        });
    }

    public function unassign(Section $section, User $admin): void
    {
        if (! $section->term->isOpen()) {
            throw new \DomainException(__('app.applications.term_closed'));
        }
        $assignment = $section->assignment;
        if (! $assignment) {
            throw new \DomainException(__('app.assignments.not_assigned'));
        }
        DB::transaction(function () use ($assignment, $section, $admin) {
            $application = $assignment->application;
            $assignment->delete();
            $this->recomputeHours($application);
            AuditLog::record($admin->id, 'unassign_section', $section);
        });
    }

    public function recomputeHours(Application $application): void
    {
        $minutes = 0;
        foreach ($application->sections()->with('meetings')->get() as $s) {
            $minutes += $s->weeklyMinutes();
        }
        $application->update(['weekly_minutes' => $minutes]);
    }

    /** @return array<int, int> section_id => application_id (unique name matches on unassigned sections only) */
    public function suggestionsFor(Term $term): array
    {
        $byName = [];
        foreach ($term->applications()->where('status', Application::STATUS_APPROVED)->with('instructor')->get() as $app) {
            $byName[ArabicNameNormaliser::normalise($app->instructor->full_name)][] = $app->id;
        }
        $out = [];
        foreach ($term->sections()->doesntHave('assignment')->whereNotNull('scheduled_instructor')->get() as $section) {
            $ids = $byName[ArabicNameNormaliser::normalise($section->scheduled_instructor)] ?? [];
            if (count($ids) === 1) {
                $out[$section->id] = $ids[0];
            }
        }

        return $out;
    }
}
```

`SectionImporter`: inject `AssignmentService` via the constructor and replace the private `recompute()` calls with `$this->assignments->recomputeHours(...)`; delete the private helper.

`app/Http/Controllers/Admin/AssignmentController.php` (index added in Task 12):

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Section;
use App\Services\Sections\AssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AssignmentController extends Controller
{
    public function __construct(private AssignmentService $service) {}

    public function store(Request $request, Section $section): RedirectResponse
    {
        $data = $request->validate(['application_id' => ['required', 'integer', 'exists:applications,id']]);
        try {
            $this->service->assign($section, Application::findOrFail($data['application_id']), $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['assign' => $e->getMessage()]);
        }

        return back()->with('status', __('app.assignments.assigned'));
    }

    public function destroy(Request $request, Section $section): RedirectResponse
    {
        try {
            $this->service->unassign($section, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['assign' => $e->getMessage()]);
        }

        return back()->with('status', __('app.assignments.unassigned'));
    }
}
```

Routes (admin group):

```php
Route::post('sections/{section}/assign', [AssignmentController::class, 'store'])->name('assignments.store');
Route::delete('sections/{section}/assign', [AssignmentController::class, 'destroy'])->name('assignments.destroy');
```

`lang/ar/app.php` — new group:

```php
'assignments' => [
    'title' => 'إسناد الشعب',
    'not_approved' => 'لا يمكن الإسناد إلا لطلب معتمد.',
    'other_term' => 'الطلب والشعبة في فصلين مختلفين.',
    'already_assigned' => 'الشعبة مسندة بالفعل لمنتدب آخر.',
    'not_assigned' => 'الشعبة غير مسندة.',
    'assigned' => 'تم إسناد الشعبة.',
    'unassigned' => 'تم إلغاء الإسناد.',
    'assign' => 'إسناد', 'unassign' => 'إلغاء الإسناد',
    'choose' => 'اختر المنتدب', 'suggested' => 'مقترح من الجدول',
    'weekly_hours' => 'الساعات الأسبوعية', 'my_sections' => 'الشعب المسندة', 'none' => 'لا توجد شعب مسندة بعد.',
    'totals' => 'إجمالي كل منتدب', 'no_approved' => 'لا يوجد منتدبون معتمدون في هذا الفصل.',
],
```

English twins.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter "AssignmentTest|SectionImportServiceTest"` then `php artisan test`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: assignment service with rules, hour recompute and name suggestions"
```

---

### Task 12: Assignment screens, application card, instructor view, dashboard alerts

**Files:**
- Create: `resources/views/admin/assignments/index.blade.php`, `tests/Feature/Admin/AssignmentScreensTest.php`
- Modify: `app/Http/Controllers/Admin/AssignmentController.php` (add `index`), `app/Http/Controllers/Admin/ApplicationController.php` (`show` passes `sections`), `app/Http/Controllers/Admin/DashboardController.php` (`$alerts`), `app/Http/Controllers/Instructor/ApplicationController.php` (`home` passes `assigned`), `resources/views/admin/applications/show.blade.php`, `resources/views/instructor/home.blade.php`, `resources/views/admin/layout.blade.php`, `routes/web.php`, `lang/ar/app.php`, `lang/en/app.php`

**Interfaces:**
- Consumes: `AssignmentService::suggestionsFor()`, `Section::meetingSummary()`, `weeklyMinutesByType()`, `Application::sections()`, `weeklyHoursLabel()`.
- Produces: route `admin.assignments.index` (GET `admin/assignments`, optional `?term=`); dashboard `$alerts` items `['text' => …, 'url' => …]` for approved applications on the open term with no assignments and for sections with `missing_since_import`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Admin/AssignmentScreensTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Instructor;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignmentScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Term $term;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChecklistItemSeeder::class);
        $this->admin = User::factory()->admin()->create();
        $this->term = Term::factory()->open()->create();
        $instructor = Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'محمد أحمد علي الفهد']);
        $this->application = Application::factory()->approved()->for($this->term)->for($instructor)->create();
    }

    public function test_assignments_index_shows_suggestion_and_dropdown(): void
    {
        $s = Section::factory()->for($this->term)->withMeetings()->create(['scheduled_instructor' => 'محمد احمد علي الفهد']);

        $r = $this->actingAs($this->admin)->get(route('admin.assignments.index'))->assertOk();
        $r->assertSee(__('app.assignments.suggested'));
        $r->assertSee('<option value="'.$this->application->id.'" selected', false);
        $r->assertSee($s->course_code);
    }

    public function test_application_show_and_instructor_home_list_sections(): void
    {
        $s = Section::factory()->for($this->term)->withMeetings()->create();
        Assignment::create(['application_id' => $this->application->id, 'section_id' => $s->id]);
        app(\App\Services\Sections\AssignmentService::class)->recomputeHours($this->application);

        $this->actingAs($this->admin)->get(route('admin.applications.show', $this->application))->assertOk()
            ->assertSee(__('app.assignments.my_sections'))->assertSee($s->course_code)->assertSee('4.2');

        $this->actingAs($this->application->instructor->user)->get(route('instructor.home'))->assertOk()
            ->assertSee($s->course_code)->assertSee('4.2')->assertDontSee(__('app.assignments.unassign'));
    }

    public function test_dashboard_alerts_for_unassigned_approved_and_missing_sections(): void
    {
        Section::factory()->for($this->term)->create(['missing_since_import' => true, 'course_code' => '7299999']);

        $r = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();
        $r->assertSee(__('app.review.alert_unassigned', ['name' => 'محمد أحمد علي الفهد']));
        $r->assertSee(__('app.review.alert_missing_section', ['section' => '7299999 / 1']));
    }

    public function test_instructor_cannot_open_assignments_index(): void
    {
        $this->actingAs($this->application->instructor->user)->get(route('admin.assignments.index'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter AssignmentScreensTest`
Expected: FAIL.

- [ ] **Step 3: Implement**

`AssignmentController::index()`:

```php
public function index(Request $request): View
{
    $term = $request->filled('term') ? Term::findOrFail($request->term) : Term::current();
    if (! $term) {
        return view('admin.assignments.index', ['term' => null, 'sections' => collect(), 'approved' => collect(), 'suggestions' => [], 'totals' => collect(), 'terms' => Term::orderByDesc('teaching_starts_on')->get()]);
    }
    $sections = $term->sections()->with(['meetings', 'assignment.application.instructor'])->orderBy('course_code')->orderBy('section_number')->get();
    $approved = $term->applications()->where('status', Application::STATUS_APPROVED)->with('instructor')->get()->sortBy(fn ($a) => $a->instructor->full_name);
    $totals = $approved->map(fn ($a) => ['name' => $a->instructor->full_name, 'hours' => $a->weeklyHoursLabel(), 'count' => $a->assignments()->count()]);

    return view('admin.assignments.index', [
        'term' => $term, 'sections' => $sections, 'approved' => $approved,
        'suggestions' => $this->service->suggestionsFor($term), 'totals' => $totals,
        'terms' => Term::orderByDesc('teaching_starts_on')->get(),
    ]);
}
```

Route: `Route::get('assignments', [AssignmentController::class, 'index'])->name('assignments.index');`

`resources/views/admin/assignments/index.blade.php` — extends `admin.layout`. Term selector (GET). If `$approved` empty → alert `app.assignments.no_approved`. Two columns (`col-lg-9` / `col-lg-3`):
- Table rows per section: course / section / name, `meetingSummary()`, hours theory/practical/field, scheduled instructor, then the assignment cell: if assigned → assignee name + a DELETE form (`@method('DELETE')`, button `app.assignments.unassign`, `onsubmit="return confirm(@js(__('app.assignments.unassign_confirm')))"`); else a POST form to `admin.assignments.store` with `<select name="application_id">` whose first option is `app.assignments.choose` and each approved application an `<option value="{id}" @selected(($suggestions[$section->id] ?? null) === $id)>`, a `badge bg-info` `app.assignments.suggested` when a suggestion exists, and button `app.assignments.assign`. `badge bg-danger app.sections.missing_badge` when flagged. Forms only when `$term->isOpen()`.
- Side panel: `app.assignments.totals` list from `$totals` (name, count, hours).

`Admin/ApplicationController::show()` — add `'sections' => $application->sections()->with('meetings')->get(),`. In `show.blade.php`, after the checklist section, add a card `app.assignments.my_sections`: table of sections (label, `meetingSummary()`, hours by type) with a per-row DELETE unassign form when `$termOpen && $application->status === approved`; footer `app.assignments.weekly_hours`: `$application->weeklyHoursLabel()`; empty state `app.assignments.none`; link to `admin.assignments.index` when approved.

`Instructor/ApplicationController::home()` — add `'assigned' => $current?->sections()->with('meetings')->get() ?? collect(),`. In `instructor/home.blade.php`, when `$current && $current->status === approved`, a read-only card `app.assignments.my_sections` with the same columns and the weekly total; no forms.

`DashboardController` — replace `$alerts = collect();` with:

```php
$alerts = collect();
if ($term) {
    foreach ($term->applications()->where('status', Application::STATUS_APPROVED)->doesntHave('assignments')->with('instructor')->get() as $a) {
        $alerts->push(['text' => __('app.review.alert_unassigned', ['name' => $a->instructor->full_name]), 'url' => route('admin.assignments.index', ['term' => $term->id])]);
    }
    foreach ($term->sections()->where('missing_since_import', true)->get() as $s) {
        $alerts->push(['text' => __('app.review.alert_missing_section', ['section' => $s->course_code.' / '.$s->section_number]), 'url' => route('admin.sections.index', ['term' => $term->id])]);
    }
}
```

`admin/layout.blade.php` nav: add `admin.assignments.index` (`app.assignments.title`).

Lang: `review.alert_unassigned` 'المنتدب :name معتمد ولم تسند له أي شعبة.', `review.alert_missing_section` 'الشعبة :section مسندة لكنها غير موجودة في آخر استيراد.', `assignments.unassign_confirm` 'إلغاء إسناد هذه الشعبة؟'. English twins.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter AssignmentScreensTest` then `php artisan test`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: assignment screen with suggestions, sections on application and instructor pages, dashboard alerts"
```

---

### Task 13: Docs, runbook and cleanup

**Files:**
- Modify: `deploy/DEPLOY.md` (add "import the term's jadawil export" to routine term setup; note the `phpoffice/phpspreadsheet` dependency comes via composer on deploy), `PROGRESS.md`, `CLAUDE.md` (status: milestone 2 implemented on branch; statuses list; new screens), `docs/superpowers/specs/2026-09-29-milestone-2-assignment-design.md` (one line under §4.2 noting PhpSpreadsheet is used directly instead of maatwebsite/excel)
- Verify: lang parity (`php -r` comparing key sets of `lang/ar/app.php` and `lang/en/app.php` recursively prints no difference), `php artisan test` green, `bash -n deploy/deploy.sh`.

- [ ] **Step 1: Lang parity check**

```bash
php -r '$a=include "lang/ar/app.php";$e=include "lang/en/app.php";function k($x,$p=""){$o=[];foreach($x as $i=>$v){$o=array_merge($o,is_array($v)?k($v,"$p$i."):["$p$i"]);}return $o;}$d=array_merge(array_diff(k($a),k($e)),array_diff(k($e),k($a)));echo $d?implode("\n",$d)."\n":"OK\n";'
```

Expected: `OK`. Fix any missing twin before continuing.

- [ ] **Step 2: Update docs**

`PROGRESS.md`: status → "Milestone 2 (committee workflow, sections import, assignments) implemented on branch `milestone-2-assignment`; tests green; deploy pending". Log line with today's date. Next: review + merge, deploy, milestone 3 (monthly attestation) brainstorm.

`CLAUDE.md`: update the status header; list the status pipeline with `complete`; mention the import source (jadawil export) and the `admin/sections`, `admin/assignments` screens.

`deploy/DEPLOY.md`: in the routine per-term section add "Export the term's timetable from jadawil (CSV or XLSX) and import it at `/admin/sections/import`; re-import whenever the timetable changes — assigned sections are never deleted automatically."

- [ ] **Step 3: Full verification**

Run: `php artisan test` and `bash -n deploy/deploy.sh`
Expected: all green, no syntax errors.

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "docs: milestone 2 status, runbook import step, spec note on PhpSpreadsheet"
```

---

## Self-review notes

- **Spec coverage:** §3.1 status + attention list → Task 1; §3.2 committee decision → Task 2; §3.3 attention groups → Tasks 1 and 12 (alerts); §3.4 carry-overs → Tasks 5 (profile edit), 4 (reopen), 3 (consolidated email); §4.1 model → Task 6; §4.2 format + parser → Tasks 7–8; §4.3 import flow → Tasks 9–10; §4.4 section list → Task 10; §5.1–5.3 assignments, rules, suggestions, screens → Tasks 11–12; §6 security → every task's 403 tests + policy `review` checks; §7 tests → per task; §8 delivery → task order; runbook → Task 13.
- **Deviation from spec wording:** XLSX read with `phpoffice/phpspreadsheet` instead of `maatwebsite/excel` (Task 8; noted in Task 13's spec edit). Weekly load is stored only as `weekly_minutes` (the M1 integer `weekly_hours` is dropped; hours are derived for display, final-review fix); milestone 3 uses `weekly_minutes`.
- **Placeholders:** none.
- **Type consistency:** `ParsedTimetable::sections` keyed `code|section` (Tasks 7, 9, 10); `ImportPlan` arrays hold the same keys; `AssignmentService::recomputeHours()` used by Tasks 9 (after 11), 11, 12; `weeklyHoursLabel()` from Task 6 used in 11, 12; `Application::factory()->approved()` and `->complete()` defined in Task 1 and used from Task 2 on; route names consistent between controllers, views and tests.
- **Review Focus pins:** 1 → Task 7 `test_duplicate_meeting_rows_are_collapsed_with_a_warning`; 2 → Task 9 `test_reimport_updates_meetings_deletes_unassigned_and_flags_assigned`; 3 → Task 2 `test_second_submission_is_refused_and_sends_no_second_mail`; 4 → Task 7 normaliser test + Task 11 `test_suggestions_match_normalised_names_only_when_unique` + Task 12 index test; 5 → Task 7 `test_unknown_day_is_an_error_with_row_number_and_blocks` + `test_unknown_activity_maps_to_theory_with_warning_and_empty_day_is_error`.
- **Known M1 test collisions:** `ReviewTest` edits are listed in Tasks 2 and 3; the `private Application $app` naming issue from M1 is avoided (all new tests use `$application`).

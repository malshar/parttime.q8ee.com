# Milestone 6 — Year Approval, Continuation, Renewal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the committee approval a yearly object, let later terms of the same year run as continuations with per-term papers only, add the batch renewal for the next year with the names list and the academic bundle, and give the department a term-end page.

**Architecture:** New `committee_approvals` table (one row per instructor per academic year); `applications.kind` (initial|continuation) and `approval_id`. `ApplicationWorkflow::start()` picks the kind; a new `gateRows()` makes the submit and complete gates read the continuation's required rows (renewing stage-2 items plus unsatisfied stage-1 rows); `markComplete()` approves a continuation directly. Renewal is one transaction creating approval rows and first-term drafts. The bundle and the names list are PhpWord/ZipArchive documents streamed and deleted after send, like the Check List.

**Tech Stack:** Laravel 12, PHP 8.4, MySQL / SQLite (tests), PHPUnit, Blade + Bootstrap 5.3 RTL, PhpWord, ZipArchive.

**Spec:** `docs/superpowers/specs/2026-10-03-milestone-6-year-approval-design.md`

## Global Constraints

- Formal, undiacritized Arabic (no tashkeel) in every `ar` string; every new `ar` key has an `en` twin at the same path; no hard-coded UI strings.
- Sensitive fields (`civil_id`, `iban`, `basic_salary`, `total_salary`) never in logs, audit details, flashes or error messages; audit details = codes, years or field names only. The renewal list and the bundle carry civil IDs by design and are admin-only and audited; they never carry salary or IBAN.
- No new application status. `kind` is set once in `start()` or by the renewal and never changed.
- Approval rows are never updated; a renewal row may be deleted only while `kind = renewal` and its application (if any) is `draft`.
- `php artisan test --compact` green after every task; Pint only on touched PHP files, never on `lang/`.
- Never read, copy or reference `../part-time/`.
- Commit after each task with the given message, ending with `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.
- Sweep iCloud duplicates before tests: `find . -path ./vendor -prune -o -name '* 2*' -print` must print nothing.

## Review Focus

1. A continuation must never reach the committee form or `committeeDecision()`, and `markComplete()` on it must end in `approved` with `approval_id` set — Task 2 tests.
2. A continuation must not ask for degree, transcripts or equivalency again, but must ask for a new civil ID when the card expired — Task 2 test `test_continuation_requires_civil_id_when_expired`.
3. The renewal batch must refuse a double submit (unique key) without partial writes, and never create a draft for a "not renewed" row — Task 3 tests.
4. The bundle must never include salary or IBAN and must be deleted after send — Task 4 tests.
5. `start()` after a renewal must reuse the draft the batch created, not create a second application — Task 3 test `test_start_reuses_the_renewal_draft`.

---

### Task 1: Approvals schema, models, decision hook

**Files:**
- Create: `database/migrations/2026_10_04_100000_create_committee_approvals_table.php`, `database/migrations/2026_10_04_100001_add_kind_and_approval_to_applications_table.php`
- Create: `app/Models/CommitteeApproval.php`, `database/factories/CommitteeApprovalFactory.php`
- Modify: `app/Models/Application.php`, `app/Models/Instructor.php`, `app/Services/ApplicationWorkflow.php` (`committeeDecision`), `database/factories/ApplicationFactory.php`
- Test: `tests/Feature/YearApprovalTest.php`

**Interfaces:**
- Produces: `CommitteeApproval` (`KIND_INITIAL='initial'`, `KIND_RENEWAL='renewal'`, `OUTCOME_APPROVED='approved'`, `OUTCOME_NOT_RENEWED='not_renewed'`, `isApproved()`, relations `instructor`, `decider`, `applications`); `Instructor::approvals()`, `approvalFor(string $year): ?CommitteeApproval`, `hasApprovalFor(string $year): bool`; `Application::KIND_INITIAL/KIND_CONTINUATION`, `isContinuation()`, `approval()`; `ApplicationFactory::continuation()`; `committeeDecision('approved')` creates the row and sets `approval_id`.

- [ ] **Step 1: Failing test**

`tests/Feature/YearApprovalTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\CommitteeApproval;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use App\Services\ApplicationWorkflow;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class YearApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(ChecklistItemSeeder::class);
    }

    public function test_committee_approval_creates_the_year_row_and_links_the_application(): void
    {
        $admin = User::factory()->admin()->create();
        $app = Application::factory()->complete()->for(Term::factory()->open())->create();
        app(ApplicationWorkflow::class)->committeeDecision($app, $admin, 'approved', '2026-10-01', 'ق/7', 'ملاحظة');

        $row = CommitteeApproval::where('instructor_id', $app->instructor_id)->firstOrFail();
        $this->assertSame('2026-2027', $row->academic_year);
        $this->assertSame(CommitteeApproval::KIND_INITIAL, $row->kind);
        $this->assertSame(CommitteeApproval::OUTCOME_APPROVED, $row->outcome);
        $this->assertSame('ق/7', $row->committee_reference);
        $this->assertSame('ملاحظة', $row->note);
        $this->assertSame($admin->id, $row->decided_by);
        $this->assertSame($row->id, $app->fresh()->approval_id);
        $this->assertTrue($app->instructor->hasApprovalFor('2026-2027'));
        $this->assertFalse($app->instructor->hasApprovalFor('2027-2028'));
    }

    public function test_rejection_creates_no_row(): void
    {
        $app = Application::factory()->complete()->for(Term::factory()->open())->create();
        app(ApplicationWorkflow::class)->committeeDecision($app, User::factory()->admin()->create(), 'rejected', '2026-10-01', 'ق/8', 'غير مستوف');
        $this->assertDatabaseCount('committee_approvals', 0);
        $this->assertFalse($app->instructor->hasApprovalFor('2026-2027'));
    }

    public function test_not_renewed_row_is_not_an_approval(): void
    {
        $instructor = Instructor::factory()->for(User::factory()->instructor())->create();
        CommitteeApproval::factory()->for($instructor)->renewal()->notRenewed()->create(['academic_year' => '2027-2028']);
        $this->assertFalse($instructor->hasApprovalFor('2027-2028'));
        $this->assertNotNull($instructor->approvalFor('2027-2028'));
    }

    public function test_one_row_per_instructor_and_year(): void
    {
        $instructor = Instructor::factory()->for(User::factory()->instructor())->create();
        CommitteeApproval::factory()->for($instructor)->create(['academic_year' => '2026-2027']);
        $this->expectException(\Illuminate\Database\QueryException::class);
        CommitteeApproval::factory()->for($instructor)->create(['academic_year' => '2026-2027']);
    }
}
```

- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/YearApprovalTest.php` → FAIL (table missing).

- [ ] **Step 3: Migrations**

`2026_10_04_100000_create_committee_approvals_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('committee_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instructor_id')->constrained()->cascadeOnDelete();
            $table->string('academic_year', 9);
            $table->string('kind', 12);        // initial|renewal
            $table->string('outcome', 12);     // approved|not_renewed
            $table->date('committee_met_on');
            $table->string('committee_reference', 60);
            $table->string('note', 500)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['instructor_id', 'academic_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('committee_approvals');
    }
};
```

`2026_10_04_100001_add_kind_and_approval_to_applications_table.php`:

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
            $table->string('kind', 12)->default('initial')->after('status');   // initial|continuation
            $table->foreignId('approval_id')->nullable()->after('kind')->constrained('committee_approvals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approval_id');
            $table->dropColumn('kind');
        });
    }
};
```

- [ ] **Step 4: Models and factory**

`app/Models/CommitteeApproval.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The committee's decision for one instructor and one academic year (spec M6 §3.1). Never updated. */
class CommitteeApproval extends Model
{
    use HasFactory;

    public const KIND_INITIAL = 'initial';

    public const KIND_RENEWAL = 'renewal';

    public const OUTCOME_APPROVED = 'approved';

    public const OUTCOME_NOT_RENEWED = 'not_renewed';

    protected $fillable = ['instructor_id', 'academic_year', 'kind', 'outcome', 'committee_met_on', 'committee_reference', 'note', 'decided_by'];

    protected function casts(): array
    {
        return ['committee_met_on' => 'date'];
    }

    public function isApproved(): bool
    {
        return $this->outcome === self::OUTCOME_APPROVED;
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class, 'approval_id');
    }

    /** "2026-2027" → "2025-2026". */
    public static function previousYear(string $academicYear): string
    {
        $start = (int) substr($academicYear, 0, 4);

        return ($start - 1).'-'.$start;
    }

    /** "2026-2027" → "2027-2028". */
    public static function nextYear(string $academicYear): string
    {
        $start = (int) substr($academicYear, 0, 4);

        return ($start + 1).'-'.($start + 2);
    }
}
```

`database/factories/CommitteeApprovalFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\CommitteeApproval;
use App\Models\Instructor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CommitteeApprovalFactory extends Factory
{
    protected $model = CommitteeApproval::class;

    public function definition(): array
    {
        return [
            'instructor_id' => Instructor::factory()->for(User::factory()->instructor()),
            'academic_year' => '2026-2027', 'kind' => CommitteeApproval::KIND_INITIAL,
            'outcome' => CommitteeApproval::OUTCOME_APPROVED,
            'committee_met_on' => '2026-10-01', 'committee_reference' => 'ق/1',
        ];
    }

    public function renewal(): static
    {
        return $this->state(fn () => ['kind' => CommitteeApproval::KIND_RENEWAL]);
    }

    public function notRenewed(): static
    {
        return $this->state(fn () => ['outcome' => CommitteeApproval::OUTCOME_NOT_RENEWED]);
    }
}
```

`app/Models/Instructor.php` — add:

```php
    public function approvals(): HasMany
    {
        return $this->hasMany(CommitteeApproval::class);
    }

    public function approvalFor(string $academicYear): ?CommitteeApproval
    {
        return $this->approvals()->where('academic_year', $academicYear)->first();
    }

    /** Spec M6 §4.1: an approved row for the year (initial or renewal). */
    public function hasApprovalFor(string $academicYear): bool
    {
        return $this->approvals()->where('academic_year', $academicYear)->where('outcome', CommitteeApproval::OUTCOME_APPROVED)->exists();
    }
```

`app/Models/Application.php` — add `'kind', 'approval_id'` to `$fillable`, constants `KIND_INITIAL = 'initial'`, `KIND_CONTINUATION = 'continuation'`, `isContinuation(): bool { return $this->kind === self::KIND_CONTINUATION; }`, relation `approval(): BelongsTo { return $this->belongsTo(CommitteeApproval::class, 'approval_id'); }`.

`ApplicationFactory` — add `continuation(): static` setting `'kind' => Application::KIND_CONTINUATION`.

- [ ] **Step 5: Decision hook**

In `ApplicationWorkflow::committeeDecision()`, wrap the update in `DB::transaction` and, when approved, create the row and set `approval_id`:

```php
        DB::transaction(function () use ($application, $admin, $outcome, $metOn, $reference, $note, $approved) {
            $application->update([
                'status' => $approved ? Application::STATUS_APPROVED : Application::STATUS_REJECTED,
                'decided_at' => now(), 'committee_outcome' => $outcome, 'committee_met_on' => $metOn,
                'committee_reference' => $reference, 'committee_note' => $note,
                'rejection_reason' => $approved ? null : $note,
            ]);
            if ($approved) {
                $approval = CommitteeApproval::create([
                    'instructor_id' => $application->instructor_id, 'academic_year' => $application->term->academic_year,
                    'kind' => CommitteeApproval::KIND_INITIAL, 'outcome' => CommitteeApproval::OUTCOME_APPROVED,
                    'committee_met_on' => $metOn, 'committee_reference' => $reference, 'note' => $note, 'decided_by' => $admin->id,
                ]);
                $application->update(['approval_id' => $approval->id]);
            }
            AuditLog::record($admin->id, 'committee_decision', $application);
        });
```

(the mail stays after the transaction; add `use App\Models\CommitteeApproval;`). Guard: if a row for the year already exists (`hasApprovalFor` true, e.g. a continuation that was wrongly sent to the committee), throw `app.review.committee_not_needed` before updating — Task 2 adds the continuation refusal, this guard covers the data-level case.

Lang (ar, `review`): `'committee_not_needed' => 'هذا الطلب استمرار لاعتماد قائم ولا يحتاج إلى قرار من اللجنة.'`; en: `'This application continues an existing approval and needs no committee decision.'`.

- [ ] **Step 6: Suite, Pint, commit**

```bash
git add -A
git commit -m "feat(approvals): committee_approvals table, application kind and approval link, decision creates the year row

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 2: Continuation applications

**Files:**
- Modify: `app/Services/ApplicationWorkflow.php` (`start`, `gateRows`, gates, `markComplete`, `committeeDecision`, `requiredMissing`), `app/Policies/ApplicationPolicy.php` (`requestExemption`), `app/Http/Controllers/Instructor/ApplicationController.php`, `app/Http/Controllers/Admin/ApplicationController.php`, `resources/views/instructor/home.blade.php`, `resources/views/instructor/application.blade.php`, `resources/views/instructor/_checklist_table.blade.php`, `resources/views/admin/applications/show.blade.php`, `lang/*`
- Create: `app/Mail/ContinuationApproved.php`, `resources/views/emails/continuation-approved.blade.php`
- Test: `tests/Feature/ContinuationTest.php`

**Interfaces:**
- Produces: `ApplicationWorkflow::gateRows(Application): array` (private), `requiredMissing(Application): array<int,string>` (public, labels of required rows not satisfied, any status), `start()` sets kind; `markComplete()` approves continuations; `committeeDecision()` refuses them; view variables `isContinuation`, `nextIsContinuation` (home).

- [ ] **Step 1: Failing tests**

`tests/Feature/ContinuationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Mail\ContinuationApproved;
use App\Models\Application;
use App\Models\CommitteeApproval;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use App\Services\ApplicationWorkflow;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContinuationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Instructor $instructor;

    private Term $first;

    private Term $second;

    private Application $initial;

    private ApplicationWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['mail.admin_notify' => 'admin@example.com']);
        $this->seed(ChecklistItemSeeder::class);
        $this->user = User::factory()->instructor()->create();
        $this->instructor = Instructor::factory()->for($this->user)->create(['basic_salary' => '900', 'total_salary' => '1200']);
        $this->first = Term::factory()->create(['academic_year' => '2026-2027', 'type' => 'first', 'teaching_starts_on' => '2026-09-13', 'teaching_ends_on' => '2026-12-24', 'status' => 'closed']);
        $this->second = Term::factory()->open()->create(['academic_year' => '2026-2027', 'type' => 'second', 'teaching_starts_on' => '2027-02-07', 'teaching_ends_on' => '2027-05-27']);
        $this->initial = Application::factory()->approved()->for($this->first)->for($this->instructor)->create();
        foreach (['civil_id', 'degree', 'transcript_bachelor', 'transcript_master', 'salary_cert', 'iban', 'employer_approval', 'undertaking'] as $code) {
            Document::factory()->for($this->initial)->forItem($code)->accepted()->create();
        }
        $approval = CommitteeApproval::factory()->for($this->instructor)->create(['academic_year' => '2026-2027']);
        $this->initial->update(['approval_id' => $approval->id]);
        $this->workflow = app(ApplicationWorkflow::class);
    }

    public function test_start_makes_a_continuation_when_the_year_is_approved(): void
    {
        $app = $this->workflow->start($this->instructor, $this->second);
        $this->assertSame(Application::KIND_CONTINUATION, $app->kind);
        $this->assertSame($this->instructor->approvalFor('2026-2027')->id, $app->approval_id);
    }

    public function test_start_is_initial_without_an_approval_or_in_another_year(): void
    {
        $other = Instructor::factory()->for(User::factory()->instructor())->create();
        $this->assertSame(Application::KIND_INITIAL, $this->workflow->start($other, $this->second)->kind);

        $next = Term::factory()->open()->create(['academic_year' => '2027-2028', 'type' => 'first', 'teaching_starts_on' => '2027-09-12', 'teaching_ends_on' => '2027-12-23']);
        $this->assertSame(Application::KIND_INITIAL, $this->workflow->start($this->instructor, $next)->kind);
    }

    public function test_continuation_requires_only_renewing_papers(): void
    {
        $app = $this->workflow->start($this->instructor, $this->second);
        $rows = $this->workflow->checklist($app);
        $this->assertSame(ApplicationWorkflow::STATE_ON_FILE, $rows['degree']['state']);
        $this->assertSame(ApplicationWorkflow::STATE_ON_FILE, $rows['iban']['state']);
        $this->assertSame(['salary_cert', 'employer_approval', 'undertaking'], array_values(array_map(fn ($l) => $l, array_keys(array_filter($rows, fn ($r) => $r['state'] === 'missing')))));
        $this->assertSame(['شهادة راتب حديثة', 'موافقة جهة العمل', 'نموذج إقرار وتعهد'], $this->workflow->requiredMissing($app));
        $this->assertFalse($this->workflow->allRequiredUploaded($app));
        foreach (['salary_cert', 'employer_approval', 'undertaking'] as $code) {
            Document::factory()->for($app)->forItem($code)->create();
        }
        $this->assertTrue($this->workflow->allRequiredUploaded($app->fresh()));
    }

    public function test_continuation_requires_civil_id_when_expired(): void
    {
        $this->instructor->update(['civil_id_expires_on' => now()->subDay()->toDateString()]);
        $app = $this->workflow->start($this->instructor->fresh(), $this->second);
        $this->assertSame('missing', $this->workflow->checklist($app)['civil_id']['state']);
        $this->assertContains('صورة البطاقة المدنية سارية المفعول', $this->workflow->requiredMissing($app));
    }

    public function test_file_complete_approves_a_continuation_directly(): void
    {
        $app = $this->workflow->start($this->instructor, $this->second);
        foreach (['salary_cert', 'employer_approval', 'undertaking'] as $code) {
            Document::factory()->for($app)->forItem($code)->accepted()->create();
        }
        $app->update(['status' => Application::STATUS_UNDER_REVIEW]);
        $admin = User::factory()->admin()->create();
        $this->workflow->markComplete($app->fresh(), $admin);

        $fresh = $app->fresh();
        $this->assertSame(Application::STATUS_APPROVED, $fresh->status);
        $this->assertNotNull($fresh->decided_at);
        $this->assertNull($fresh->committee_outcome);
        $this->assertSame($this->instructor->approvalFor('2026-2027')->id, $fresh->approval_id);
        $this->assertDatabaseHas('audit_log', ['action' => 'approve_continuation', 'subject_id' => $app->id]);
        Mail::assertSent(ContinuationApproved::class);
        $this->assertTrue($this->workflow->stageTwoComplete($fresh));
    }

    public function test_committee_decision_and_exemptions_refused_on_continuation(): void
    {
        $app = $this->workflow->start($this->instructor, $this->second);
        $app->update(['status' => Application::STATUS_COMPLETE]);
        try {
            $this->workflow->committeeDecision($app->fresh(), User::factory()->admin()->create(), 'approved', '2027-02-01', 'ق/9', null);
            $this->fail('expected refusal');
        } catch (\DomainException $e) {
            $this->assertSame(__('app.review.committee_not_needed'), $e->getMessage());
        }
        $app->update(['status' => Application::STATUS_DRAFT]);
        $this->actingAs($this->user)->post(route('instructor.exemptions.store', [$app, 'transcript_bachelor']), ['reason' => 'x'])->assertForbidden();
    }

    public function test_pages_show_continuation_wording_and_hide_academic_uploads(): void
    {
        $this->actingAs($this->user)->get(route('instructor.home'))->assertOk()->assertSee(__('app.applications.start_continuation'));
        $app = $this->workflow->start($this->instructor, $this->second);
        $r = $this->actingAs($this->user)->get(route('instructor.applications.show', $app))->assertOk();
        $r->assertSee(__('app.applications.continuation_title'))->assertSee(__('app.applications.academic_on_file'))->assertSee(__('app.applications.term_papers'));
        $r->assertDontSee(route('instructor.documents.store', [$app, 'degree']));
        $r->assertSee(route('instructor.documents.store', [$app, 'salary_cert']));
        $r->assertDontSee(__('app.exemptions.request'));

        $app->update(['status' => Application::STATUS_UNDER_REVIEW]);
        $admin = User::factory()->admin()->create();
        $r = $this->actingAs($admin)->get(route('admin.applications.show', $app))->assertOk();
        $r->assertSee(__('app.applications.kinds.continuation'))->assertDontSee(__('app.review.committee_save'));
    }
}
```

- [ ] **Step 2: Run** → FAIL (`kind` stays initial, routes/keys missing).

- [ ] **Step 3: Workflow**

`start()`:

```php
    public function start(Instructor $instructor, Term $term): Application
    {
        if (! $term->isOpen()) {
            throw new TermClosedException;
        }
        $approval = $instructor->approvalFor($term->academic_year);
        $continuation = $approval?->isApproved() === true;

        return Application::firstOrCreate(
            ['term_id' => $term->id, 'instructor_id' => $instructor->id],
            ['status' => Application::STATUS_DRAFT,
                'kind' => $continuation ? Application::KIND_CONTINUATION : Application::KIND_INITIAL,
                'approval_id' => $continuation ? $approval->id : null],
        );
    }
```

Gate rows:

```php
    /**
     * Rows the submit and complete gates read (spec M6 §4.3): stage-1 required rows for an initial
     * application; for a continuation the renewing stage-2 items plus any stage-1 row not satisfied.
     */
    private function gateRows(Application $application): array
    {
        $rows = $this->checklist($application);
        if (! $application->isContinuation()) {
            return array_filter($rows, fn ($row) => $row['stage'] === ChecklistItem::STAGE_COMMITTEE && ! $row['optional']);
        }

        return array_filter($rows, fn ($row) => ! $row['optional'] && (
            ($row['stage'] === ChecklistItem::STAGE_AFTER_APPROVAL && $row['item']->renews_each_term)
            || ($row['stage'] === ChecklistItem::STAGE_COMMITTEE && ! in_array($row['state'], self::SATISFIED_STATES, true))
        ));
    }

    /** Labels of the gate rows not yet satisfied, in checklist order (any status). */
    public function requiredMissing(Application $application): array
    {
        $out = [];
        foreach ($this->gateRows($application) as $row) {
            if (! in_array($row['state'], self::SATISFIED_STATES, true)) {
                $out[] = $row['item']->label_ar;
            }
        }

        return $out;
    }
```

Replace every `$this->stageOneRows($application)` in `allRequiredAccepted`, `allRequiredUploaded`, `hasUndecidedExemptions`, `completeBlockMessage`, `markComplete` with `$this->gateRows($application)` (delete `stageOneRows()` if nothing else uses it). `stageTwoRows()` stays (stage-2 readiness after approval).

`markComplete()` — after the rows check:

```php
        if ($application->isContinuation()) {
            $application->update(['status' => Application::STATUS_APPROVED, 'decided_at' => now(), 'complete_at' => now(),
                'approval_id' => $application->approval_id ?? $application->instructor->approvalFor($application->term->academic_year)?->id]);
            AuditLog::record($admin->id, 'approve_continuation', $application);
            $this->safeSend($application->instructor->user->email, new ContinuationApproved($application));

            return;
        }
```

`committeeDecision()` — first guard: `if ($application->isContinuation() || $application->instructor->hasApprovalFor($application->term->academic_year)) throw new \DomainException(__('app.review.committee_not_needed'));` (keep Task 1's data-level guard folded into this one).

- [ ] **Step 4: Policy, controllers, views**

`ApplicationPolicy::requestExemption`: add `&& ! $application->isContinuation()`.

`Instructor\ApplicationController::home`: add `'nextIsContinuation' => $term && ! $current && $instructor->hasApprovalFor($term->academic_year)`; `show`: add `'isContinuation' => $application->isContinuation()`, `'requiredMissing' => $this->workflow->requiredMissing($application)`. `Admin\ApplicationController::show`: add `'isContinuation'`, `'requiredMissing'`.

`home.blade.php` start button: `{{ $nextIsContinuation ? __('app.applications.start_continuation') : __('app.applications.start') }}`.

`instructor/application.blade.php`: title `{{ $isContinuation ? __('app.applications.continuation_title').' — '.$application->term->label() : $application->term->label() }}`; stage headings become `{{ $isContinuation ? __('app.applications.academic_on_file') : __('app.applications.stage1_title') }}` and `{{ $isContinuation ? __('app.applications.term_papers') : __('app.applications.stage2_title') }}`; for a continuation the stage-1 table gets `uploads => $application->isEditable()` but the partial hides upload and exemption controls on satisfied rows (`hideSatisfied => $isContinuation`), the stage-2 hint is replaced by `app.applications.term_papers_hint`, and the stage-2 table gets `uploads => $application->isEditable() || $application->acceptsStageTwoUploads()`; when the application is editable and `requiredMissing` is not empty, show `app.applications.still_required` + the list.

`instructor/_checklist_table.blade.php`: accept `$hideSatisfied ?? false`; render the upload include and the exemption `<details>` only when `! ($hideSatisfied && in_array($row['state'], \App\Services\ApplicationWorkflow::SATISFIED_STATES, true))`, and never the exemption form when `$application->isContinuation()`.

`admin/applications/show.blade.php`: next to the status badge add `@if ($isContinuation)<span class="badge bg-info text-dark">{{ __('app.applications.kinds.continuation') }}</span>@endif`; in the decision block, the `complete` branch shows the committee form only when `! $isContinuation` (a continuation never reaches `complete`, but guard anyway); the `canComplete` button label is `{{ $isContinuation ? __('app.review.approve_continuation') : __('app.review.mark_complete') }}`; in the final branch, when `$application->approval` exists show `app.review.year_approval` with kind, date and reference.

`DocumentPolicy::create` is unchanged (`isUploadable` covers every required/optional item).

- [ ] **Step 5: Mail**

`app/Mail/ContinuationApproved.php` (same shape as `ApplicationApproved`, subject `app.mail.continuation_subject`, view `emails.continuation-approved` with `name`, `term`, `url`); view body: `app.mail.continuation_body` (":term — تم اعتماد استمرارك من القسم ضمن اعتماد اللجنة للعام الدراسي، سيتواصل معك القسم بخصوص الجدول.") + open link.

- [ ] **Step 6: Lang** (ar; en twins):

- `applications`: `'kinds' => ['initial' => 'طلب جديد', 'continuation' => 'استمرار']`, `'start_continuation' => 'تقديم طلب استمرار للفصل الحالي'`, `'continuation_title' => 'طلب استمرار'`, `'academic_on_file' => 'المستندات الأكاديمية (على الملف)'`, `'term_papers' => 'أوراق الفصل'`, `'term_papers_hint' => 'تطلب هذه الأوراق في كل فصل دراسي ويعتمدها القسم دون الرجوع إلى اللجنة.'`, `'still_required' => 'المطلوب قبل التقديم'`.
- `review`: `'approve_continuation' => 'اعتماد الاستمرار'`, `'year_approval' => 'اعتماد اللجنة للعام الدراسي :year (:kind) بتاريخ :date، المرجع :ref'`.
- `mail`: `'continuation_subject' => 'اعتماد الاستمرار'`, `'continuation_body' => 'تم اعتماد استمرارك للفصل :term من القسم ضمن اعتماد اللجنة للعام الدراسي. سيتواصل معك القسم بخصوص الجدول.'`.

- [ ] **Step 7: Suite, Pint, commit**

Existing tests that call `stageOneRows` indirectly keep passing (initial applications unchanged).

```bash
git add -A
git commit -m "feat(continuation): same-year applications need per-term papers only and are approved by the department

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 3: Renewal batch, names list, mails

**Files:**
- Create: `app/Services/RenewalService.php`, `app/Services/RenewalListDocument.php`, `app/Http/Controllers/Admin/RenewalController.php`, `app/Http/Requests/RecordRenewalsRequest.php`, `resources/views/admin/renewals/index.blade.php`, `app/Mail/RenewalApproved.php`, `app/Mail/RenewalRefused.php`, `resources/views/emails/renewal-approved.blade.php`, `resources/views/emails/renewal-refused.blade.php`
- Modify: `routes/web.php`, `resources/views/admin/layout.blade.php` (nav link), `lang/*`
- Test: `tests/Feature/Admin/RenewalsTest.php`

**Interfaces:**
- Produces: routes `admin.renewals.index` (GET `admin/renewals`), `admin.renewals.store` (POST `admin/renewals`), `admin.renewals.list` (GET `admin/renewals/list`), `admin.renewals.destroy` (DELETE `admin/renewals/{approval}`); `RenewalService::candidates(string $year): Collection` (instructors with approved previous-year row and no target-year row, with `lastTerm` loaded), `record(string $year, array $rows, string $metOn, string $reference, User $admin): array{renewed:int, refused:int}`, `delete(CommitteeApproval, User $admin): void`; `RenewalListDocument::build(string $year, Collection $candidates, User $by): string` (docx path).

- [ ] **Step 1: Failing tests**

`tests/Feature/Admin/RenewalsTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Mail\RenewalApproved;
use App\Mail\RenewalRefused;
use App\Models\Application;
use App\Models\CommitteeApproval;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use App\Services\ApplicationWorkflow;
use App\Services\RenewalService;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RenewalsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Instructor $a;

    private Instructor $b;

    private Instructor $c;

    private Term $nextFirst;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(ChecklistItemSeeder::class);
        $this->admin = User::factory()->admin()->create();
        $old = Term::factory()->create(['academic_year' => '2026-2027', 'type' => 'second', 'teaching_starts_on' => '2027-02-07', 'teaching_ends_on' => '2027-05-27', 'status' => 'closed']);
        $this->a = Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'أحمد المرشح']);
        $this->b = Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'بدر المرشح']);
        $this->c = Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'جابر المرفوض']);
        foreach ([$this->a, $this->b] as $i) {
            CommitteeApproval::factory()->for($i)->create(['academic_year' => '2026-2027']);
            Application::factory()->approved()->for($old)->for($i)->create();
        }
        Application::factory()->for($old)->for($this->c)->create(['status' => Application::STATUS_REJECTED]);
        $this->nextFirst = Term::factory()->open()->create(['academic_year' => '2027-2028', 'type' => 'first', 'teaching_starts_on' => '2027-09-12', 'teaching_ends_on' => '2027-12-23']);
    }

    public function test_candidates_are_previous_year_approved_without_a_target_row(): void
    {
        $names = app(RenewalService::class)->candidates('2027-2028')->pluck('full_name')->all();
        $this->assertSame(['أحمد المرشح', 'بدر المرشح'], $names);
        CommitteeApproval::factory()->for($this->a)->renewal()->create(['academic_year' => '2027-2028']);
        $this->assertSame(['بدر المرشح'], app(RenewalService::class)->candidates('2027-2028')->pluck('full_name')->all());
    }

    public function test_page_lists_candidates_and_requires_admin(): void
    {
        $this->actingAs($this->admin)->get(route('admin.renewals.index', ['year' => '2027-2028']))->assertOk()->assertSee('أحمد المرشح')->assertDontSee('جابر المرفوض');
        $this->actingAs($this->a->user)->get(route('admin.renewals.index'))->assertForbidden();
    }

    public function test_record_creates_rows_drafts_and_mails(): void
    {
        $this->actingAs($this->admin)->post(route('admin.renewals.store'), [
            'year' => '2027-2028', 'committee_met_on' => '2027-06-15', 'committee_reference' => 'ق/22',
            'rows' => [$this->a->id => ['outcome' => 'renewed'], $this->b->id => ['outcome' => 'not_renewed', 'note' => 'تقييم ضعيف']],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $ra = $this->a->approvalFor('2027-2028');
        $this->assertSame(CommitteeApproval::KIND_RENEWAL, $ra->kind);
        $this->assertTrue($ra->isApproved());
        $this->assertSame('ق/22', $ra->committee_reference);
        $draft = Application::where('instructor_id', $this->a->id)->where('term_id', $this->nextFirst->id)->firstOrFail();
        $this->assertSame(Application::KIND_CONTINUATION, $draft->kind);
        $this->assertSame(Application::STATUS_DRAFT, $draft->status);
        $this->assertSame($ra->id, $draft->approval_id);

        $rb = $this->b->approvalFor('2027-2028');
        $this->assertSame(CommitteeApproval::OUTCOME_NOT_RENEWED, $rb->outcome);
        $this->assertSame('تقييم ضعيف', $rb->note);
        $this->assertDatabaseMissing('applications', ['instructor_id' => $this->b->id, 'term_id' => $this->nextFirst->id]);

        Mail::assertSent(RenewalApproved::class, fn ($m) => $m->hasTo($this->a->user->email));
        Mail::assertSent(RenewalRefused::class, fn ($m) => $m->hasTo($this->b->user->email));
        $this->assertDatabaseHas('audit_log', ['action' => 'renewal_approved', 'subject_id' => $this->a->id, 'details' => '2027-2028']);
        $this->assertDatabaseHas('audit_log', ['action' => 'renewal_refused', 'subject_id' => $this->b->id, 'details' => '2027-2028']);
        $this->assertDatabaseMissing('audit_log', ['details' => 'تقييم ضعيف']);
    }

    public function test_start_reuses_the_renewal_draft(): void
    {
        app(RenewalService::class)->record('2027-2028', [$this->a->id => ['outcome' => 'renewed']], '2027-06-15', 'ق/22', $this->admin);
        $app = app(ApplicationWorkflow::class)->start($this->a, $this->nextFirst);
        $this->assertSame(1, Application::where('instructor_id', $this->a->id)->where('term_id', $this->nextFirst->id)->count());
        $this->assertSame(Application::KIND_CONTINUATION, $app->kind);
    }

    public function test_record_refusals(): void
    {
        $post = fn (array $over = []) => $this->actingAs($this->admin)->post(route('admin.renewals.store'), array_merge([
            'year' => '2027-2028', 'committee_met_on' => '2027-06-15', 'committee_reference' => 'ق/22',
            'rows' => [$this->a->id => ['outcome' => 'renewed']],
        ], $over));
        $post(['rows' => []])->assertSessionHasErrors('rows');
        $post(['rows' => [$this->c->id => ['outcome' => 'renewed']]])->assertSessionHasErrors('renewals');
        $this->nextFirst->delete();
        $post()->assertSessionHasErrors('renewals');
        $this->assertDatabaseCount('committee_approvals', 2);
    }

    public function test_double_submit_is_refused_without_partial_writes(): void
    {
        app(RenewalService::class)->record('2027-2028', [$this->a->id => ['outcome' => 'renewed']], '2027-06-15', 'ق/22', $this->admin);
        $this->actingAs($this->admin)->post(route('admin.renewals.store'), [
            'year' => '2027-2028', 'committee_met_on' => '2027-06-15', 'committee_reference' => 'ق/22',
            'rows' => [$this->a->id => ['outcome' => 'renewed'], $this->b->id => ['outcome' => 'renewed']],
        ])->assertSessionHasErrors('renewals');
        $this->assertNull($this->b->approvalFor('2027-2028'));
    }

    public function test_delete_allowed_only_on_renewal_with_draft(): void
    {
        app(RenewalService::class)->record('2027-2028', [$this->a->id => ['outcome' => 'renewed']], '2027-06-15', 'ق/22', $this->admin);
        $row = $this->a->approvalFor('2027-2028');
        $this->actingAs($this->admin)->delete(route('admin.renewals.destroy', $row))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($this->a->fresh()->approvalFor('2027-2028'));
        $this->assertDatabaseMissing('applications', ['instructor_id' => $this->a->id, 'term_id' => $this->nextFirst->id]);
        $this->assertDatabaseHas('audit_log', ['action' => 'delete_renewal', 'subject_id' => $this->a->id, 'details' => '2027-2028']);

        app(RenewalService::class)->record('2027-2028', [$this->a->id => ['outcome' => 'renewed']], '2027-06-15', 'ق/22', $this->admin);
        Application::where('instructor_id', $this->a->id)->where('term_id', $this->nextFirst->id)->update(['status' => Application::STATUS_SUBMITTED]);
        $this->actingAs($this->admin)->delete(route('admin.renewals.destroy', $this->a->approvalFor('2027-2028')))->assertSessionHasErrors('renewals');
        $initial = CommitteeApproval::factory()->for($this->c)->create(['academic_year' => '2026-2027']);
        $this->actingAs($this->admin)->delete(route('admin.renewals.destroy', $initial))->assertSessionHasErrors('renewals');
    }

    public function test_names_list_document_contains_candidates_and_is_audited(): void
    {
        $r = $this->actingAs($this->admin)->get(route('admin.renewals.list', ['year' => '2027-2028']))->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $path = tempnam(sys_get_temp_dir(), 'list').'.docx';
        file_put_contents($path, $r->streamedContent());
        $zip = new \ZipArchive;
        $zip->open($path);
        $text = html_entity_decode(strip_tags($zip->getFromName('word/document.xml')));
        $zip->close();
        @unlink($path);
        $this->assertStringContainsString('2027-2028', $text);
        $this->assertStringContainsString('أحمد المرشح', $text);
        $this->assertStringContainsString($this->a->civil_id, $text);
        $this->assertStringNotContainsString('جابر', $text);
        $this->assertDatabaseHas('audit_log', ['action' => 'export_renewal_list', 'details' => '2027-2028']);
    }
}
```

(`$r->streamedContent()` works for `BinaryFileResponse` via `response()->download()`; if not, read `$r->baseResponse->getFile()->getPathname()` before the file is deleted — the ChecklistDocumentTest shows the pattern used there; copy it.)

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Service**

`app/Services/RenewalService.php`:

```php
<?php

namespace App\Services;

use App\Mail\RenewalApproved;
use App\Mail\RenewalRefused;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\CommitteeApproval;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** Spec M6 §5: the yearly renewal batch. */
class RenewalService
{
    /** Instructors approved for the previous year with no row for $year, by name, with `lastTerm` set. */
    public function candidates(string $year): Collection
    {
        $previous = CommitteeApproval::previousYear($year);

        return Instructor::query()
            ->whereHas('approvals', fn ($q) => $q->where('academic_year', $previous)->where('outcome', CommitteeApproval::OUTCOME_APPROVED))
            ->whereDoesntHave('approvals', fn ($q) => $q->where('academic_year', $year))
            ->with(['user', 'applications.term'])
            ->get()
            ->each(fn (Instructor $i) => $i->lastTerm = $i->applications->where('status', Application::STATUS_APPROVED)
                ->sortByDesc(fn ($a) => $a->term->teaching_starts_on)->first()?->term)
            ->sortBy('full_name')->values();
    }

    /**
     * @param  array<int, array{outcome: string, note?: ?string}>  $rows  instructor id => decision
     * @return array{renewed: int, refused: int}
     */
    public function record(string $year, array $rows, string $metOn, string $reference, User $admin): array
    {
        if ($rows === []) {
            throw new \DomainException(__('app.renewals.nothing_selected'));
        }
        $firstTerm = Term::where('academic_year', $year)->where('type', 'first')->first();
        if (! $firstTerm) {
            throw new \DomainException(__('app.renewals.no_first_term', ['year' => $year]));
        }
        $candidates = $this->candidates($year)->keyBy('id');
        foreach (array_keys($rows) as $id) {
            if (! $candidates->has($id)) {
                throw new \DomainException(__('app.renewals.not_a_candidate'));
            }
        }

        $mails = [];
        $counts = ['renewed' => 0, 'refused' => 0];
        DB::transaction(function () use ($year, $rows, $metOn, $reference, $admin, $firstTerm, $candidates, &$mails, &$counts) {
            foreach ($rows as $id => $decision) {
                $instructor = $candidates[$id];
                $renewed = $decision['outcome'] === 'renewed';
                $approval = CommitteeApproval::create([
                    'instructor_id' => $instructor->id, 'academic_year' => $year, 'kind' => CommitteeApproval::KIND_RENEWAL,
                    'outcome' => $renewed ? CommitteeApproval::OUTCOME_APPROVED : CommitteeApproval::OUTCOME_NOT_RENEWED,
                    'committee_met_on' => $metOn, 'committee_reference' => $reference,
                    'note' => $decision['note'] ?? null, 'decided_by' => $admin->id,
                ]);
                if ($renewed) {
                    $application = Application::firstOrCreate(
                        ['term_id' => $firstTerm->id, 'instructor_id' => $instructor->id],
                        ['status' => Application::STATUS_DRAFT, 'kind' => Application::KIND_CONTINUATION, 'approval_id' => $approval->id],
                    );
                    $mails[] = [$instructor->user->email, new RenewalApproved($instructor, $approval, $application)];
                    $counts['renewed']++;
                } else {
                    $mails[] = [$instructor->user->email, new RenewalRefused($instructor, $approval)];
                    $counts['refused']++;
                }
                AuditLog::record($admin->id, $renewed ? 'renewal_approved' : 'renewal_refused', $instructor, null, $year);
            }
        });
        foreach ($mails as [$to, $mail]) {
            $this->safeSend($to, $mail);
        }

        return $counts;
    }

    public function delete(CommitteeApproval $approval, User $admin): void
    {
        $application = $approval->applications()->first();
        if ($approval->kind !== CommitteeApproval::KIND_RENEWAL || ($application && $application->status !== Application::STATUS_DRAFT)) {
            throw new \DomainException(__('app.renewals.cannot_delete'));
        }
        DB::transaction(function () use ($approval, $application, $admin) {
            $application?->delete();
            AuditLog::record($admin->id, 'delete_renewal', $approval->instructor, null, $approval->academic_year);
            $approval->delete();
        });
    }

    private function safeSend(string $to, Mailable $mail): void
    {
        try {
            Mail::to($to)->send($mail);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
```

A `QueryException` from the unique key (double submit) must surface as the `renewals` error: catch `\Illuminate\Database\QueryException` in the controller and map to `app.renewals.already_recorded`.

- [ ] **Step 4: Names list document**

`app/Services/RenewalListDocument.php` — PhpWord, same setup as `ChecklistDocument` (Arial, bidi, ar-KW): title `__('app.renewals.list_title', ['year' => $year], 'ar')`, a line with the department and `now()->format('Y/m/d')`, a table with header cells `م`, `الاسم`, `الرقم المدني`, `جهة العمل`, `المؤهل`, `الفصول السابقة` and one row per candidate (serial, `full_name`, `civil_id`, `employer`, `__('app.profile.degrees.'.$i->highest_degree, [], 'ar').' — '.$i->degree_title`, the previous-year approved terms' labels joined by `، `), then `__('app.renewals.list_footer', [], 'ar')` and a signature line for the department head. Saved to `storage/app/private/generated/renewal-list-<year>-<random>.docx`, path returned.

- [ ] **Step 5: Request, controller, routes, view, nav, mails, lang**

`RecordRenewalsRequest`: `year` required regex `/^\d{4}-\d{4}$/`; `committee_met_on` required date; `committee_reference` required string max 60; `rows` required array min 1; `rows.*.outcome` required in `renewed,not_renewed`; `rows.*.note` nullable string max 500. Attributes from lang.

`RenewalController`: `index` (year from query or `CommitteeApproval::nextYear(Term::current()?->academic_year ?? <current calendar year>-<+1>)`, candidates, existing rows for the year for the "recorded" table with delete buttons), `store` (calls `record`, flashes `app.renewals.recorded` with counts; catches `DomainException` → `renewals` error; catches `QueryException` → `app.renewals.already_recorded`), `list` (audit `export_renewal_list` details = year, `response()->download(...)->deleteFileAfterSend(true)`), `destroy` (calls `delete`, flashes/errors). Routes in the admin group:

```php
    Route::get('renewals', [RenewalController::class, 'index'])->name('renewals.index');
    Route::post('renewals', [RenewalController::class, 'store'])->name('renewals.store');
    Route::get('renewals/list', [RenewalController::class, 'list'])->name('renewals.list');
    Route::delete('renewals/{approval}', [RenewalController::class, 'destroy'])->name('renewals.destroy');
```

View `admin/renewals/index.blade.php`: year selector (text input + go), header form fields (meeting date, reference), candidates table (checkbox `rows[<id>][selected]` is NOT used — instead a row is included when its outcome select is not empty: `<select name="rows[{{ $i->id }}][outcome]"><option value="">—</option><option value="renewed">…</option><option value="not_renewed">…</option></select>` plus `rows[<id>][note]`), submit button with confirm, the "قائمة الأسماء للجنة" link, and below a table of rows already recorded for the year (name, outcome, date, reference, delete button when deletable). The controller drops rows with an empty outcome before validation (`prepareForValidation` in the request filters `rows` to entries with a non-empty outcome).

Admin nav (`admin/layout.blade.php`): add a button `__('app.renewals.title')` → `admin.renewals.index`.

Mails: `RenewalApproved(Instructor $instructor, CommitteeApproval $approval, Application $application)` → subject `app.mail.renewal_approved_subject`, body `app.mail.renewal_approved_body` (:year), the per-term papers list (renewing items' labels from `ChecklistItem::where('renews_each_term', true)->orderBy('sort_order')`), link to the application; `RenewalRefused(Instructor, CommitteeApproval)` → subject `app.mail.renewal_refused_subject`, body `app.mail.renewal_refused_body` (:year) + note if any.

Lang (ar) new section:

```php
    'renewals' => [
        'title' => 'تجديد الاعتماد', 'year' => 'العام الدراسي المستهدف', 'candidates' => 'المرشحون للتجديد',
        'no_candidates' => 'لا يوجد منتدبون معتمدون في العام السابق بانتظار التجديد.',
        'outcome' => 'قرار اللجنة', 'outcomes' => ['renewed' => 'تجديد', 'not_renewed' => 'عدم تجديد'],
        'note' => 'ملاحظة', 'last_term' => 'آخر فصل', 'record' => 'تسجيل قرار اللجنة',
        'record_confirm' => 'سيتم تسجيل القرار وإبلاغ المنتدبين. لا يمكن التراجع إلا بحذف سجل التجديد قبل تقديم الطلب.',
        'recorded' => 'تم تسجيل قرار اللجنة: :renewed تجديد، :refused عدم تجديد.',
        'recorded_rows' => 'قرارات مسجلة لهذا العام', 'delete' => 'حذف', 'deleted' => 'تم حذف سجل التجديد.',
        'list' => 'قائمة الأسماء للجنة', 'list_title' => 'قائمة المنتدبين المرشحين لتجديد الاعتماد للعام الدراسي :year',
        'list_footer' => 'يرجى التكرم بتجديد اعتماد المذكورين أعلاه للعام الدراسي المذكور.',
        'nothing_selected' => 'لم يحدد قرار لأي منتدب.',
        'no_first_term' => 'لا يوجد فصل أول للعام الدراسي :year، أضف الفصل أولا.',
        'not_a_candidate' => 'أحد المنتدبين المحددين ليس مرشحا للتجديد.',
        'already_recorded' => 'سبق تسجيل قرار لأحد المنتدبين لهذا العام.',
        'cannot_delete' => 'لا يمكن حذف هذا السجل: ليس تجديدا أو قدم المنتدب طلبه.',
    ],
```

`mail`: `'renewal_approved_subject' => 'تجديد اعتماد الانتداب'`, `'renewal_approved_body' => 'جددت اللجنة اعتمادك للعام الدراسي :year. يرجى رفع أوراق الفصل التالية من صفحة الطلب:'`, `'renewal_refused_subject' => 'نتيجة تجديد الاعتماد'`, `'renewal_refused_body' => 'نأسف، لم تجدد اللجنة اعتمادك للعام الدراسي :year.'`. All with en twins.

- [ ] **Step 6: Suite, Pint, commit**

```bash
git add -A
git commit -m "feat(renewals): batch renewal for the next academic year, names list, mails

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 4: Academic bundle

**Files:**
- Create: `app/Services/AcademicBundle.php`, `app/Http/Controllers/Admin/InstructorController.php`, `resources/views/admin/instructors/show.blade.php`
- Modify: `routes/web.php`, `resources/views/admin/applications/show.blade.php` (link from the profile card), `lang/*`
- Test: `tests/Feature/Admin/AcademicBundleTest.php`

**Interfaces:**
- Produces: routes `admin.instructors.show` (GET `admin/instructors/{instructor}`), `admin.instructors.bundle` (GET `admin/instructors/{instructor}/academic-bundle`); `AcademicBundle::build(Instructor, User $by): string` (zip path); `AcademicBundle::ITEMS = ['civil_id', 'degree', 'transcript_bachelor', 'transcript_master', 'equivalency']`.

- [ ] **Step 1: Failing tests**

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\ChecklistExemption;
use App\Models\CommitteeApproval;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use App\Services\AcademicBundle;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AcademicBundleTest extends TestCase
{
    use RefreshDatabase;

    private Instructor $instructor;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ChecklistItemSeeder::class);
        $this->admin = User::factory()->admin()->create();
        $this->instructor = Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'محمد أحمد علي الفهد', 'basic_salary' => '900', 'total_salary' => '1200']);
        $old = Application::factory()->approved()->for(Term::factory()->create())->for($this->instructor)->create();
        $new = Application::factory()->approved()->for(Term::factory()->create(['type' => 'second', 'teaching_starts_on' => '2027-02-07', 'teaching_ends_on' => '2027-05-27']))->for($this->instructor)->create();
        CommitteeApproval::factory()->for($this->instructor)->create();
        foreach ([[$old, 'degree', 'old-degree.pdf', 1], [$new, 'degree', 'new-degree.pdf', 1], [$new, 'degree', 'new-degree-2.pdf', 2], [$old, 'civil_id', 'id.pdf', 1], [$old, 'iban', 'iban.pdf', 1]] as [$app, $code, $name, $part]) {
            $path = "applications/{$app->id}/".$name;
            Storage::disk('local')->put($path, 'content '.$name);
            Document::factory()->for($app)->forItem($code)->accepted()->create(['path' => $path, 'original_name' => $name, 'part' => $part, 'reviewed_at' => $app->is($new) ? now() : now()->subYear()]);
        }
        ChecklistExemption::factory()->for($new)->forItem('transcript_bachelor')->accepted()->create();
    }

    public function test_bundle_has_summary_and_latest_accepted_academic_parts_only(): void
    {
        $zipPath = app(AcademicBundle::class)->build($this->instructor, $this->admin);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $summary = html_entity_decode(strip_tags((new \ZipArchive)->open('zip://'.$zipPath.'#00-summary.docx') === true ? '' : ''));
        $zip->close();
        sort($names);
        $this->assertSame(['00-summary.docx', 'civil_id-1-id.pdf', 'degree-1-new-degree.pdf', 'degree-2-new-degree-2.pdf'], $names);
        @unlink($zipPath);
    }

    public function test_summary_mentions_exemption_and_never_salary_or_iban(): void
    {
        $zipPath = app(AcademicBundle::class)->build($this->instructor, $this->admin);
        $zip = new \ZipArchive;
        $zip->open($zipPath);
        $docx = $zip->getFromName('00-summary.docx');
        $zip->close();
        $tmp = tempnam(sys_get_temp_dir(), 'sum').'.docx';
        file_put_contents($tmp, $docx);
        $inner = new \ZipArchive;
        $inner->open($tmp);
        $text = html_entity_decode(strip_tags($inner->getFromName('word/document.xml')));
        $inner->close();
        @unlink($tmp);
        @unlink($zipPath);
        $this->assertStringContainsString('محمد أحمد علي الفهد', $text);
        $this->assertStringContainsString($this->instructor->civil_id, $text);
        $this->assertStringContainsString(__('app.bundle.exempted', [], 'ar'), $text);
        $this->assertStringContainsString('2026-2027', $text);
        $this->assertStringNotContainsString('1200', $text);
        $this->assertStringNotContainsString($this->instructor->iban, $text);
    }

    public function test_route_streams_zip_for_admin_only_audits_and_cleans_up(): void
    {
        $this->actingAs($this->admin)->get(route('admin.instructors.bundle', $this->instructor))->assertOk()->assertHeader('content-type', 'application/zip');
        $this->assertDatabaseHas('audit_log', ['action' => 'export_academic_bundle', 'subject_id' => $this->instructor->id]);
        $this->assertSame([], glob(storage_path('app/private/generated/tmp/bundle-*')));
        $this->actingAs($this->instructor->user)->get(route('admin.instructors.bundle', $this->instructor))->assertForbidden();
    }

    public function test_instructor_page_shows_approvals_and_bundle_button(): void
    {
        $this->actingAs($this->admin)->get(route('admin.instructors.show', $this->instructor))->assertOk()
            ->assertSee('2026-2027')->assertSee(route('admin.instructors.bundle', $this->instructor));
    }
}
```

(Drop the unused `$summary` line in the first test when transcribing; it is a placeholder-free no-op. With `Storage::fake('local')`, `storage_path('app/private/generated/tmp')` may differ from the fake root: build the bundle under the **fake** disk's `generated/tmp` via `Storage::disk('local')->path('generated/tmp')` and assert cleanliness through `Storage::disk('local')->files('generated/tmp')` being empty instead of `glob`.)

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Service**

`app/Services/AcademicBundle.php`:

- `ITEMS` constant as above.
- `latestAccepted(Instructor): Collection` — for each code, the head document (`part = 1`) with the highest `reviewed_at` then id among the instructor's applications, `status = accepted`, with `parts` loaded (reuse the `setRelation` pattern from `onFileDocuments()`); keyed by code.
- `acceptedExemptions(Instructor): Collection` — accepted `ChecklistExemption` rows of the instructor's applications keyed by item code.
- `build(Instructor $i, User $by): string`:
  - PhpWord summary (same setup as `ChecklistDocument`): title `app.bundle.title` (ar), lines: name; civil ID + expiry; nationality label; highest degree + title; country name; obtained date; equivalency: file date / `app.bundle.not_applicable` / `app.bundle.missing`; transcripts: per item `app.bundle.on_file` with date / `app.bundle.exempted` / `app.bundle.missing`; employer + job title; approvals: one line per `CommitteeApproval` (`app.review.year_approval` with year, kind label, date, ref); generated `now()` + `$by->name`. Never salary, IBAN, bank.
  - ZIP (`ZipArchive`) under `Storage::disk('local')->path('generated/tmp')` named `bundle-<id>-<random>.zip`: `00-summary.docx` + for each item/part `"<code>-<part>-<original_name>"` from `Storage::disk('local')->path($part->path)`; the summary docx temp file deleted after adding.
  - Returns the zip path.

- [ ] **Step 4: Controller, routes, views, lang**

`Admin\InstructorController`: `show(Instructor)` → view with approvals (`$instructor->approvals()->orderByDesc('academic_year')->get()`), applications with terms, and the bundle button; `bundle(Request, Instructor, AcademicBundle)` → audit `export_academic_bundle`, `response()->download($path, "academic-{$instructor->id}.zip", ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true)`.

Routes (admin group): `Route::get('instructors/{instructor}', [InstructorController::class, 'show'])->name('instructors.show'); Route::get('instructors/{instructor}/academic-bundle', [InstructorController::class, 'bundle'])->name('instructors.bundle');`

`admin/instructors/show.blade.php`: name + masked civil ID header, approvals table (year, kind, outcome, date, reference, note), applications table (term, kind, status, link), bundle button. `admin/applications/show.blade.php` profile card header: add a link `app.review.instructor_record` → `admin.instructors.show`.

Lang (ar): `'bundle' => ['title' => 'ملخص المستندات الأكاديمية', 'download' => 'حزمة المستندات الأكاديمية', 'on_file' => 'على الملف (قبل بتاريخ :date)', 'exempted' => 'معفى بقرار القسم', 'missing' => 'غير متوفر', 'not_applicable' => 'لا ينطبق', 'generated' => 'أعد بتاريخ :date بواسطة :name']`, `review.instructor_record => 'سجل المنتدب'`, `review.approvals => 'اعتمادات اللجنة'`; en twins.

- [ ] **Step 5: Suite, Pint, commit**

```bash
git add -A
git commit -m "feat(bundle): instructor record page and academic documents bundle for the committee

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 5: Term-end page

**Files:**
- Create: `app/Services/TermClosingReport.php`, `resources/views/admin/terms/closing.blade.php`
- Modify: `app/Http/Controllers/Admin/TermController.php`, `routes/web.php`, `resources/views/admin/terms/index.blade.php`, `lang/*`
- Test: `tests/Feature/Admin/TermClosingPageTest.php`

**Interfaces:**
- Produces: route `admin.terms.closing` (GET `admin/terms/{term}/closing`); `TermClosingReport::rows(Term): Collection` of `['application' => Application, 'months' => [['label' => string, 'status' => 'exported'|'generated'|'missing']], 'next' => ?Application, 'nextTerm' => ?Term, 'nextMissing' => list<string>]`; `counts(Term): array{approved:int, months_unexported:int, continuations_missing:int}`.

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\Attestation;
use App\Models\CommitteeApproval;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use App\Services\TermClosingReport;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TermClosingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_rows_counts_and_page(): void
    {
        $this->seed(ChecklistItemSeeder::class);
        $admin = User::factory()->admin()->create();
        $term = Term::factory()->open()->create();   // 2026-09-13 .. 2026-12-24 → 4 months
        $next = Term::factory()->create(['type' => 'second', 'teaching_starts_on' => '2027-02-07', 'teaching_ends_on' => '2027-05-27']);
        $i = Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'سالم المنتدب']);
        $app = Application::factory()->approved()->for($term)->for($i)->create();
        CommitteeApproval::factory()->for($i)->create();
        Attestation::factory()->for($app)->create(['year' => 2026, 'month' => 9, 'status' => Attestation::STATUS_EXPORTED]);
        Attestation::factory()->for($app)->create(['year' => 2026, 'month' => 10, 'status' => Attestation::STATUS_GENERATED]);
        $cont = Application::factory()->continuation()->for($next)->for($i)->create();

        $report = app(TermClosingReport::class);
        $row = $report->rows($term)->first();
        $this->assertSame(['exported', 'generated', 'missing', 'missing'], array_column($row['months'], 'status'));
        $this->assertTrue($row['next']->is($cont));
        $this->assertContains('شهادة راتب حديثة', $row['nextMissing']);
        $this->assertSame(['approved' => 1, 'months_unexported' => 3, 'continuations_missing' => 0], $report->counts($term));

        $this->actingAs($admin)->get(route('admin.terms.closing', $term))->assertOk()
            ->assertSee('سالم المنتدب')->assertSee(__('app.terms.closing_title'))->assertSee(__('app.applications.statuses.draft'))
            ->assertSee(route('admin.applications.show', $cont));
        $this->actingAs($i->user)->get(route('admin.terms.closing', $term))->assertForbidden();

        $term->update(['status' => 'closed']);
        $this->actingAs($admin)->get(route('admin.terms.closing', $term))->assertOk();
        $this->actingAs($admin)->get(route('admin.terms.index'))->assertOk()->assertSee(route('admin.terms.closing', $term));
    }
}
```

(Check `Attestation::factory()` exists — `tests/Feature/Admin/AttestationsTest.php` shows how attestations are created; if there is no factory, create one with `application_id`, `year`, `month`, `status`, `generated_at`.)

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Service**

```php
<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Attestation;
use App\Models\Term;
use Illuminate\Support\Collection;

/** Spec M6 §7: what the department processes at the end of a term. */
class TermClosingReport
{
    public function __construct(private ApplicationWorkflow $workflow) {}

    public function nextTerm(Term $term): ?Term
    {
        return Term::where('teaching_starts_on', '>', $term->teaching_starts_on)->orderBy('teaching_starts_on')->first();
    }

    public function rows(Term $term): Collection
    {
        $next = $this->nextTerm($term);
        $months = $term->months();
        $applications = $term->applications()->where('status', Application::STATUS_APPROVED)->with('instructor')->get()
            ->sortBy(fn ($a) => $a->instructor->full_name)->values();
        $attestations = Attestation::whereIn('application_id', $applications->pluck('id'))->get()->groupBy('application_id');
        $continuations = $next ? Application::where('term_id', $next->id)->whereIn('instructor_id', $applications->pluck('instructor_id'))->get()->keyBy('instructor_id') : collect();

        return $applications->map(function (Application $a) use ($months, $attestations, $continuations, $next) {
            $own = $attestations->get($a->id, collect());
            $cont = $continuations->get($a->instructor_id);

            return [
                'application' => $a,
                'months' => array_map(fn ($m) => ['label' => $m['label'], 'status' => $own->first(fn ($t) => (int) $t->year === $m['year'] && (int) $t->month === $m['month'])?->status ?? 'missing'], $months),
                'next' => $cont,
                'nextTerm' => $next,
                'nextMissing' => $cont ? $this->workflow->requiredMissing($cont) : [],
            ];
        });
    }

    public function counts(Term $term): array
    {
        $rows = $this->rows($term);

        return [
            'approved' => $rows->count(),
            'months_unexported' => $rows->sum(fn ($r) => count(array_filter($r['months'], fn ($m) => $m['status'] !== Attestation::STATUS_EXPORTED))),
            'continuations_missing' => $rows->filter(fn ($r) => $r['nextTerm'] && $r['next'] === null)->count(),
        ];
    }
}
```

- [ ] **Step 4: Controller, route, views, lang**

`TermController::closing(Term $term, TermClosingReport $report)` → view `admin.terms.closing` with `term`, `rows`, `counts`, `nextTerm`. Route (admin group): `Route::get('terms/{term}/closing', [TermController::class, 'closing'])->name('terms.closing');`. Terms index: a link `app.terms.closing` per term next to edit/close.

View: heading `app.terms.closing_title` + term label; three count badges; table: instructor (link to application), weekly hours, months as badges (`bg-success` exported, `bg-warning text-dark` generated, `bg-secondary` missing, label = month label), next term column: `app.terms.no_next_term` / `app.terms.continuation_none` / status badge + link + missing list joined by `، `; the close-term form when the term is open (same form as the index).

Lang (ar, `terms`): `'closing' => 'نهاية الفصل'`, `'closing_title' => 'إجراءات نهاية الفصل'`, `'approved_count' => 'منتدبون معتمدون'`, `'months_unexported' => 'أشهر مزاولة غير مصدرة'`, `'continuations_missing' => 'استمرارات لم تبدأ'`, `'months' => 'أشهر المزاولة'`, `'next_term' => 'الفصل التالي'`, `'no_next_term' => 'لم يضف الفصل التالي بعد'`, `'continuation_none' => 'لم يبدأ طلب الاستمرار'`; `attestations.statuses` already has `generated`/`exported` labels (reuse) plus add `'missing' => 'غير مولدة'` if absent. En twins.

- [ ] **Step 5: Suite, Pint, commit**

```bash
git add -A
git commit -m "feat(terms): term-end page with attestation months and next-term continuation state

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 6: Docs

**Files:**
- Modify: `CLAUDE.md`, `PROGRESS.md`, `deploy/DEPLOY.md`, `docs/superpowers/reviews/` (nothing; the review record is written by the controller)

- [ ] **Step 1:** `CLAUDE.md` status paragraph: milestone 6 on branch `milestone-6-year-approval`: year approvals (`committee_approvals`), continuation applications (per-term papers, department approval), renewal batch at `/admin/renewals` with the names list, instructor record + academic bundle at `/admin/instructors/{id}`, term-end page at `/admin/terms/{id}/closing`. "Next step": deploy (two migrations), then create the next academic year's first term before running a renewal. `PROGRESS.md`: log line 2026-10-04. `DEPLOY.md`: one line under the 5b note: "Milestone 6: two migrations, no server steps; the renewal batch needs the target year's first term to exist."

- [ ] **Step 2: Commit**

```bash
git add -A
git commit -m "docs: milestone 6 status, deploy note

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Self-review notes

- Spec coverage: §3 → T1; §4 → T2; §5 → T3; §6 → T4; §7 → T5; §8 copy spread over T2–T5; §9 security: policies/routes in T3–T5 (admin group), audits asserted in tests; §10 tests mapped per task; §11 order followed.
- Type consistency: `requiredMissing()` (T2) is consumed by T5 and the instructor page; `CommitteeApproval::previousYear/nextYear` (T1) used by T3; `ApplicationFactory::continuation()` (T1) used by T5; `Application::isContinuation()` used everywhere.
- Known soft spot: T2's `gateRows()` replaces `stageOneRows()`; `blockMessageForRows()` and `rowsOnlyExemptionsBlock()` keep working on the new row set (exemptions never exist on continuations, so the exemption message cannot fire there).

# Milestone 5b — Two-Stage Documents Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split the applicant checklist into a committee stage and a post-approval stage, add transcript exemption requests, collect salary after approval, gate the monthly attestation on the second stage, and allow several files per upload.

**Architecture:** Checklist items carry `stage`, `exemptable` and `official` flags (seeded). `ApplicationWorkflow::checklist()` returns stage-1 rows then stage-2 rows with two new states (`exemption_requested`, `exempted`) from a new `checklist_exemptions` table; the submit and complete gates read stage-1 rows only, and `stageTwoComplete()` derives readiness from stage-2 rows plus the salary fields. Documents gain a `part` column so one version can hold several files; the head part (part 1) carries the review state. No new application status.

**Tech Stack:** Laravel 12, PHP 8.4, MySQL (prod) / SQLite `:memory:` (tests), PHPUnit, Blade + Bootstrap 5.3 RTL, PhpWord.

**Spec:** `docs/superpowers/specs/2026-10-02-milestone-5b-two-stage-documents-design.md`

## Global Constraints

- Formal, undiacritized Arabic (no tashkeel) in every `ar` string; every new `ar` key has an `en` twin; no hard-coded UI strings in views or code.
- Sensitive fields (`civil_id`, `iban`, `basic_salary`, `total_salary`) are never written to logs, audit details, flashes or error messages; audit `details` hold field names or item codes only.
- The status pipeline is unchanged: `draft → submitted → under_review ⇄ incomplete → complete → approved | rejected`, `withdrawn`. No new status, no stored "stage 2 complete" flag.
- `php artisan test --compact` green after every task; Pint (`vendor/bin/pint --quiet <touched php files>`) only on touched files — never on `lang/`.
- Never read, copy or reference `../part-time/` or anything under it.
- Commit after each task with the message given in the task; end commit messages with `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.
- Before running tests, sweep iCloud duplicates: `find . -path ./vendor -prune -o -name '* 2*' -print` must print nothing (delete any such files after confirming they are copies).
- Migrations are additive; the `optional` column stays (seeded false).

## Review Focus

1. A stage-1 upload or exemption request on an **approved** application must be refused (403), while a stage-2 upload is allowed — Task 4 tests both.
2. A stage-2 rejection on an approved application must **not** change the status and must not run the `incomplete` branch — Task 4 test `test_stage_two_rejection_keeps_approved_status`.
3. A pending exemption must block "mark complete" with the exemption-specific message while **not** blocking submission — Task 2 tests.
4. A multi-file upload must store all parts under one version and accept/reject must update every part; a legacy single `file` post must still work — Task 6 tests.
5. `stageTwoComplete()` must count on-file stage-2 copies and must fail when either salary field is null even if every document is accepted — Task 2 tests.

---

### Task 1: Schema, seeder and models

**Files:**
- Create: `database/migrations/2026_10_03_100000_add_stage_flags_to_checklist_items_table.php`
- Create: `database/migrations/2026_10_03_100001_create_checklist_exemptions_table.php`
- Create: `database/migrations/2026_10_03_100002_add_part_to_documents_table.php`
- Create: `app/Models/ChecklistExemption.php`
- Create: `database/factories/ChecklistExemptionFactory.php`
- Modify: `database/seeders/ChecklistItemSeeder.php`
- Modify: `app/Models/ChecklistItem.php`
- Modify: `app/Models/Application.php` (relations)
- Modify: `app/Models/Document.php` (`parts` relation)
- Modify: `tests/Unit/ChecklistResolverTest.php`, `tests/Feature/OptionalItemsTest.php`
- Test: `tests/Feature/StageSeederTest.php`

**Interfaces:**
- Produces: `ChecklistItem` attributes `stage` (0|1|2), `exemptable` (bool), `official` (bool); constants `ChecklistItem::STAGE_COMMITTEE = 1`, `STAGE_AFTER_APPROVAL = 2`; `ChecklistItem::isStageTwo(): bool`.
- Produces: `ChecklistExemption` model (`STATUS_PENDING|STATUS_ACCEPTED|STATUS_REJECTED`, relations `application`, `item`, `decider`), `Application::exemptions()`, `Document::parts()`.

- [ ] **Step 1: Write the failing seeder test**

`tests/Feature/StageSeederTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ChecklistItem;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StageSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_items_carry_stage_exemptable_and_official_flags(): void
    {
        $this->seed(ChecklistItemSeeder::class);
        $this->seed(ChecklistItemSeeder::class); // idempotent
        $this->assertDatabaseCount('checklist_items', 14);

        $expected = [
            'schedule' => [0, false, true], 'assignment_letter' => [0, false, true], 'attestation' => [0, false, true],
            'civil_id' => [1, false, true], 'degree' => [1, false, true],
            'transcript_bachelor' => [1, true, false], 'transcript_master' => [1, true, false],
            'equivalency' => [1, false, true], 'social_insurance' => [2, false, true], 'experience' => [1, false, true],
            'salary_cert' => [2, false, true], 'iban' => [2, false, true], 'employer_approval' => [2, false, true], 'undertaking' => [2, false, true],
        ];
        foreach ($expected as $code => [$stage, $exemptable, $official]) {
            $item = ChecklistItem::where('code', $code)->firstOrFail();
            $this->assertSame($stage, (int) $item->stage, $code);
            $this->assertSame($exemptable, (bool) $item->exemptable, $code);
            $this->assertSame($official, (bool) $item->official, $code);
            $this->assertFalse((bool) $item->optional, "$code must not be optional any more");
        }
        $this->assertSame(['civil_id', 'degree', 'transcript_bachelor', 'transcript_master', 'equivalency', 'experience'],
            ChecklistItem::where('stage', 1)->orderBy('sort_order')->pluck('code')->all());
        $this->assertSame(['social_insurance', 'salary_cert', 'iban', 'employer_approval', 'undertaking'],
            ChecklistItem::where('stage', 2)->orderBy('sort_order')->pluck('code')->all());
    }

    public function test_documents_unique_key_includes_part(): void
    {
        $this->seed(ChecklistItemSeeder::class);
        $app = \App\Models\Application::factory()->create();
        \App\Models\Document::factory()->for($app)->forItem('degree')->create(['version' => 1, 'part' => 1]);
        \App\Models\Document::factory()->for($app)->forItem('degree')->create(['version' => 1, 'part' => 2]);
        $this->assertDatabaseCount('documents', 2);
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/StageSeederTest.php`
Expected: FAIL (unknown column `stage`).

- [ ] **Step 3: Migrations**

`database/migrations/2026_10_03_100000_add_stage_flags_to_checklist_items_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_items', function (Blueprint $table) {
            $table->unsignedTinyInteger('stage')->default(1)->after('condition');   // 0 department, 1 committee, 2 after approval
            $table->boolean('exemptable')->default(false)->after('stage');
            $table->boolean('official')->default(true)->after('exemptable');       // printed on the official Check List
        });
    }

    public function down(): void
    {
        Schema::table('checklist_items', function (Blueprint $table) {
            $table->dropColumn(['stage', 'exemptable', 'official']);
        });
    }
};
```

`database/migrations/2026_10_03_100001_create_checklist_exemptions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_exemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checklist_item_id')->constrained()->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestamp('requested_at');
            $table->string('status', 10)->default('pending');   // pending|accepted|rejected
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->unique(['application_id', 'checklist_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_exemptions');
    }
};
```

`database/migrations/2026_10_03_100002_add_part_to_documents_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedSmallInteger('part')->default(1)->after('version');
            $table->dropUnique(['application_id', 'checklist_item_id', 'version']);
            $table->unique(['application_id', 'checklist_item_id', 'version', 'part'], 'documents_item_version_part_unique');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropUnique('documents_item_version_part_unique');
            $table->unique(['application_id', 'checklist_item_id', 'version']);
            $table->dropColumn('part');
        });
    }
};
```

Note for SQLite: `dropUnique` by column list works on both drivers in Laravel 12 (SQLite rebuilds the table). If the SQLite test driver refuses the drop inside the same `Schema::table` call, split it into two `Schema::table` calls (add column + drop unique first, then add the new unique).

- [ ] **Step 4: Seeder**

Replace the `$items` array and loop in `database/seeders/ChecklistItemSeeder.php`:

```php
        // [code, label, note, provided_by, condition, renews_each_term, stage, exemptable, official]
        $items = [
            ['schedule', 'الجدول الدراسي', null, 'department', 'always', false, 0, false, true],
            ['assignment_letter', 'كشف التكليف للمنتدب', null, 'department', 'always', false, 0, false, true],
            ['attestation', 'كشف المزاولة للمنتدب', null, 'department', 'always', false, 0, false, true],
            ['civil_id', 'صورة البطاقة المدنية سارية المفعول', null, 'applicant', 'always', false, 1, false, true],
            ['degree', 'صورة من المؤهل العلمي', null, 'applicant', 'always', false, 1, false, true],
            ['transcript_bachelor', 'كشف درجات البكالوريوس', null, 'applicant', 'always', false, 1, true, false],
            ['transcript_master', 'كشف درجات الماجستير', null, 'applicant', 'master_or_above', false, 1, true, false],
            ['equivalency', 'صورة من معادلة المؤهل العلمي', 'للمؤهلات الصادرة من خارج دولة الكويت', 'applicant', 'foreign_degree', false, 1, false, true],
            ['social_insurance', 'شهادة من المؤسسة العامة للتأمينات الاجتماعية', 'للعاملين في القطاع الخاص فقط', 'applicant', 'private_sector', true, 2, false, true],
            ['experience', 'صورة من شهادة الخبرة', 'لحملة شهادة البكالوريوس، لا تقل عن 10 سنوات', 'applicant', 'bachelor_only', false, 1, false, true],
            ['salary_cert', 'شهادة راتب حديثة', null, 'applicant', 'always', true, 2, false, true],
            ['iban', 'كشف الآيبان IBAN معتمد من البنك', null, 'applicant', 'always', false, 2, false, true],
            ['employer_approval', 'موافقة جهة العمل', 'موجهة لمدير عام الهيئة ومدون بها الفصل الدراسي والعام الدراسي', 'applicant', 'always', true, 2, false, true],
            ['undertaking', 'نموذج إقرار وتعهد', 'موقع من قبل المنتدب', 'applicant', 'always', true, 2, false, true],
        ];

        foreach ($items as $i => [$code, $label, $note, $by, $cond, $renews, $stage, $exemptable, $official]) {
            ChecklistItem::updateOrCreate(['code' => $code], [
                'label_ar' => $label, 'note_ar' => $note, 'sort_order' => $i + 1,
                'provided_by' => $by, 'condition' => $cond, 'renews_each_term' => $renews,
                'stage' => $stage, 'exemptable' => $exemptable, 'official' => $official,
                'optional' => false,   // 5b: optional rows are no longer seeded; the column stays for future use
            ]);
        }
```

- [ ] **Step 5: Models**

`app/Models/ChecklistItem.php` — replace `$fillable`, `casts()` and add constants/helpers:

```php
    public const STAGE_DEPARTMENT = 0;

    public const STAGE_COMMITTEE = 1;

    public const STAGE_AFTER_APPROVAL = 2;

    protected $fillable = ['code', 'label_ar', 'note_ar', 'sort_order', 'provided_by', 'condition', 'optional',
        'renews_each_term', 'stage', 'exemptable', 'official'];

    protected function casts(): array
    {
        return ['renews_each_term' => 'boolean', 'optional' => 'boolean', 'exemptable' => 'boolean', 'official' => 'boolean', 'stage' => 'integer'];
    }

    public function isStageTwo(): bool
    {
        return $this->stage === self::STAGE_AFTER_APPROVAL;
    }
```

`app/Models/ChecklistExemption.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An applicant's request to be exempted from an exemptable stage-1 item (spec 5b §6). */
class ChecklistExemption extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = ['application_id', 'checklist_item_id', 'reason', 'requested_at', 'status',
        'decided_by', 'decided_at', 'decision_note', 'notified_at'];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'decided_at' => 'datetime', 'notified_at' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ChecklistItem::class, 'checklist_item_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
```

`database/factories/ChecklistExemptionFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\ChecklistExemption;
use App\Models\ChecklistItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class ChecklistExemptionFactory extends Factory
{
    protected $model = ChecklistExemption::class;

    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'checklist_item_id' => fn () => ChecklistItem::where('code', 'transcript_bachelor')->value('id'),
            'reason' => 'الجامعة لا تصدر كشف درجات للخريجين القدامى',
            'requested_at' => now(), 'status' => ChecklistExemption::STATUS_PENDING,
        ];
    }

    public function forItem(string $code): static
    {
        return $this->state(fn () => ['checklist_item_id' => ChecklistItem::where('code', $code)->value('id')]);
    }

    public function accepted(): static
    {
        return $this->state(fn () => ['status' => ChecklistExemption::STATUS_ACCEPTED, 'decided_at' => now()]);
    }

    public function rejected(string $note = 'يمكن طلب الكشف من الجامعة'): static
    {
        return $this->state(fn () => ['status' => ChecklistExemption::STATUS_REJECTED, 'decided_at' => now(), 'decision_note' => $note]);
    }
}
```

`app/Models/Application.php` — add after `renewals()`:

```php
    public function exemptions(): HasMany
    {
        return $this->hasMany(ChecklistExemption::class);
    }
```

`app/Models/Document.php` — add `'part'` to `$fillable` and:

```php
    /** Every file of this document's version (part 1 is the head that carries the review state). */
    public function parts(): HasMany
    {
        return $this->hasMany(Document::class, 'application_id', 'application_id')
            ->where('checklist_item_id', $this->checklist_item_id)->where('version', $this->version)->orderBy('part');
    }
```

(import `Illuminate\Database\Eloquent\Relations\HasMany`). `database/factories/DocumentFactory.php` `definition()` gains `'part' => 1`.

- [ ] **Step 6: Fix the two tests that pinned the 2026-10-02 optional seeding**

`tests/Unit/ChecklistResolverTest.php::test_local_master_government_requires_only_unconditional_applicant_items`: the required list becomes `['civil_id', 'degree', 'transcript_bachelor', 'transcript_master', 'salary_cert', 'iban', 'employer_approval', 'undertaking']` and the `optional` assertion becomes `assertSame([], ...)`. `test_all_three_conditions_together`: count becomes 10 (bachelor: no master transcript, plus experience, equivalency, social insurance → civil_id, degree, transcript_bachelor, equivalency, social_insurance, experience, salary_cert, iban, employer_approval, undertaking).

`tests/Feature/OptionalItemsTest.php`: delete the file. Its behaviours (optional flag mechanics) are superseded; Task 2 re-tests `optional` rows with a factory-made optional item.

- [ ] **Step 7: Run the suite**

Run: `php artisan test --compact`
Expected: green except tests that enumerate the old 6-item required list by name (`SubmitTest::uploadAll`, `CompleteStatusTest`, `ReviewTest` setups): they now fail because transcripts are required. Fix them by adding `'transcript_bachelor', 'transcript_master'` to those code lists (setups only; the behaviour under test is unchanged). Re-run until green.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat(checklist): stage, exemptable and official flags; exemptions table; document parts

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 2: Plan stages, derivation, gates and stage-2 readiness

**Files:**
- Modify: `app/Services/ChecklistPlan.php`, `app/Services/ChecklistResolver.php`, `app/Services/ApplicationWorkflow.php`, `app/Services/ChecklistDocument.php`, `app/Models/Application.php`, `app/Models/Instructor.php`
- Modify: `lang/ar/app.php`, `lang/en/app.php`
- Test: `tests/Feature/StageDerivationTest.php`

**Interfaces:**
- Consumes: Task 1 flags and `ChecklistExemption`.
- Produces: `ChecklistPlan::$stage1`, `$stage2` (Collections), `isUploadable()` unchanged; `ApplicationWorkflow::STATE_EXEMPTION_REQUESTED = 'exemption_requested'`, `STATE_EXEMPTED = 'exempted'`, `SATISFIED_STATES`; row keys `exemption` and `stage`; `allRequiredUploaded()` / `allRequiredAccepted()` stage-1 only; `stageTwoComplete(Application): bool`, `stageTwoMissing(Application): array<int,string>`, `hasUndecidedExemptions(Application): bool`; `Application::acceptsStageTwoUploads(): bool`; `Instructor::hasApprovedApplicationInOpenTerm(): bool`.

- [ ] **Step 1: Failing tests**

`tests/Feature/StageDerivationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ChecklistExemption;
use App\Models\ChecklistItem;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use App\Services\ApplicationWorkflow;
use App\Services\ChecklistResolver;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StageDerivationTest extends TestCase
{
    use RefreshDatabase;

    private Application $application;

    private ApplicationWorkflow $workflow;

    private const STAGE1 = ['civil_id', 'degree', 'transcript_bachelor', 'transcript_master'];

    private const STAGE2 = ['salary_cert', 'iban', 'employer_approval', 'undertaking'];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(ChecklistItemSeeder::class);
        $instructor = Instructor::factory()->for(User::factory()->instructor())->create(['basic_salary' => null, 'total_salary' => null]);
        $this->application = Application::factory()->for(Term::factory()->open())->for($instructor)->create();
        $this->workflow = app(ApplicationWorkflow::class);
    }

    private function accept(array $codes): void
    {
        foreach ($codes as $code) {
            Document::factory()->for($this->application)->forItem($code)->accepted()->create();
        }
    }

    public function test_plan_splits_applicable_items_by_stage(): void
    {
        $plan = app(ChecklistResolver::class)->for($this->application->instructor);
        $this->assertSame(self::STAGE1, $plan->stage1->pluck('code')->all());
        $this->assertSame(self::STAGE2, $plan->stage2->pluck('code')->all());
        $this->assertSame([...self::STAGE1, ...self::STAGE2], $plan->required->pluck('code')->all());
    }

    public function test_rows_come_stage_one_first_with_stage_and_exemption_keys(): void
    {
        $rows = $this->workflow->checklist($this->application);
        $this->assertSame([...self::STAGE1, ...self::STAGE2], array_keys($rows));
        $this->assertSame(1, $rows['degree']['stage']);
        $this->assertSame(2, $rows['iban']['stage']);
        $this->assertNull($rows['transcript_bachelor']['exemption']);
    }

    public function test_exemption_states(): void
    {
        ChecklistExemption::factory()->for($this->application)->forItem('transcript_bachelor')->create();
        ChecklistExemption::factory()->for($this->application)->forItem('transcript_master')->accepted()->create();
        $rows = $this->workflow->checklist($this->application);
        $this->assertSame(ApplicationWorkflow::STATE_EXEMPTION_REQUESTED, $rows['transcript_bachelor']['state']);
        $this->assertSame(ApplicationWorkflow::STATE_EXEMPTED, $rows['transcript_master']['state']);

        $rows['transcript_bachelor']['exemption']->update(['status' => ChecklistExemption::STATUS_REJECTED, 'decision_note' => 'x', 'decided_at' => now()]);
        $rows = $this->workflow->checklist($this->application);
        $this->assertSame('missing', $rows['transcript_bachelor']['state']);
        $this->assertSame('x', $rows['transcript_bachelor']['exemption']->decision_note);
    }

    public function test_uploaded_document_wins_over_an_exemption(): void
    {
        ChecklistExemption::factory()->for($this->application)->forItem('transcript_bachelor')->accepted()->create();
        Document::factory()->for($this->application)->forItem('transcript_bachelor')->create();
        $this->assertSame('pending', $this->workflow->checklist($this->application)['transcript_bachelor']['state']);
    }

    public function test_submit_gate_reads_stage_one_only_and_accepts_a_pending_exemption(): void
    {
        $this->assertFalse($this->workflow->allRequiredUploaded($this->application));
        $this->accept(['civil_id', 'degree', 'transcript_master']);
        ChecklistExemption::factory()->for($this->application)->forItem('transcript_bachelor')->create();
        $this->assertTrue($this->workflow->allRequiredUploaded($this->application), 'stage-2 items and a pending exemption must not block submission');
    }

    public function test_complete_gate_blocks_on_pending_exemption_and_passes_on_accepted_one(): void
    {
        $this->accept(['civil_id', 'degree', 'transcript_master']);
        $exemption = ChecklistExemption::factory()->for($this->application)->forItem('transcript_bachelor')->create();
        $this->assertFalse($this->workflow->allRequiredAccepted($this->application));
        $this->assertTrue($this->workflow->hasUndecidedExemptions($this->application));

        $exemption->update(['status' => ChecklistExemption::STATUS_ACCEPTED, 'decided_at' => now()]);
        $this->assertTrue($this->workflow->allRequiredAccepted($this->application), 'stage-2 items must not block the committee step');
        $this->assertFalse($this->workflow->hasUndecidedExemptions($this->application));
    }

    public function test_mark_complete_message_names_exemptions_when_they_are_the_only_blocker(): void
    {
        $this->accept(['civil_id', 'degree', 'transcript_master']);
        ChecklistExemption::factory()->for($this->application)->forItem('transcript_bachelor')->create();
        $this->application->update(['status' => Application::STATUS_UNDER_REVIEW]);
        try {
            $this->workflow->markComplete($this->application, User::factory()->admin()->create());
            $this->fail('expected DomainException');
        } catch (\DomainException $e) {
            $this->assertSame(__('app.review.complete_blocked_exemptions'), $e->getMessage());
        }
    }

    public function test_stage_two_complete_needs_documents_and_salary(): void
    {
        $this->application->update(['status' => Application::STATUS_APPROVED]);
        $this->assertFalse($this->workflow->stageTwoComplete($this->application));
        $this->assertContains('شهادة راتب حديثة', $this->workflow->stageTwoMissing($this->application));

        $this->accept(self::STAGE2);
        $this->assertFalse($this->workflow->stageTwoComplete($this->application), 'salary still missing');
        $this->assertSame([__('app.profile.salary_missing')], $this->workflow->stageTwoMissing($this->application));

        $this->application->instructor->update(['basic_salary' => '900', 'total_salary' => '1200']);
        $this->assertTrue($this->workflow->stageTwoComplete($this->application->fresh()));
        $this->assertSame([], $this->workflow->stageTwoMissing($this->application->fresh()));
    }

    public function test_stage_two_counts_on_file_copies_but_not_renewing_items(): void
    {
        $this->application->update(['status' => Application::STATUS_APPROVED]);
        $this->application->instructor->update(['basic_salary' => '900', 'total_salary' => '1200']);
        $earlier = Application::factory()->for(Term::factory()->create(['teaching_starts_on' => now()->subYear(), 'teaching_ends_on' => now()->subMonths(8), 'status' => 'closed']))
            ->for($this->application->instructor)->create(['status' => Application::STATUS_APPROVED]);
        foreach (['iban', 'salary_cert'] as $code) {
            Document::factory()->for($earlier)->forItem($code)->accepted()->create();
        }
        $this->accept(['employer_approval', 'undertaking']);
        $rows = $this->workflow->checklist($this->application->fresh());
        $this->assertSame(ApplicationWorkflow::STATE_ON_FILE, $rows['iban']['state']);
        $this->assertSame('missing', $rows['salary_cert']['state'], 'renews each term');
        $this->assertFalse($this->workflow->stageTwoComplete($this->application->fresh()));
    }

    public function test_stage_two_is_never_complete_before_approval(): void
    {
        $this->accept([...self::STAGE1, ...self::STAGE2]);
        $this->application->instructor->update(['basic_salary' => '900', 'total_salary' => '1200']);
        $this->assertFalse($this->workflow->stageTwoComplete($this->application->fresh()));
    }

    public function test_optional_rows_are_ignored_by_every_gate(): void
    {
        ChecklistItem::where('code', 'transcript_master')->update(['optional' => true]);
        $this->accept(['civil_id', 'degree', 'transcript_bachelor']);
        $this->assertTrue($this->workflow->allRequiredUploaded($this->application));
        $this->assertTrue($this->workflow->allRequiredAccepted($this->application));
    }

    public function test_accepts_stage_two_uploads_only_when_approved_on_an_open_term(): void
    {
        $this->assertFalse($this->application->acceptsStageTwoUploads());
        $this->application->update(['status' => Application::STATUS_APPROVED]);
        $this->assertTrue($this->application->fresh()->acceptsStageTwoUploads());
        $this->assertTrue($this->application->instructor->hasApprovedApplicationInOpenTerm());
        $this->application->term->update(['status' => 'closed']);
        $this->assertFalse($this->application->fresh()->acceptsStageTwoUploads());
        $this->assertFalse($this->application->instructor->fresh()->hasApprovedApplicationInOpenTerm());
    }

    public function test_printed_check_list_prints_official_items_only(): void
    {
        $admin = User::factory()->admin()->create();
        $path = app(\App\Services\ChecklistDocument::class)->build($this->application, $admin);
        $zip = new \ZipArchive;
        $zip->open($path);
        $text = html_entity_decode(strip_tags(str_replace('</w:p>', "\n", $zip->getFromName('word/document.xml'))));
        $zip->close();
        @unlink($path);
        $this->assertStringContainsString('شهادة راتب حديثة', $text);
        $this->assertStringNotContainsString('كشف درجات', $text);
    }
}
```

- [ ] **Step 2: Run to see failures**

Run: `php artisan test --compact tests/Feature/StageDerivationTest.php` — Expected: FAIL (`$stage1` undefined, constants missing).

- [ ] **Step 3: ChecklistPlan and resolver**

`app/Services/ChecklistPlan.php`:

```php
<?php

namespace App\Services;

use Illuminate\Support\Collection;

final class ChecklistPlan
{
    public function __construct(
        public readonly Collection $required,
        public readonly Collection $department,
        public readonly Collection $notApplicable,
        public readonly Collection $optional,
        public readonly Collection $stage1,
        public readonly Collection $stage2,
    ) {}

    public function isRequired(string $code): bool
    {
        return $this->required->contains('code', $code);
    }

    /** Required or optional: the applicant may upload it. */
    public function isUploadable(string $code): bool
    {
        return $this->isRequired($code) || $this->optional->contains('code', $code);
    }
}
```

`app/Services/ChecklistResolver.php` `for()`:

```php
        $items = ChecklistItem::orderBy('sort_order')->get();
        $applicable = $items->filter(fn ($i) => ! $i->isDepartment() && $i->appliesTo($instructor));
        $required = $applicable->reject(fn ($i) => $i->optional);

        return new ChecklistPlan(
            required: $required->sortBy([['stage', 'asc'], ['sort_order', 'asc']])->values(),
            department: $items->filter(fn ($i) => $i->isDepartment())->values(),
            notApplicable: $items->filter(fn ($i) => ! $i->isDepartment() && ! $i->appliesTo($instructor))->values(),
            optional: $applicable->filter(fn ($i) => $i->optional)->values(),
            stage1: $required->where('stage', ChecklistItem::STAGE_COMMITTEE)->values(),
            stage2: $required->where('stage', ChecklistItem::STAGE_AFTER_APPROVAL)->values(),
        );
```

- [ ] **Step 4: Workflow derivation and gates**

In `app/Services/ApplicationWorkflow.php`:

Constants after `STATE_ON_FILE`:

```php
    public const STATE_EXEMPTION_REQUESTED = 'exemption_requested';

    public const STATE_EXEMPTED = 'exempted';

    /** Row states that satisfy a required item. */
    public const SATISFIED_STATES = [Document::STATUS_ACCEPTED, self::STATE_ON_FILE, self::STATE_EXEMPTED];
```

Replace `checklist()`:

```php
    /**
     * Spec 5b §4. Stage-1 rows first, then stage-2, then optional rows:
     * ['item', 'document' (head, parts loaded), 'state', 'source', 'renewal', 'exemption', 'stage', 'optional']
     * state ∈ missing|pending|accepted|rejected|on_file|exemption_requested|exempted.
     *
     * @return array<string, array{item: ChecklistItem, document: ?Document, state: string, source: ?Document, renewal: ?ChecklistRenewal, exemption: ?ChecklistExemption, stage: int, optional: bool}>
     */
    public function checklist(Application $application): array
    {
        $plan = $this->resolver->for($application->instructor);
        $docs = $application->latestDocuments();
        $renewals = $application->renewals()->get()->keyBy('checklist_item_id');
        $exemptions = $application->exemptions()->get()->keyBy('checklist_item_id');
        $onFile = null;
        $out = [];
        foreach ($plan->required->concat($plan->optional) as $item) {
            $doc = $docs->get($item->code);
            $exemption = $exemptions->get($item->id);
            $row = ['item' => $item, 'document' => $doc, 'state' => 'missing', 'source' => null,
                'renewal' => $renewals->get($item->id), 'exemption' => $exemption,
                'stage' => (int) $item->stage, 'optional' => (bool) $item->optional];
            if ($doc) {
                $row['state'] = $doc->status;
            } elseif ($exemption?->status === ChecklistExemption::STATUS_ACCEPTED) {
                $row['state'] = self::STATE_EXEMPTED;
            } elseif ($exemption?->status === ChecklistExemption::STATUS_PENDING) {
                $row['state'] = self::STATE_EXEMPTION_REQUESTED;
            } elseif ($row['renewal'] === null && ! $item->renews_each_term
                // Rule 4 is skipped for final applications so their record does not flip once the card expires.
                && ! ($item->code === 'civil_id' && ! $application->isFinal() && $application->instructor->civilIdExpired())) {
                $onFile ??= $this->onFileDocuments($application);
                if ($source = $onFile->get($item->code)) {
                    $row['state'] = self::STATE_ON_FILE;
                    $row['source'] = $source;
                }
            }
            $out[$item->code] = $row;
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> stage-1 required rows only */
    private function stageOneRows(Application $application): array
    {
        return array_filter($this->checklist($application), fn ($row) => $row['stage'] === ChecklistItem::STAGE_COMMITTEE && ! $row['optional']);
    }

    /** @return array<string, array<string, mixed>> stage-2 required rows only */
    private function stageTwoRows(Application $application): array
    {
        return array_filter($this->checklist($application), fn ($row) => $row['stage'] === ChecklistItem::STAGE_AFTER_APPROVAL && ! $row['optional']);
    }
```

Replace `allRequiredAccepted()` and `allRequiredUploaded()`:

```php
    /** Committee gate (spec 5b §5): every stage-1 item accepted, on file or exempted. */
    public function allRequiredAccepted(Application $application): bool
    {
        foreach ($this->stageOneRows($application) as $row) {
            if (! in_array($row['state'], self::SATISFIED_STATES, true)) {
                return false;
            }
        }

        return true;
    }

    /** Submission gate (spec 5b §5): every stage-1 item uploaded, on file, exempted or exemption-requested. */
    public function allRequiredUploaded(Application $application): bool
    {
        foreach ($this->stageOneRows($application) as $row) {
            if ($row['state'] === 'missing' || $row['state'] === Document::STATUS_REJECTED) {
                return false;
            }
        }

        return true;
    }

    public function hasUndecidedExemptions(Application $application): bool
    {
        foreach ($this->stageOneRows($application) as $row) {
            if ($row['state'] === self::STATE_EXEMPTION_REQUESTED) {
                return true;
            }
        }

        return false;
    }

    /** Spec 5b §5: approved, every stage-2 item satisfied, both salary fields present. */
    public function stageTwoComplete(Application $application): bool
    {
        return $application->status === Application::STATUS_APPROVED && $this->stageTwoMissing($application) === [];
    }

    /** Labels of what still blocks stage 2 (documents by label, then the salary line). Empty when nothing is missing or the application is not approved. */
    public function stageTwoMissing(Application $application): array
    {
        if ($application->status !== Application::STATUS_APPROVED) {
            return [];
        }
        $missing = [];
        foreach ($this->stageTwoRows($application) as $row) {
            if (! in_array($row['state'], self::SATISFIED_STATES, true)) {
                $missing[] = $row['item']->label_ar;
            }
        }
        $i = $application->instructor;
        if ($i->basic_salary === null || $i->total_salary === null) {
            $missing[] = __('app.profile.salary_missing');
        }

        return $missing;
    }
```

Wait: `stageTwoMissing()` returns `[]` for non-approved applications but `stageTwoComplete()` must be false then — it is, because of the status check. Keep both as written.

In `markComplete()`, replace the `allRequiredAccepted` check with:

```php
        if (! $this->allRequiredAccepted($application)) {
            throw new \DomainException($this->hasUndecidedExemptions($application) && $this->onlyExemptionsBlock($application)
                ? __('app.review.complete_blocked_exemptions') : __('app.review.complete_blocked'));
        }
```

and add:

```php
    private function onlyExemptionsBlock(Application $application): bool
    {
        foreach ($this->stageOneRows($application) as $row) {
            if (! in_array($row['state'], [...self::SATISFIED_STATES, self::STATE_EXEMPTION_REQUESTED], true)) {
                return false;
            }
        }

        return true;
    }
```

Add `use App\Models\ChecklistExemption;` to the imports.

- [ ] **Step 5: Model helpers**

`app/Models/Application.php` after `isFinal()`:

```php
    /** Spec 5b §5: stage-2 documents may be uploaded and reviewed while approved on an open term. */
    public function acceptsStageTwoUploads(): bool
    {
        return $this->status === self::STATUS_APPROVED && $this->term->isOpen();
    }
```

`app/Models/Instructor.php` after `hasLockedApplication()`:

```php
    public function hasApprovedApplicationInOpenTerm(): bool
    {
        return $this->applications()->where('status', Application::STATUS_APPROVED)
            ->whereHas('term', fn ($q) => $q->open())->exists();
    }
```

- [ ] **Step 6: Printed Check List uses `official`**

`app/Services/ChecklistDocument.php`: change the loop query to `ChecklistItem::where('official', true)->orderBy('sort_order')->get()`. The `isset($checklist[$item->code])` branch keeps printing ☑ for `SATISFIED_STATES` (replace the `in_array(..., ['accepted', ApplicationWorkflow::STATE_ON_FILE])` with `in_array(..., ApplicationWorkflow::SATISFIED_STATES, true)`).

- [ ] **Step 7: Lang keys**

`lang/ar/app.php`:
- `review`: `'complete_blocked_exemptions' => 'هناك طلبات إعفاء لم يبت فيها، اقبلها أو ارفضها أولا.',`
- `profile`: `'salary_missing' => 'بيانات الراتب (الأساسي والإجمالي)',`
- `documents.states`: add `'exemption_requested' => 'طلب إعفاء قيد النظر', 'exempted' => 'معفى'`.

`lang/en/app.php` twins: `'complete_blocked_exemptions' => 'There are undecided exemption requests; accept or reject them first.'`, `'salary_missing' => 'Salary details (basic and total)'`, states `'exemption_requested' => 'Exemption requested', 'exempted' => 'Exempted'`.

- [ ] **Step 8: Run the suite, Pint, commit**

Run: `php artisan test --compact` — Expected: green. `vendor/bin/pint --quiet app/Services/ChecklistPlan.php app/Services/ChecklistResolver.php app/Services/ApplicationWorkflow.php app/Services/ChecklistDocument.php app/Models/Application.php app/Models/Instructor.php tests/Feature/StageDerivationTest.php`.

```bash
git add -A
git commit -m "feat(workflow): stage-1 gates, exemption states, stage-2 readiness

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 3: Exemption requests (workflow, routes, notices)

**Files:**
- Modify: `app/Services/ApplicationWorkflow.php`, `routes/web.php`, `app/Http/Controllers/Instructor/ApplicationController.php`, `app/Http/Controllers/Admin/ApplicationController.php`, `app/Policies/ApplicationPolicy.php`, `app/Mail/DocumentsRejected.php`, `resources/views/emails/documents-rejected.blade.php`, `lang/ar/app.php`, `lang/en/app.php`
- Create: `app/Http/Requests/ExemptionRequest.php`, `app/Http/Requests/DecideExemptionRequest.php`
- Test: `tests/Feature/ExemptionsTest.php`

**Interfaces:**
- Produces: `ApplicationWorkflow::requestExemption(Application, ChecklistItem, string $reason): void`, `decideExemption(ChecklistExemption, User $admin, string $status, ?string $note): void`; routes `instructor.exemptions.store` (POST `my/applications/{application}/exemptions/{item:code}`), `admin.exemptions.decide` (POST `admin/exemptions/{exemption}/decide`); `ApplicationPolicy::requestExemption(User, Application, ChecklistItem)`.
- `pendingRejectionNotices()` rows may now have `exemption` rejected and un-notified; `DocumentsRejected` lists them.

- [ ] **Step 1: Failing tests**

`tests/Feature/ExemptionsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Mail\DocumentsRejected;
use App\Models\Application;
use App\Models\ChecklistExemption;
use App\Models\ChecklistItem;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use App\Services\ApplicationWorkflow;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ExemptionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $admin;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(ChecklistItemSeeder::class);
        $this->user = User::factory()->instructor()->create();
        $this->admin = User::factory()->admin()->create();
        $instructor = Instructor::factory()->for($this->user)->create();
        $this->application = Application::factory()->for(Term::factory()->open())->for($instructor)->create();
    }

    private function request(string $code, array $data = ['reason' => 'الجامعة لا تصدر كشوف قديمة'], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->post(route('instructor.exemptions.store', [$this->application, $code]), $data);
    }

    public function test_applicant_requests_an_exemption_and_it_is_audited(): void
    {
        $this->request('transcript_bachelor')->assertRedirect(route('instructor.applications.show', $this->application));
        $this->assertDatabaseHas('checklist_exemptions', ['application_id' => $this->application->id, 'status' => 'pending', 'reason' => 'الجامعة لا تصدر كشوف قديمة']);
        $this->assertDatabaseHas('audit_log', ['action' => 'request_exemption', 'user_id' => $this->user->id, 'details' => 'transcript_bachelor']);
        $this->assertSame(ApplicationWorkflow::STATE_EXEMPTION_REQUESTED, app(ApplicationWorkflow::class)->checklist($this->application)['transcript_bachelor']['state']);
    }

    public function test_reason_is_required_and_capped(): void
    {
        $this->request('transcript_bachelor', ['reason' => ''])->assertSessionHasErrors('reason');
        $this->request('transcript_bachelor', ['reason' => str_repeat('س', 501)])->assertSessionHasErrors('reason');
    }

    public function test_refused_for_non_exemptable_stage_two_submitted_or_stranger(): void
    {
        $this->request('degree')->assertForbidden();
        $this->request('iban')->assertForbidden();
        $this->request('transcript_bachelor', as: User::factory()->instructor()->create())->assertForbidden();
        $this->application->update(['status' => Application::STATUS_SUBMITTED]);
        $this->request('transcript_bachelor')->assertForbidden();
    }

    public function test_refused_when_a_document_is_already_uploaded_or_request_pending(): void
    {
        Document::factory()->for($this->application)->forItem('transcript_bachelor')->create();
        $this->request('transcript_bachelor')->assertSessionHasErrors('exemption');
        $this->application->documents()->delete();
        $this->request('transcript_bachelor')->assertRedirect();
        $this->request('transcript_bachelor')->assertSessionHasErrors('exemption');
    }

    public function test_re_request_after_rejection_resets_the_row(): void
    {
        $e = ChecklistExemption::factory()->for($this->application)->forItem('transcript_bachelor')->rejected()->create(['decided_by' => $this->admin->id, 'notified_at' => now()]);
        $this->application->update(['status' => Application::STATUS_INCOMPLETE]);
        $this->request('transcript_bachelor', ['reason' => 'سبب جديد'])->assertRedirect();
        $e->refresh();
        $this->assertSame('pending', $e->status);
        $this->assertSame('سبب جديد', $e->reason);
        $this->assertNull($e->decided_by);
        $this->assertNull($e->decision_note);
        $this->assertNull($e->notified_at);
    }

    public function test_admin_accepts_and_rejects_with_audit_and_status_change(): void
    {
        $this->application->update(['status' => Application::STATUS_UNDER_REVIEW]);
        $a = ChecklistExemption::factory()->for($this->application)->forItem('transcript_bachelor')->create();
        $b = ChecklistExemption::factory()->for($this->application)->forItem('transcript_master')->create();

        $this->actingAs($this->admin)->post(route('admin.exemptions.decide', $a), ['status' => 'accepted'])->assertRedirect();
        $this->assertSame('accepted', $a->fresh()->status);
        $this->assertSame($this->admin->id, $a->fresh()->decided_by);
        $this->assertDatabaseHas('audit_log', ['action' => 'exemption_accepted', 'details' => 'transcript_bachelor']);
        $this->assertSame(Application::STATUS_UNDER_REVIEW, $this->application->fresh()->status);

        $this->actingAs($this->admin)->post(route('admin.exemptions.decide', $b), ['status' => 'rejected'])->assertSessionHasErrors('decision_note');
        $this->actingAs($this->admin)->post(route('admin.exemptions.decide', $b), ['status' => 'rejected', 'decision_note' => 'اطلبه من الجامعة'])->assertRedirect();
        $this->assertSame('rejected', $b->fresh()->status);
        $this->assertDatabaseHas('audit_log', ['action' => 'exemption_rejected', 'details' => 'transcript_master']);
        $this->assertSame(Application::STATUS_INCOMPLETE, $this->application->fresh()->status);
        $this->assertDatabaseMissing('audit_log', ['details' => 'اطلبه من الجامعة']);
    }

    public function test_decide_refused_for_instructor_non_pending_and_closed_term(): void
    {
        $this->application->update(['status' => Application::STATUS_UNDER_REVIEW]);
        $e = ChecklistExemption::factory()->for($this->application)->forItem('transcript_bachelor')->accepted()->create();
        $this->actingAs($this->user)->post(route('admin.exemptions.decide', $e), ['status' => 'accepted'])->assertForbidden();
        $this->actingAs($this->admin)->post(route('admin.exemptions.decide', $e), ['status' => 'accepted'])->assertSessionHasErrors('exemption');
        $p = ChecklistExemption::factory()->for($this->application)->forItem('transcript_master')->create();
        $this->application->term->update(['status' => 'closed']);
        $this->actingAs($this->admin)->post(route('admin.exemptions.decide', $p), ['status' => 'accepted'])->assertSessionHasErrors('exemption');
    }

    public function test_rejected_exemption_is_in_the_consolidated_notice_once(): void
    {
        $this->application->update(['status' => Application::STATUS_INCOMPLETE]);
        ChecklistExemption::factory()->for($this->application)->forItem('transcript_bachelor')->rejected('اطلبه من الجامعة')->create();
        $workflow = app(ApplicationWorkflow::class);
        $this->assertCount(1, $workflow->pendingRejectionNotices($this->application));

        $workflow->notifyRejections($this->application, $this->admin);
        Mail::assertSent(DocumentsRejected::class, fn ($m) => str_contains($m->render(), 'اطلبه من الجامعة') && str_contains($m->render(), 'كشف درجات البكالوريوس'));
        $this->assertSame([], $workflow->pendingRejectionNotices($this->application->fresh()));
    }
}
```

- [ ] **Step 2: Run to see failures** — `php artisan test --compact tests/Feature/ExemptionsTest.php` → route not defined.

- [ ] **Step 3: Workflow methods**

Add to `ApplicationWorkflow`:

```php
    /** Spec 5b §6: the applicant asks to be exempted from an exemptable stage-1 item instead of uploading it. */
    public function requestExemption(Application $application, ChecklistItem $item, string $reason): void
    {
        $row = $this->checklist($application)[$item->code] ?? null;
        if ($row === null || $row['document'] !== null || in_array($row['state'], [self::STATE_EXEMPTION_REQUESTED, self::STATE_EXEMPTED], true)) {
            throw new \DomainException(__('app.exemptions.cannot_request'));
        }

        DB::transaction(function () use ($application, $item, $reason) {
            ChecklistExemption::updateOrCreate(
                ['application_id' => $application->id, 'checklist_item_id' => $item->id],
                ['reason' => $reason, 'requested_at' => now(), 'status' => ChecklistExemption::STATUS_PENDING,
                    'decided_by' => null, 'decided_at' => null, 'decision_note' => null, 'notified_at' => null],
            );
            AuditLog::record($application->instructor->user_id, 'request_exemption', $application, null, $item->code);
        });
    }

    /** Spec 5b §6: the admin accepts or rejects a pending exemption; a rejection sends the file back to the applicant. */
    public function decideExemption(ChecklistExemption $exemption, User $admin, string $status, ?string $note): void
    {
        $application = $exemption->application;
        if (! $application->term->isOpen()) {
            throw new \DomainException(__('app.applications.term_closed'));
        }
        if (! $exemption->isPending() || ! in_array($application->status, Application::REVIEWABLE_STATUSES, true)) {
            throw new \DomainException(__('app.exemptions.cannot_decide'));
        }

        DB::transaction(function () use ($exemption, $application, $admin, $status, $note) {
            $exemption->update(['status' => $status, 'decided_by' => $admin->id, 'decided_at' => now(),
                'decision_note' => $status === ChecklistExemption::STATUS_REJECTED ? $note : null, 'notified_at' => null]);
            AuditLog::record($admin->id, 'exemption_'.$status, $application, null, $exemption->item->code);
            if ($status === ChecklistExemption::STATUS_REJECTED) {
                $application->update(['status' => Application::STATUS_INCOMPLETE, 'complete_at' => null, 'reviewed_at' => $application->reviewed_at ?? now()]);
            }
        });
    }
```

Extend the notice helpers:

```php
    private function needsNotice(array $row): bool
    {
        return $row['state'] === Document::STATUS_REJECTED
            || ($row['renewal'] !== null && $row['document'] === null)
            || ($row['document'] === null && $row['exemption']?->status === ChecklistExemption::STATUS_REJECTED);
    }

    private function noticeSent(array $row): bool
    {
        if ($row['state'] === Document::STATUS_REJECTED) {
            return $row['document']->notified_at !== null;
        }
        if ($row['renewal'] !== null) {
            return $row['renewal']->notified_at !== null;
        }

        return $row['exemption']->notified_at !== null;
    }
```

In `notifyRejections()`, the per-row update becomes:

```php
        foreach ($rows as $row) {
            $target = $row['state'] === Document::STATUS_REJECTED ? $row['document'] : ($row['renewal'] ?? $row['exemption']);
            $target->update(['notified_at' => now()]);
        }
```

- [ ] **Step 4: Requests, policy, routes, controllers**

`app/Http/Requests/ExemptionRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExemptionRequest extends FormRequest
{
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }

    public function attributes(): array
    {
        return ['reason' => __('app.exemptions.reason')];
    }
}
```

`app/Http/Requests/DecideExemptionRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\ChecklistExemption;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideExemptionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([ChecklistExemption::STATUS_ACCEPTED, ChecklistExemption::STATUS_REJECTED])],
            'decision_note' => ['nullable', 'string', 'max:500', Rule::requiredIf(fn () => $this->status === ChecklistExemption::STATUS_REJECTED)],
        ];
    }

    public function attributes(): array
    {
        return ['decision_note' => __('app.exemptions.decision_note')];
    }
}
```

`app/Policies/ApplicationPolicy.php` add:

```php
    /** Called as: $user->can('requestExemption', [$application, $item]) */
    public function requestExemption(User $user, Application $application, ChecklistItem $item): bool
    {
        return $this->owns($user, $application) && $application->isEditable()
            && $item->exemptable && $item->stage === ChecklistItem::STAGE_COMMITTEE
            && app(ChecklistResolver::class)->for($application->instructor)->isRequired($item->code);
    }
```

(imports `App\Models\ChecklistItem`, `App\Services\ChecklistResolver`).

`routes/web.php` — instructor group:

```php
    Route::post('applications/{application}/exemptions/{item:code}', [InstructorApplicationController::class, 'requestExemption'])->name('exemptions.store')->withoutScopedBindings();
```

admin group:

```php
    Route::post('exemptions/{exemption}/decide', [AdminApplicationController::class, 'decideExemption'])->name('exemptions.decide');
```

`Instructor\ApplicationController`:

```php
    public function requestExemption(ExemptionRequest $request, Application $application, ChecklistItem $item): RedirectResponse
    {
        $this->authorize('requestExemption', [$application, $item]);
        try {
            $this->workflow->requestExemption($application, $item, $request->reason);
        } catch (\DomainException $e) {
            return back()->withErrors(['exemption' => $e->getMessage()]);
        }

        return redirect()->route('instructor.applications.show', $application)->with('status', __('app.exemptions.requested'));
    }
```

`Admin\ApplicationController`:

```php
    public function decideExemption(DecideExemptionRequest $request, ChecklistExemption $exemption): RedirectResponse
    {
        $this->authorize('review', $exemption->application);
        try {
            $this->workflow->decideExemption($exemption, $request->user(), $request->status, $request->decision_note);
        } catch (\DomainException $e) {
            return back()->withErrors(['exemption' => $e->getMessage()]);
        }

        return back()->with('status', __('app.exemptions.decided'));
    }
```

- [ ] **Step 5: Mail**

`app/Mail/DocumentsRejected.php` docblock gains `exemption: ?ChecklistExemption`. `resources/views/emails/documents-rejected.blade.php` list item:

```blade
            <li>
                {{ $row['item']->label_ar }}
                @if ($row['state'] === 'rejected' && $row['document']?->rejection_reason)
                    — {{ $row['document']->rejection_reason }}
                @elseif ($row['renewal'])
                    — {{ __('app.documents.renewal_requested', ['reason' => $row['renewal']->reason], 'ar') }}
                @elseif ($row['exemption']?->status === 'rejected')
                    — {{ __('app.exemptions.rejected_line', ['note' => $row['exemption']->decision_note], 'ar') }}
                @endif
            </li>
```

- [ ] **Step 6: Lang** — new `exemptions` section in `lang/ar/app.php`:

```php
    'exemptions' => [
        'request' => 'طلب إعفاء', 'reason' => 'سبب طلب الإعفاء', 'requested' => 'تم إرسال طلب الإعفاء، وسيبت فيه القسم.',
        'pending' => 'طلب إعفاء قيد النظر', 'accepted' => 'تم قبول طلب الإعفاء', 'rejected' => 'تم رفض طلب الإعفاء',
        'rejected_line' => 'رفض طلب الإعفاء: :note',
        'decision_note' => 'سبب الرفض', 'accept' => 'قبول الإعفاء', 'reject' => 'رفض الإعفاء',
        'decided' => 'تم تسجيل القرار في طلب الإعفاء.',
        'cannot_request' => 'لا يمكن طلب الإعفاء لهذا البند في حالته الحالية.',
        'cannot_decide' => 'لا يمكن البت في طلب الإعفاء في حالته الحالية.',
        'applicant_reason' => 'سبب الطلب',
    ],
```

`lang/en/app.php` twins: Request exemption / Exemption reason / Your exemption request has been sent; the department will decide. / Exemption requested / Exemption accepted / Exemption rejected / Exemption rejected: :note / Rejection reason / Accept exemption / Reject exemption / The exemption decision has been recorded. / An exemption cannot be requested for this item in its current state. / The exemption request cannot be decided in its current state. / Applicant's reason.

- [ ] **Step 7: Suite, Pint, commit**

```bash
git add -A
git commit -m "feat(exemptions): transcript exemption requests, admin decision, notice

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 4: Uploads and review after approval

**Files:**
- Modify: `app/Policies/DocumentPolicy.php`, `app/Services/ApplicationWorkflow.php` (`reviewDocument`, `notifyRejections`, `requestFreshCopy`), `resources/views/instructor/_upload.blade.php`, `resources/views/admin/applications/show.blade.php` (review-button condition only)
- Test: `tests/Feature/ApprovedPhaseTest.php`

**Interfaces:**
- Consumes: `Application::acceptsStageTwoUploads()`, `ChecklistItem::isStageTwo()`.
- Produces: `DocumentPolicy::create` allows stage-2/optional uploads while approved; `reviewDocument()` accepts stage-2 reviews while approved without status change; `notifyRejections()` works while approved; `requestFreshCopy()` works on approved applications for stage-2 on-file rows without setting `incomplete`.

- [ ] **Step 1: Failing tests**

`tests/Feature/ApprovedPhaseTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Mail\DocumentsRejected;
use App\Models\Application;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use App\Services\ApplicationWorkflow;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApprovedPhaseTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $admin;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        $this->seed(ChecklistItemSeeder::class);
        $this->user = User::factory()->instructor()->create();
        $this->admin = User::factory()->admin()->create();
        $instructor = Instructor::factory()->for($this->user)->create();
        $this->application = Application::factory()->approved()->for(Term::factory()->open())->for($instructor)->create();
        foreach (['civil_id', 'degree', 'transcript_bachelor', 'transcript_master'] as $code) {
            Document::factory()->for($this->application)->forItem($code)->accepted()->create();
        }
    }

    private function upload(string $code)
    {
        return $this->actingAs($this->user)->post(route('instructor.documents.store', [$this->application, $code]), ['file' => UploadedFile::fake()->create('f.pdf', 10, 'application/pdf')]);
    }

    public function test_stage_two_upload_allowed_stage_one_refused_after_approval(): void
    {
        $this->upload('iban')->assertRedirect();
        $this->assertSame('pending', app(ApplicationWorkflow::class)->checklist($this->application)['iban']['state']);
        $this->upload('degree')->assertForbidden();
        $this->assertSame(Application::STATUS_APPROVED, $this->application->fresh()->status);
    }

    public function test_stage_two_upload_refused_on_closed_term(): void
    {
        $this->application->term->update(['status' => 'closed']);
        $this->upload('iban')->assertForbidden();
    }

    public function test_admin_reviews_stage_two_after_approval_without_status_change(): void
    {
        $doc = Document::factory()->for($this->application)->forItem('iban')->create();
        $this->actingAs($this->admin)->post(route('admin.documents.review', $doc), ['status' => 'accepted'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('accepted', $doc->fresh()->status);

        $doc2 = Document::factory()->for($this->application)->forItem('undertaking')->create();
        $this->actingAs($this->admin)->post(route('admin.documents.review', $doc2), ['status' => 'rejected', 'reason' => 'غير موقع'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('rejected', $doc2->fresh()->status);
        $this->assertSame(Application::STATUS_APPROVED, $this->application->fresh()->status, 'a stage-2 rejection never changes the status');
    }

    public function test_stage_one_review_refused_after_approval(): void
    {
        $doc = $this->application->latestDocuments()->get('degree');
        $this->actingAs($this->admin)->post(route('admin.documents.review', $doc), ['status' => 'rejected', 'reason' => 'x'])->assertSessionHasErrors('review');
        $this->assertSame('accepted', $doc->fresh()->status);
    }

    public function test_rejection_notice_works_while_approved(): void
    {
        Document::factory()->for($this->application)->forItem('undertaking')->rejected('غير موقع')->create();
        $this->actingAs($this->admin)->post(route('admin.applications.notify_rejections', $this->application))->assertRedirect()->assertSessionHasNoErrors();
        Mail::assertSent(DocumentsRejected::class);
        $this->assertSame(Application::STATUS_APPROVED, $this->application->fresh()->status);
    }

    public function test_fresh_copy_on_stage_two_on_file_row_keeps_approved_status(): void
    {
        $earlier = Application::factory()->for(Term::factory()->create(['teaching_starts_on' => now()->subYear(), 'teaching_ends_on' => now()->subMonths(8), 'status' => 'closed']))
            ->for($this->application->instructor)->create(['status' => Application::STATUS_APPROVED]);
        Document::factory()->for($earlier)->forItem('iban')->accepted()->create();
        $this->assertSame(ApplicationWorkflow::STATE_ON_FILE, app(ApplicationWorkflow::class)->checklist($this->application)['iban']['state']);

        $this->actingAs($this->admin)->post(route('admin.applications.renewals.store', [$this->application, 'iban']), ['reason' => 'تغير البنك'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(Application::STATUS_APPROVED, $this->application->fresh()->status);
        $this->assertSame('missing', app(ApplicationWorkflow::class)->checklist($this->application->fresh())['iban']['state']);
    }
}
```

- [ ] **Step 2: Run to see failures** — stage-2 upload returns 403 today.

- [ ] **Step 3: Policy**

`app/Policies/DocumentPolicy.php::create`:

```php
    public function create(User $user, Application $application, ChecklistItem $item): bool
    {
        if (! $this->owns($user, $application)) {
            return false;
        }
        $plan = app(ChecklistResolver::class)->for($application->instructor);
        if (! $plan->isUploadable($item->code)) {
            return false;
        }
        if ($application->isEditable()) {
            return true;
        }

        // Spec 5b §5: after approval only stage-2 (and optional) items may still be uploaded.
        return $application->acceptsStageTwoUploads() && ($item->isStageTwo() || $item->optional);
    }
```

- [ ] **Step 4: Workflow**

`reviewDocument()` — replace the first guard:

```php
        $application = $document->application;
        $stageTwoReview = $application->status === Application::STATUS_APPROVED
            && ($document->checklistItem->isStageTwo() || $document->checklistItem->optional);
        if ($application->isFinal() && ! $stageTwoReview) {
            throw new \DomainException(__('app.review.already_final'));
        }
```

and the tail stays: `if ($status === REJECTED && ! $application->isFinal())` → `incomplete` (approved is final, so no change). Use `$application = $document->application->fresh()` as today for the tail.

`notifyRejections()` — no code change needed beyond the guard `term->isOpen()`; verify the admin page button condition (`! $application->isFinal()`) is relaxed in Task 8. For the test here, call the route directly: the controller has no final-status guard, so it passes once `needsNotice` works. If the controller or workflow has an `isFinal()` guard, remove it.

`requestFreshCopy()` — replace the status guard and the status update:

```php
        $approvedStageTwo = $application->status === Application::STATUS_APPROVED && $item->isStageTwo();
        if (! in_array($application->status, Application::UNFINISHED_STATUSES, true) && ! $approvedStageTwo) {
            throw new \DomainException(__('app.review.fresh_copy_wrong_status'));
        }
        ...
            if (! $approvedStageTwo) {
                $application->update(['status' => Application::STATUS_INCOMPLETE, 'complete_at' => null, 'reviewed_at' => $application->reviewed_at ?? now()]);
            }
```

- [ ] **Step 5: Upload partial condition**

`resources/views/instructor/_upload.blade.php` first line: `@if ($application->isEditable() || ($application->acceptsStageTwoUploads() && ($item->isStageTwo() || $item->optional)))`.

`resources/views/admin/applications/show.blade.php` review-buttons condition (`@if ($document && ! $application->isFinal() && $termOpen)`) becomes `@if ($document && $termOpen && (! $application->isFinal() || ($application->status === \App\Models\Application::STATUS_APPROVED && ($item->isStageTwo() || $item->optional))))`; the fresh-copy `@elseif` adds `|| ($application->status === \App\Models\Application::STATUS_APPROVED && $item->isStageTwo())` to its status condition; the notify button condition drops `! $application->isFinal()`.

- [ ] **Step 6: Suite, Pint, commit**

```bash
git add -A
git commit -m "feat(workflow): stage-2 uploads, reviews, notices and fresh copies after approval

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 5: Salary after approval

**Files:**
- Modify: `app/Http/Requests/ProfileRequest.php`, `routes/web.php`, `app/Http/Controllers/Instructor/ProfileController.php`, `resources/views/instructor/_profile_fields.blade.php`, `lang/ar/app.php`, `lang/en/app.php`
- Create: `app/Http/Requests/SalaryRequest.php`, `resources/views/instructor/_salary_form.blade.php`
- Test: `tests/Feature/SalaryAfterApprovalTest.php`

**Interfaces:**
- Produces: route `instructor.salary.update` (PUT `my/salary`), `ProfileController::updateSalary(SalaryRequest)`, partial `instructor._salary_form` (expects `$instructor`), lang `app.profile.salary_later`, `app.profile.salary_title`, `app.profile.salary_saved`, `app.profile.salary_not_yet`.
- Task 8 includes the partial in the stage-2 section.

- [ ] **Step 1: Failing tests**

`tests/Feature/SalaryAfterApprovalTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Instructor\ProfileTest;
use Tests\TestCase;

class SalaryAfterApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChecklistItemSeeder::class);
        $this->user = User::factory()->instructor()->create();
    }

    public function test_profile_saves_without_salary_before_approval(): void
    {
        $payload = ProfileTest::payload();
        unset($payload['basic_salary'], $payload['total_salary']);
        $this->actingAs($this->user)->put(route('instructor.profile.update'), $payload)->assertRedirect(route('instructor.home'))->assertSessionHasNoErrors();
        $this->assertNull($this->user->instructor->fresh()->basic_salary);
    }

    public function test_total_must_not_be_below_basic_when_both_given(): void
    {
        $this->actingAs($this->user)->put(route('instructor.profile.update'), ProfileTest::payload(['basic_salary' => '1000', 'total_salary' => '900']))->assertSessionHasErrors('total_salary');
    }

    public function test_salary_route_forbidden_before_approval_and_for_strangers(): void
    {
        $instructor = Instructor::factory()->for($this->user)->create(['basic_salary' => null, 'total_salary' => null]);
        $this->actingAs($this->user)->put(route('instructor.salary.update'), ['basic_salary' => '900', 'total_salary' => '1200'])->assertForbidden();
        Application::factory()->approved()->for(Term::factory()->open())->for($instructor)->create();
        $this->actingAs(User::factory()->instructor()->create())->put(route('instructor.salary.update'), ['basic_salary' => '900', 'total_salary' => '1200'])->assertForbidden();
    }

    public function test_salary_saved_after_approval_despite_profile_lock_and_audited_with_names_only(): void
    {
        $instructor = Instructor::factory()->for($this->user)->create(['basic_salary' => null, 'total_salary' => null]);
        Application::factory()->approved()->for(Term::factory()->open())->for($instructor)->create();
        $this->assertTrue($instructor->hasLockedApplication());

        $this->actingAs($this->user)->put(route('instructor.salary.update'), ['basic_salary' => '900', 'total_salary' => '1200'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('900', $instructor->fresh()->basic_salary);
        $this->assertDatabaseHas('audit_log', ['action' => 'edit_profile', 'user_id' => $this->user->id, 'details' => 'basic_salary,total_salary']);
        $this->assertDatabaseMissing('audit_log', ['details' => '900']);

        $this->actingAs($this->user)->from(route('instructor.home'))->put(route('instructor.salary.update'), ['basic_salary' => 'abc', 'total_salary' => '1200'])
            ->assertRedirect(route('instructor.home'))->assertSessionHasErrors('basic_salary');
    }
}
```

- [ ] **Step 2: Run to see failures** — route missing / salary required.

- [ ] **Step 3: Validation**

`ProfileRequest::rules()`: `'basic_salary' => ['nullable', 'numeric', 'min:0', 'max:99999']`, `'total_salary' => ['nullable', 'numeric', 'min:0', 'max:99999', 'gte:basic_salary']` (Laravel's `gte` on a nullable field compares only when both present — verify with the test; if `gte` fails on a missing `basic_salary`, use `Rule::when($this->filled('basic_salary'), ['gte:basic_salary'])`).

`app/Http/Requests/SalaryRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SalaryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'basic_salary' => ['required', 'numeric', 'min:0', 'max:99999'],
            'total_salary' => ['required', 'numeric', 'min:0', 'max:99999', 'gte:basic_salary'],
        ];
    }

    public function attributes(): array
    {
        return ['basic_salary' => __('app.profile.basic_salary'), 'total_salary' => __('app.profile.total_salary')];
    }
}
```

- [ ] **Step 4: Route and controller**

`routes/web.php` instructor group: `Route::put('salary', [ProfileController::class, 'updateSalary'])->name('salary.update');`

`ProfileController`:

```php
    /** Spec 5b §7: salary is entered after approval, while the rest of the profile is locked. */
    public function updateSalary(SalaryRequest $request): RedirectResponse
    {
        $instructor = $request->user()->instructor;
        abort_unless($instructor && $instructor->hasApprovedApplicationInOpenTerm(), 403);

        $instructor->update($request->only('basic_salary', 'total_salary'));
        AuditLog::record($request->user()->id, 'edit_profile', $instructor, null, 'basic_salary,total_salary');

        return back()->with('status', __('app.profile.salary_saved'));
    }
```

- [ ] **Step 5: Views and lang**

`resources/views/instructor/_salary_form.blade.php`:

```blade
<div class="card mb-3 border-warning">
    <div class="card-header">{{ __('app.profile.salary_title') }}</div>
    <div class="card-body">
        <form method="post" action="{{ route('instructor.salary.update') }}" class="row g-2 g-md-3 align-items-end">
            @csrf
            @method('put')
            <div class="col-6 col-md-4"><label class="form-label mb-1">{{ __('app.profile.basic_salary') }}</label>
                <input type="number" step="0.001" name="basic_salary" value="{{ old('basic_salary') }}" class="form-control" dir="ltr" inputmode="decimal" autocomplete="off" required></div>
            <div class="col-6 col-md-4"><label class="form-label mb-1">{{ __('app.profile.total_salary') }}</label>
                <input type="number" step="0.001" name="total_salary" value="{{ old('total_salary') }}" class="form-control" dir="ltr" inputmode="decimal" autocomplete="off" required></div>
            <div class="col-12 col-md-4"><button class="btn btn-eet">{{ __('app.common.save') }}</button></div>
        </form>
        <div class="form-text">{{ __('app.profile.sensitive_reenter') }}</div>
    </div>
</div>
```

(`old()` values are the typed numbers of the failed attempt, same as today's profile form behaviour of blanking: keep `old()` here since the form posts back to the same page; it never echoes stored values.)

`_profile_fields.blade.php`: under the two salary inputs add `<div class="form-text">{{ __('app.profile.salary_later') }}</div>` when `! $instructor->hasApprovedApplicationInOpenTerm()` (guard `$instructor->exists`). Remove `required` from both salary inputs.

Lang `profile` (ar): `'salary_title' => 'بيانات الراتب', 'salary_later' => 'يمكن تعبئتها بعد اعتماد اللجنة.', 'salary_saved' => 'تم حفظ بيانات الراتب.', 'salary_not_yet' => 'تدخل بيانات الراتب بعد اعتماد اللجنة.'`; en: Salary details / Can be filled in after the committee's approval. / Salary details saved. / Salary details are entered after the committee's approval.

- [ ] **Step 6: Suite, Pint, commit**

```bash
git add -A
git commit -m "feat(profile): salary optional before approval, dedicated salary form after it

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 6: Multi-file upload

**Files:**
- Modify: `app/Http/Requests/UploadDocumentRequest.php`, `app/Services/DocumentStore.php`, `app/Http/Controllers/Instructor/DocumentController.php`, `app/Models/Application.php` (`latestDocuments`), `app/Services/ApplicationWorkflow.php` (`reviewDocument`, `onFileDocuments`), `resources/views/instructor/_upload.blade.php`, `resources/views/instructor/application.blade.php`, `resources/views/admin/applications/show.blade.php`, `lang/*`
- Create: `resources/views/_document_links.blade.php`
- Test: `tests/Feature/MultiFileUploadTest.php`

**Interfaces:**
- Produces: `DocumentStore::store(Application, ChecklistItem, array $files): Document` (returns the head; accepts a list of `UploadedFile`); `Application::latestDocuments()` heads with `parts` loaded; partial `_document_links` (`$document`, `$route` = `'instructor'|'admin'`) rendering one link per part.

- [ ] **Step 1: Failing tests**

`tests/Feature/MultiFileUploadTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use App\Services\ApplicationWorkflow;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MultiFileUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $admin;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ChecklistItemSeeder::class);
        $this->user = User::factory()->instructor()->create();
        $this->admin = User::factory()->admin()->create();
        $this->application = Application::factory()->for(Term::factory()->open())->for(Instructor::factory()->for($this->user))->create();
    }

    private function files(int $n): array
    {
        return array_map(fn ($i) => UploadedFile::fake()->create("p$i.pdf", 10, 'application/pdf'), range(1, $n));
    }

    private function upload(array $data)
    {
        return $this->actingAs($this->user)->post(route('instructor.documents.store', [$this->application, 'degree']), $data);
    }

    public function test_three_files_become_three_parts_of_one_version(): void
    {
        $this->upload(['files' => $this->files(3)])->assertRedirect();
        $docs = Document::where('application_id', $this->application->id)->orderBy('part')->get();
        $this->assertSame([1, 1, 1], $docs->pluck('version')->all());
        $this->assertSame([1, 2, 3], $docs->pluck('part')->all());
        $this->assertSame(['p1.pdf', 'p2.pdf', 'p3.pdf'], $docs->pluck('original_name')->all());
        foreach ($docs as $d) {
            Storage::disk('local')->assertExists($d->path);
        }

        $head = $this->application->latestDocuments()->get('degree');
        $this->assertSame(1, $head->part);
        $this->assertCount(3, $head->parts);

        $this->upload(['files' => $this->files(1)])->assertRedirect();
        $this->assertSame(2, $this->application->latestDocuments()->get('degree')->version);
    }

    public function test_legacy_single_file_field_still_works(): void
    {
        $this->upload(['file' => UploadedFile::fake()->create('one.pdf', 10, 'application/pdf')])->assertRedirect();
        $this->assertDatabaseHas('documents', ['original_name' => 'one.pdf', 'version' => 1, 'part' => 1]);
    }

    public function test_limits_and_zip_refused(): void
    {
        $this->upload(['files' => $this->files(11)])->assertSessionHasErrors('files');
        $this->upload(['files' => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'), UploadedFile::fake()->create('b.zip', 10, 'application/zip')]])->assertSessionHasErrors();
        $this->upload([])->assertSessionHasErrors('files');
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_review_of_the_head_updates_every_part(): void
    {
        $this->upload(['files' => $this->files(2)]);
        $this->application->update(['status' => Application::STATUS_UNDER_REVIEW]);
        $head = $this->application->latestDocuments()->get('degree');
        $this->actingAs($this->admin)->post(route('admin.documents.review', $head), ['status' => 'rejected', 'reason' => 'ناقص'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['rejected', 'rejected'], Document::orderBy('part')->pluck('status')->all());
        $this->assertSame(['ناقص', 'ناقص'], Document::orderBy('part')->pluck('rejection_reason')->all());
    }

    public function test_pages_link_every_part_and_history_shows_the_count(): void
    {
        $this->upload(['files' => $this->files(2)]);
        $parts = Document::orderBy('part')->get();
        $this->actingAs($this->user)->get(route('instructor.applications.show', $this->application))->assertOk()
            ->assertSee(route('instructor.documents.download', $parts[0]))->assertSee(route('instructor.documents.download', $parts[1]));
        $this->actingAs($this->admin)->get(route('admin.applications.show', $this->application))->assertOk()
            ->assertSee(route('admin.documents.download', $parts[1]))->assertSee(__('app.documents.parts_count', ['n' => 2]));
    }
}
```

- [ ] **Step 2: Run to see failures**.

- [ ] **Step 3: Request**

`UploadDocumentRequest`:

```php
    protected function prepareForValidation(): void
    {
        // Legacy single-file posts.
        if (! $this->hasFile('files') && $this->hasFile('file')) {
            $this->merge(['files' => [$this->file('file')]]);
            $this->files->set('files', [$this->file('file')]);
        }
    }

    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,docx',
                'mimetypes:application/pdf,image/jpeg,image/png,application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        ];
    }

    public function withValidator(Validator $v): void
    {
        $v->after(function (Validator $v) {
            foreach ((array) $this->file('files', []) as $i => $f) {
                if ($f && (str_ends_with(strtolower($f->getClientOriginalName()), '.zip') || $f->getMimeType() === 'application/zip')) {
                    $v->errors()->add("files.$i", __('app.documents.zip_rejected'));
                }
            }
        });
    }

    public function messages(): array
    {
        $rules = __('app.documents.file_rules');

        return ['files.required' => $rules, 'files.max' => __('app.documents.too_many_files'), 'files.*.mimes' => $rules, 'files.*.mimetypes' => $rules, 'files.*.max' => $rules];
    }
```

If `$this->files->set()` does not make `hasFile('files')` true in tests, use `$this->convertedFiles = null` trick or simply handle the legacy field in the controller: `$files = $request->file('files') ?? [$request->file('file')]` with both rule sets (`file` optional, `files` required-without:file). Pick whichever passes `test_legacy_single_file_field_still_works`; keep the rules above for `files`.

- [ ] **Step 4: Store and controller**

`DocumentStore::store(Application $application, ChecklistItem $item, array $files): Document`:

```php
    /** @param  list<UploadedFile>  $files  one version, parts 1..n; returns the head (part 1) */
    public function store(Application $application, ChecklistItem $item, array $files): Document
    {
        return DB::transaction(function () use ($application, $item, $files) {
            $version = (int) $application->documents()->where('checklist_item_id', $item->id)->max('version') + 1;
            $head = null;
            foreach (array_values($files) as $i => $file) {
                $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
                $path = $file->storeAs("applications/{$application->id}", Str::random(40).'.'.$ext, self::DISK);
                $doc = $application->documents()->create([
                    'checklist_item_id' => $item->id, 'path' => $path,
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'mime' => $file->getMimeType() ?? $file->getClientMimeType(), 'size' => $file->getSize(),
                    'status' => Document::STATUS_PENDING, 'version' => $version, 'part' => $i + 1,
                ]);
                $head ??= $doc;
            }

            return $head;
        });
    }
```

Controller: `$this->store->store($application, $item, $request->file('files'));`. Update every existing caller of `store()` (grep `->store(` in `app/` and `tests/`): callers pass a single `UploadedFile` → wrap in `[...]`.

- [ ] **Step 5: Heads and parts**

`Application::latestDocuments()`:

```php
    /** Head (part 1) of the latest version per item, keyed by item code, with `parts` loaded. */
    public function latestDocuments(): Collection
    {
        $all = $this->documents()->with('checklistItem')->orderByDesc('version')->orderBy('part')->get();
        $heads = $all->where('part', 1)->unique('checklist_item_id');
        foreach ($heads as $head) {
            $head->setRelation('parts', $all->where('checklist_item_id', $head->checklist_item_id)->where('version', $head->version)->sortBy('part')->values());
        }

        return $heads->keyBy(fn (Document $d) => $d->checklistItem->code);
    }
```

`ApplicationWorkflow::reviewDocument()`: the superseded check compares against the head (`->is($document)`), so a review must target part 1: add before it `if ($document->part !== 1) { $document = $document->parts()->first(); }`. The update becomes `Document::where(['application_id' => ..., 'checklist_item_id' => ..., 'version' => $document->version])->update([...])` followed by `$document->refresh()`, and the audit row stays on the head.

`onFileDocuments()`: add `->where('part', 1)` to the source query and load parts for each source with the same `setRelation` pattern (query the instructor's documents once more by `whereIn` of `(application_id, checklist_item_id, version)` — simplest: for each source `$source->setRelation('parts', $source->parts()->get())`; N is small).

- [ ] **Step 6: Views**

`resources/views/_document_links.blade.php`:

```blade
@php($parts = $document->relationLoaded('parts') ? $document->parts : $document->parts()->get())
@foreach ($parts as $part)
    @if ($route === 'admin')
        @if ($part->mime === 'application/pdf' || $part->isImage())
            <a href="{{ route('admin.documents.view', $part) }}" data-doc-url="{{ route('admin.documents.view', $part) }}" data-bs-toggle="modal" data-bs-target="#docModal">{{ __('app.review.view') }}</a> —
        @endif
        <a href="{{ route('admin.documents.download', $part) }}">{{ $parts->count() > 1 ? __('app.documents.part_n', ['n' => $part->part]) : __('app.documents.download') }}</a>
    @else
        <a href="{{ route('instructor.documents.download', $part) }}">{{ $part->original_name }}</a>
    @endif
    @if (! $loop->last)<br>@endif
@endforeach
<span class="text-muted small">({{ __('app.documents.version') }} {{ $document->version }}@if ($parts->count() > 1), {{ __('app.documents.parts_count', ['n' => $parts->count()]) }}@endif)</span>
```

Instructor page file cell: replace the single link with `@include('_document_links', ['document' => $document, 'route' => 'instructor'])` inside `@if ($document)`. Admin page file cell: `@php($file = $document ?? $row['source'])` then `@include('_document_links', ['document' => $file, 'route' => 'admin'])`. History table: query `->where('part', 1)` in the controller and show `{{ $document->version }}` plus parts count via `$document->parts()->count()` when > 1 (one extra query per version is acceptable).

`_upload.blade.php`: `<input type="file" name="files[]" multiple ...>` and the hint `__('app.documents.file_rules_multi')`.

Lang `documents` (ar): `'file_rules_multi' => 'حتى 10 ملفات في المرة الواحدة: PDF أو JPG أو PNG أو DOCX، بحد أقصى 10 ميغابايت للملف.', 'too_many_files' => 'الحد الأقصى 10 ملفات في المرة الواحدة.', 'parts_count' => ':n ملفات', 'part_n' => 'الملف :n'`; en: Up to 10 files at once: PDF, JPG, PNG or DOCX, 10 MB each. / At most 10 files at once. / :n files / File :n.

- [ ] **Step 7: Suite, Pint, commit**

```bash
git add -A
git commit -m "feat(documents): several files per upload as parts of one version

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 7: Attestation gating, dashboard group, attestation page list

**Files:**
- Modify: `app/Services/Attestations/AttestationService.php`, `app/Http/Controllers/Admin/DashboardController.php`, `app/Http/Controllers/Admin/AttestationController.php`, `resources/views/admin/dashboard.blade.php`, `resources/views/admin/attestations/index.blade.php`, `lang/*`
- Test: `tests/Feature/StageTwoGatingTest.php`

**Interfaces:**
- Produces: `AttestationService::listed(Term)` excludes approved applications whose stage 2 is incomplete; `AttestationService::awaitingDocuments(Term): Collection` (approved, assigned, stage 2 incomplete, with `missing` list); dashboard variable `$awaitingDocuments` (approved applications of the current term with stage 2 incomplete, each with `missing`).

- [ ] **Step 1: Failing tests**

`tests/Feature/StageTwoGatingTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use App\Services\Attestations\AttestationService;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StageTwoGatingTest extends TestCase
{
    use RefreshDatabase;

    private Term $term;

    private Application $ready;

    private Application $waiting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChecklistItemSeeder::class);
        $this->term = Term::factory()->open()->create();
        $this->ready = $this->approved('جاهز', salary: true, docs: true);
        $this->waiting = $this->approved('منتظر', salary: false, docs: true);
    }

    private function approved(string $name, bool $salary, bool $docs): Application
    {
        $instructor = Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => $name,
            'basic_salary' => $salary ? '900' : null, 'total_salary' => $salary ? '1200' : null]);
        $app = Application::factory()->approved()->for($this->term)->for($instructor)->create();
        if ($docs) {
            foreach (['salary_cert', 'iban', 'employer_approval', 'undertaking'] as $code) {
                Document::factory()->for($app)->forItem($code)->accepted()->create();
            }
        }
        $section = Section::factory()->for($this->term)->create();
        Assignment::factory()->for($app)->for($section)->create();

        return $app;
    }

    public function test_listed_excludes_applications_with_stage_two_incomplete(): void
    {
        $service = app(AttestationService::class);
        $this->assertSame([$this->ready->id], $service->listed($this->term)->pluck('id')->all());
        $this->assertSame([$this->waiting->id], $service->awaitingDocuments($this->term)->pluck('id')->all());
        $this->assertSame([__('app.profile.salary_missing')], $service->awaitingDocuments($this->term)->first()->missing);
    }

    public function test_generate_missing_skips_waiting_applications(): void
    {
        $admin = User::factory()->admin()->create();
        $m = $this->term->months()[0];
        $n = app(AttestationService::class)->generateMissing($this->term, $m['year'], $m['month'], $admin);
        $this->assertSame(1, $n);
        $this->assertDatabaseMissing('attestations', ['application_id' => $this->waiting->id]);
    }

    public function test_attestation_page_and_dashboard_list_waiting_applications_with_missing_items(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('admin.attestations.index', ['term' => $this->term->id]))->assertOk()
            ->assertSee(__('app.attestations.awaiting_documents'))->assertSee('منتظر')->assertSee(__('app.profile.salary_missing'));
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()
            ->assertSee(__('app.review.group_awaiting_documents'))->assertSee('منتظر')->assertDontSee('جاهز — '.__('app.profile.salary_missing'));
    }
}
```

(Check `Section::factory()` and `Assignment::factory()` exist — `tests/Feature/Admin/AttestationsTest.php` shows how it builds an assigned application; copy that helper if the factories differ.)

- [ ] **Step 2: Run to see failures**.

- [ ] **Step 3: Service**

`AttestationService` (inject `ApplicationWorkflow $workflow` in the constructor alongside the generator):

```php
    /** Approved applications with at least one assignment and stage 2 complete (spec 5b §6), by instructor name. */
    public function listed(Term $term): Collection
    {
        return $this->approvedAssigned($term)->filter(fn ($a) => $this->workflow->stageTwoComplete($a))->values();
    }

    /** Approved and assigned but not yet listed; each application gets a `missing` attribute (labels). */
    public function awaitingDocuments(Term $term): Collection
    {
        return $this->approvedAssigned($term)->reject(fn ($a) => $this->workflow->stageTwoComplete($a))
            ->each(fn ($a) => $a->missing = $this->workflow->stageTwoMissing($a))->values();
    }

    private function approvedAssigned(Term $term): Collection
    {
        return $term->applications()->where('status', Application::STATUS_APPROVED)->has('assignments')->with('instructor')->get()
            ->sortBy(fn ($a) => $a->instructor->full_name)->values();
    }
```

Check that nothing else constructs `AttestationService` manually (grep `new AttestationService`); tests use the container.

- [ ] **Step 4: Controllers and views**

`AttestationController::index`: pass `'awaiting' => $this->service->awaitingDocuments($term)`. In `index.blade.php`, after the rows table (inside the `@if ($term)` branch), add:

```blade
@if ($awaiting->isNotEmpty())
    <h2 class="h6 mt-4">{{ __('app.attestations.awaiting_documents') }}</h2>
    <ul class="mb-4">
        @foreach ($awaiting as $a)
            <li><a href="{{ route('admin.applications.show', $a) }}">{{ $a->instructor->full_name }}</a> — {{ implode('، ', $a->missing) }}</li>
        @endforeach
    </ul>
@endif
```

`DashboardController::index`: after `$committee`, add

```php
        $awaitingDocuments = collect();
        if ($term) {
            $awaitingDocuments = (clone $base)->where('status', Application::STATUS_APPROVED)->get()
                ->reject(fn ($a) => $this->workflow->stageTwoComplete($a))
                ->each(fn ($a) => $a->missing = $this->workflow->stageTwoMissing($a))->values();
        }
```

(inject `ApplicationWorkflow $workflow`), pass it to the view. `dashboard.blade.php` after the committee group:

```blade
<h2 class="h6 mt-4">{{ __('app.review.group_awaiting_documents') }}</h2>
@if ($awaitingDocuments->isEmpty())
    <div class="alert alert-info">{{ __('app.review.no_awaiting_documents') }}</div>
@else
    <ul>
        @foreach ($awaitingDocuments as $a)
            <li><a href="{{ route('admin.applications.show', $a) }}">{{ $a->instructor->full_name }}</a> — {{ implode('، ', $a->missing) }}</li>
        @endforeach
    </ul>
@endif
```

Lang (ar): `review.group_awaiting_documents => 'معتمد، بانتظار المستندات'`, `review.no_awaiting_documents => 'لا توجد طلبات معتمدة بانتظار المستندات.'`, `attestations.awaiting_documents => 'بانتظار استكمال المستندات (لا تصدر لهم المزاولة بعد)'`; en twins: Approved, awaiting documents / No approved applications are awaiting documents. / Awaiting documents (no attestation yet).

- [ ] **Step 5: Existing attestation tests** — `AttestationsTest` builds approved applications without stage-2 documents or salary; add a test helper in that file that marks stage 2 complete (accept the four stage-2 items, set salary) and call it where `listed()` must include the application. Keep every existing assertion.

- [ ] **Step 6: Suite, Pint, commit**

```bash
git add -A
git commit -m "feat(attestations): gate on stage 2; dashboard and attestation page list waiting instructors

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 8: Pages and approval mail

**Files:**
- Modify: `resources/views/instructor/application.blade.php`, `resources/views/admin/applications/show.blade.php`, `app/Http/Controllers/Instructor/ApplicationController.php`, `app/Http/Controllers/Admin/ApplicationController.php`, `resources/views/emails/application-approved.blade.php`, `app/Mail/ApplicationApproved.php`, `lang/*`
- Create: `resources/views/instructor/_checklist_table.blade.php`, `resources/views/admin/applications/_checklist_table.blade.php`
- Test: `tests/Feature/StagePagesTest.php`

**Interfaces:**
- Consumes: rows with `stage`, `exemption`; `stageTwoComplete/Missing`; routes from Tasks 3, 5; partials from Task 6.

- [ ] **Step 1: Failing tests**

`tests/Feature/StagePagesTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Mail\ApplicationApproved;
use App\Models\Application;
use App\Models\ChecklistExemption;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use App\Services\ApplicationWorkflow;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StagePagesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $admin;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(ChecklistItemSeeder::class);
        $this->user = User::factory()->instructor()->create();
        $this->admin = User::factory()->admin()->create();
        $this->application = Application::factory()->for(Term::factory()->open())->for(Instructor::factory()->for($this->user)->state(['basic_salary' => null, 'total_salary' => null]))->create();
    }

    public function test_instructor_page_has_two_sections_and_exemption_button_before_approval(): void
    {
        $r = $this->actingAs($this->user)->get(route('instructor.applications.show', $this->application))->assertOk();
        $r->assertSeeInOrder([__('app.applications.stage1_title'), 'كشف درجات البكالوريوس', __('app.applications.stage2_title'), 'شهادة راتب حديثة']);
        $r->assertSee(__('app.exemptions.request'));
        $r->assertSee(__('app.documents.stage2_hint'));
        $r->assertSee(__('app.documents.employer_letter_hint'));
        $r->assertDontSee(route('instructor.documents.store', [$this->application, 'iban']));
        $r->assertDontSee(route('instructor.salary.update'));
    }

    public function test_instructor_page_after_approval_shows_stage_two_uploads_salary_form_and_missing_list(): void
    {
        $this->application->update(['status' => Application::STATUS_APPROVED]);
        $r = $this->actingAs($this->user)->get(route('instructor.applications.show', $this->application))->assertOk();
        $r->assertSee(route('instructor.documents.store', [$this->application, 'iban']));
        $r->assertDontSee(route('instructor.documents.store', [$this->application, 'degree']));
        $r->assertSee(route('instructor.salary.update'));
        $r->assertDontSee(__('app.exemptions.request'));
        $r->assertSee(__('app.applications.stage2_pending'));
    }

    public function test_instructor_page_states_stage_two_complete(): void
    {
        $this->application->update(['status' => Application::STATUS_APPROVED]);
        $this->application->instructor->update(['basic_salary' => '900', 'total_salary' => '1200']);
        foreach (['salary_cert', 'iban', 'employer_approval', 'undertaking'] as $code) {
            Document::factory()->for($this->application)->forItem($code)->accepted()->create();
        }
        $this->actingAs($this->user)->get(route('instructor.applications.show', $this->application))->assertOk()
            ->assertSee(__('app.applications.stage2_complete'))->assertDontSee(route('instructor.salary.update'));
    }

    public function test_admin_page_shows_exemption_forms_badges_and_decision_messages(): void
    {
        $this->application->update(['status' => Application::STATUS_UNDER_REVIEW]);
        foreach (['civil_id', 'degree', 'transcript_master'] as $code) {
            Document::factory()->for($this->application)->forItem($code)->accepted()->create();
        }
        $e = ChecklistExemption::factory()->for($this->application)->forItem('transcript_bachelor')->create(['reason' => 'سبب الطالب']);
        $r = $this->actingAs($this->admin)->get(route('admin.applications.show', $this->application))->assertOk();
        $r->assertSee('سبب الطالب')->assertSee(route('admin.exemptions.decide', $e))->assertSee(__('app.exemptions.accept'));
        $r->assertSee(__('app.review.complete_blocked_exemptions'));
        $r->assertSee(__('app.applications.stage2_badge'));
    }

    public function test_admin_page_after_approval_shows_stage_two_state(): void
    {
        $this->application->update(['status' => Application::STATUS_APPROVED]);
        $this->actingAs($this->admin)->get(route('admin.applications.show', $this->application))->assertOk()
            ->assertSee(__('app.review.stage2_missing'))->assertSee(__('app.profile.salary_missing'));
    }

    public function test_approval_mail_lists_stage_two_items(): void
    {
        $this->application->update(['status' => Application::STATUS_COMPLETE]);
        app(ApplicationWorkflow::class)->committeeDecision($this->application, $this->admin, 'approved', '2026-10-01', 'ق/1', null);
        Mail::assertSent(ApplicationApproved::class, fn ($m) => str_contains($r = $m->render(), 'شهادة راتب حديثة') && str_contains($r, __('app.mail.approved_stage2_intro', [], 'ar')) && str_contains($r, __('app.documents.employer_letter_hint', [], 'ar')));
    }
}
```

- [ ] **Step 2: Run to see failures**.

- [ ] **Step 3: Controllers**

`Instructor\ApplicationController::show` adds to the view data:

```php
            'stage1' => array_filter($checklist = $this->workflow->checklist($application), fn ($r) => $r['stage'] === 1),
            'stage2' => array_filter($checklist, fn ($r) => $r['stage'] === 2 || $r['optional']),
            'stageTwoComplete' => $this->workflow->stageTwoComplete($application),
            'stageTwoMissing' => $this->workflow->stageTwoMissing($application),
            'showSalaryForm' => $application->acceptsStageTwoUploads() && ($application->instructor->basic_salary === null || $application->instructor->total_salary === null),
```

(keep `'checklist' => $checklist` for the existing tests). `Admin\ApplicationController::show` adds `stage1`, `stage2`, `stageTwoComplete`, `stageTwoMissing`, `undecidedExemptions => $this->workflow->hasUndecidedExemptions($application)`, and changes the history query to `->where('part', 1)`.

- [ ] **Step 4: Instructor page**

Extract the current checklist `<table>` into `resources/views/instructor/_checklist_table.blade.php` taking `$rows`, `$application`, `$uploads` (bool: render upload controls). Row additions inside the status cell:

```blade
@if ($row['state'] === 'exemption_requested')
    <div class="small text-muted">{{ __('app.exemptions.pending') }}</div>
@elseif ($row['state'] === 'exempted')
    <div class="small text-success">{{ __('app.exemptions.accepted') }}</div>
@elseif ($row['exemption']?->status === 'rejected' && ! $row['document'])
    <div class="small text-danger">{{ __('app.exemptions.rejected_line', ['note' => $row['exemption']->decision_note]) }}</div>
@endif
@if ($row['item']->code === 'employer_approval')
    <div class="small text-muted">{{ __('app.documents.employer_letter_hint') }}</div>
@endif
```

Actions cell, when `$uploads` and the item is exemptable and the row is `missing` (including after a rejected exemption) and the application is editable:

```blade
<details class="mt-1">
    <summary class="small">{{ __('app.exemptions.request') }}</summary>
    <form method="post" action="{{ route('instructor.exemptions.store', [$application, $row['item']->code]) }}" class="d-flex gap-1 mt-1">
        @csrf
        <input type="text" name="reason" class="form-control form-control-sm" placeholder="{{ __('app.exemptions.reason') }}" maxlength="500" required>
        <button class="btn btn-sm btn-outline-secondary text-nowrap">{{ __('app.exemptions.request') }}</button>
    </form>
</details>
```

`application.blade.php` body becomes:

```blade
    <h2 class="h6">{{ __('app.applications.stage1_title') }}</h2>
    @include('instructor._checklist_table', ['rows' => $stage1, 'application' => $application, 'uploads' => $application->isEditable()])

    <h2 class="h6">{{ __('app.applications.stage2_title') }}</h2>
    <p class="text-muted small">{{ __('app.documents.stage2_hint') }}</p>
    @if ($application->status === \App\Models\Application::STATUS_APPROVED)
        @if ($stageTwoComplete)
            <div class="alert alert-success py-2">{{ __('app.applications.stage2_complete') }}</div>
        @else
            <div class="alert alert-warning py-2">{{ __('app.applications.stage2_pending') }}: {{ implode('، ', $stageTwoMissing) }}</div>
        @endif
        @if ($showSalaryForm)
            @include('instructor._salary_form', ['instructor' => $application->instructor])
        @endif
    @endif
    @include('instructor._checklist_table', ['rows' => $stage2, 'application' => $application, 'uploads' => $application->acceptsStageTwoUploads()])
```

(the `_upload` partial already refuses stage-1 uploads after approval; `$uploads` only decides whether to render the control at all; the `$errors->has('exemption')` message renders through the layout's error block).

- [ ] **Step 5: Admin page**

Extract the checklist `<table>` into `admin/applications/_checklist_table.blade.php` (`$rows`, `$application`, `$termOpen`). Per row: a `{{ __('app.applications.stage2_badge') }}` badge when `$row['stage'] === 2`; the exemption block in the status cell:

```blade
@if ($row['exemption'] && ! $row['document'])
    <div class="small">{{ __('app.exemptions.applicant_reason') }}: {{ $row['exemption']->reason }}</div>
    @if ($row['exemption']->isPending() && $termOpen && in_array($application->status, \App\Models\Application::REVIEWABLE_STATUSES, true))
        <form method="post" action="{{ route('admin.exemptions.decide', $row['exemption']) }}" class="d-flex gap-1 mt-1">
            @csrf
            <input type="hidden" name="status" value="accepted">
            <button class="btn btn-sm btn-outline-success text-nowrap">{{ __('app.exemptions.accept') }}</button>
        </form>
        <form method="post" action="{{ route('admin.exemptions.decide', $row['exemption']) }}" class="d-flex gap-1 mt-1">
            @csrf
            <input type="hidden" name="status" value="rejected">
            <input type="text" name="decision_note" class="form-control form-control-sm" placeholder="{{ __('app.exemptions.decision_note') }}" maxlength="500" required>
            <button class="btn btn-sm btn-outline-danger text-nowrap">{{ __('app.exemptions.reject') }}</button>
        </form>
    @elseif ($row['exemption']->status === 'rejected')
        <div class="small text-danger">{{ __('app.exemptions.rejected_line', ['note' => $row['exemption']->decision_note]) }}</div>
    @endif
@endif
```

Page: two headings `app.applications.stage1_title` / `stage2_title`, each including the partial. Decision block: the `@else` branch message becomes `{{ $undecidedExemptions && ! $canComplete ? __('app.review.complete_blocked_exemptions') : __('app.review.complete_blocked') }}` (compute "only exemptions block" in the controller as `onlyExemptionsBlock` is private: expose `ApplicationWorkflow::completeBlockMessage(Application): string` returning the right key and use it here and in `markComplete()`). In the final-status branch, when approved add:

```blade
@if ($stageTwoComplete)
    <div class="alert alert-success py-2 mt-2 mb-0">{{ __('app.review.stage2_complete') }}</div>
@else
    <div class="alert alert-warning py-2 mt-2 mb-0">{{ __('app.review.stage2_missing') }}: {{ implode('، ', $stageTwoMissing) }}</div>
@endif
```

- [ ] **Step 6: Approval mail**

`ApplicationApproved::content()` adds `'items' => app(ApplicationWorkflow::class)->plan($this->application)->stage2->pluck('label_ar')->all()`; the view:

```blade
    <p>{{ __('app.mail.approved_body', ['term' => $term], 'ar') }}</p>
    <p>{{ __('app.mail.approved_stage2_intro', [], 'ar') }}</p>
    <ul>
        @foreach ($items as $label)
            <li>{{ $label }}</li>
        @endforeach
        <li>{{ __('app.profile.salary_missing', [], 'ar') }}</li>
    </ul>
    <p>{{ __('app.documents.employer_letter_hint', [], 'ar') }}</p>
```

- [ ] **Step 7: Lang** (ar; en twins in the same shape):

- `applications`: `'stage1_title' => 'المرحلة الأولى: مستندات اللجنة', 'stage2_title' => 'المرحلة الثانية: بعد الاعتماد', 'stage2_badge' => 'المرحلة الثانية', 'stage2_complete' => 'اكتملت مستندات المرحلة الثانية.', 'stage2_pending' => 'المطلوب لاستكمال الملف'`
- `documents`: `'stage2_hint' => 'تطلب هذه المستندات بعد اعتماد اللجنة، ولا تؤثر على تقديم الطلب.', 'employer_letter_hint' => 'تطلب الكلية موافقة جهة العمل بكتاب رسمي بعد الاعتماد، ثم ترفع نسخة الموافقة هنا.'`
- `review`: `'stage2_complete' => 'اكتملت مستندات المرحلة الثانية وبيانات الراتب.', 'stage2_missing' => 'بانتظار استكمال'`
- `mail`: `'approved_stage2_intro' => 'لاستكمال ملفك يرجى رفع المستندات التالية وتعبئة بيانات الراتب من صفحة الطلب:'`

- [ ] **Step 8: Suite, Pint, commit**

```bash
git add -A
git commit -m "feat(ui): two-stage checklist pages, exemption forms, stage-2 state, approval mail

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 9: Parked items, deploy notes and docs

**Files:**
- Modify: `app/Services/ApplicationWorkflow.php` (`submit`), `app/Http/Requests/ProfileRequest.php` (`degree_country`), `tests/Unit/Attestations/AttestationDocumentTest.php`, `tests/Feature/Admin/ReviewTest.php`, `deploy/DEPLOY.md`, `CLAUDE.md`, `PROGRESS.md`
- Test: `tests/Feature/Instructor/SubmitTest.php` (one new test), `tests/Feature/Instructor/ProfileTest.php` (one new test)

- [ ] **Step 1: Submit under the term lock**

```php
    public function submit(Application $application): void
    {
        DB::transaction(function () use ($application) {
            $term = Term::whereKey($application->term_id)->lockForUpdate()->firstOrFail();
            if (! $term->isOpen() || ! $application->isEditable()) {
                throw new \DomainException(__('app.applications.term_closed'));
            }
            if (! $this->allRequiredUploaded($application)) {
                throw new \DomainException(__('app.applications.submit_blocked'));
            }
            $application->update(['status' => Application::STATUS_SUBMITTED, 'submitted_at' => now()]);
        });
        $this->notifyAdmin($application);
    }
```

Test in `SubmitTest`: `test_submit_on_a_term_closed_meanwhile_is_refused` — create the application, close the term through `Term::update(['status' => 'closed'])` after the page load, post submit, assert the status is still `draft` and the error is `app.applications.term_closed`.

- [ ] **Step 2: `degree_country` in the list** — `'degree_country' => ['required', 'string', Rule::in(array_keys(__('app.countries')))]`. `ProfileTest`: `test_degree_country_must_be_in_the_list` posts `'degree_country' => 'ZQ'` and asserts an error on `degree_country`; `'KW'` passes.

- [ ] **Step 3: Test hardenings** — in `AttestationDocumentTest` where `preg_match_all('~<w:tcW w:w="(\d+)"~', ...)` runs, add `$this->assertCount(<expected cell count>, $tcw[1])` using the count the template's week row actually has (read it from the test's existing expectations). In `ReviewTest::test_show_header_and_profile_card_show_role_and_applicant_email`, add `->assertSee($this->admin->name)`.

- [ ] **Step 4: DEPLOY.md** — add under the Environment section:

```
Multi-file uploads (5b): PHP-FPM must allow 10 files × 10 MB per request.
Check `/etc/php/8.4/fpm/php.ini`: `upload_max_filesize = 10M`,
`post_max_size = 110M`, `max_file_uploads = 20`; then
`systemctl reload php8.4-fpm`. Deploy = `./deploy/deploy.sh` (three
migrations, seeder re-run).
```

- [ ] **Step 5: CLAUDE.md status and PROGRESS.md** — status paragraph: 5b implemented on branch `milestone-5b-two-stage`, what it adds (one sentence per section of the spec), deploy note; "Next step": deploy, check PHP-FPM limits, then the first real applicants. PROGRESS.md: log line 2026-10-03 with the same.

- [ ] **Step 6: Suite, Pint, commit**

```bash
git add -A
git commit -m "chore: submit under term lock, country-list validation, test hardenings, 5b docs

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Self-review notes

- Spec coverage: §3 (Task 1), §4–5 (Task 2, 4), §6 (Task 3), §7 (Task 5), §8 (Task 6), §9 (Task 7, 8), §10 security (policies in 3/4/5, audit names only — tests assert), §11 (Task 9), §12 tests spread per task, §13 delivery order followed.
- Type consistency: `stageTwoMissing()` returns `array<int,string>` everywhere; `listed()` returns a Collection of Applications as before; `DocumentStore::store()` signature change is propagated to every caller in Task 6 Step 4.
- Known cross-task edge: Task 2's `markComplete()` message logic is replaced in Task 8 by `completeBlockMessage()`; Task 8 keeps the Task 2 test green by returning the same keys.

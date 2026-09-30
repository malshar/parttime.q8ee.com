# Milestone 4 — Term Close, On-File Documents, Parked Fixes — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close a term safely (refuse while applications are unfinished, withdraw unsent drafts), let a returning instructor's earlier accepted documents satisfy the new checklist as "على الملف" with an admin override, and land the fixes parked from the milestone 3 review.

**Architecture:** Same Laravel 12 monolith. Term close and the on-file derivation live in `ApplicationWorkflow` (the checklist row gains an `on_file` state plus `source`/`renewal` fields; a new `checklist_renewals` table holds admin overrides). Screens and the printed Check List render the new state. The parked fixes touch the attestation service, document builder, converter, generator and template script.

**Tech Stack:** PHP ≥ 8.2, Laravel 12, MySQL/SQLite, PHPUnit, Bootstrap 5 RTL (CDN), PhpWord (existing), Python 3 for the template script (existing).

**Spec:** `docs/superpowers/specs/2026-09-30-milestone-4-term-close-on-file-design.md` (binding).

## Global Constraints

- Laravel 12; SQLite `:memory:` in tests; PHPUnit; TDD per task; `vendor/bin/pint` only on touched files (never the whole `lang/` directory).
- Arabic-first, RTL: every new UI string in `lang/ar/app.php` with an English twin in `lang/en/app.php`; formal undiacritized Arabic; no hard-coded UI strings in views/controllers.
- Admin-only actions go through the existing policies (`ApplicationPolicy::review`) and the `role:admin` group; instructors get 403.
- Audit rows: `close_term` (details `drafts_withdrawn=<n>`), `request_fresh_copy` (details = item code); never sensitive values.
- Checklist row shape everywhere: `['item', 'document', 'state', 'source', 'renewal']`; `state ∈ missing|pending|accepted|rejected|on_file`. `on_file` satisfies `allRequiredUploaded()`, `allRequiredAccepted()`, `markComplete()`, the committee step and the printed Check List (☑).
- Derivation order (spec §4.2): document in this application → renewal row → renews-each-term → civil ID expired (`civil_id_expires_on <= today`) → latest accepted document of the item in another application of the same instructor whose term starts earlier → missing.
- Term close: refused while any application is `submitted|under_review|incomplete|complete`; drafts withdrawn in the same transaction; closed stays read-only; `Term::STATUS_ARCHIVED` removed.
- Commit after every task with the `Co-Authored-By:` trailer the environment specifies. Never read `../part-time/`.

## Review Focus

1. **A returning instructor whose earlier application is still `draft` or was withdrawn without any review.** Expected: no on-file items (no accepted document exists). Pinned in Task 2.
2. **Two earlier terms, the older with an accepted copy and the newer with a rejected copy of the same item.** Expected: the item is on file from the older term (the rejected newer copy is not a source; only accepted documents qualify). Pinned in Task 2.
3. **Closing a term that has an approved application with a generated but unexported attestation.** Expected: closing succeeds; downloads still work afterwards. Pinned in Task 1.
4. **The admin requests a fresh copy, the instructor uploads it, the admin accepts it.** Expected: the row becomes `accepted`, the renewal row stays but no longer affects the state, the application returns to `submitted` through the existing incomplete flow. Pinned in Task 3.
5. **A concurrent save on the attestation page after another admin regenerated it.** Expected: the stale form is refused with a message, nothing is written. Pinned in Task 5.

## File Structure

```
app/
  Exceptions/TermCloseBlockedException.php                     (new: carries the blocking applications)
  Models/ChecklistRenewal.php                                   (new)
  Models/Application.php                                        (UNFINISHED_STATUSES, renewals())
  Models/Term.php                                               (STATUS_ARCHIVED and attestations() removed)
  Services/ApplicationWorkflow.php                              (closeTerm, checklist on_file, onFileDocuments, requestFreshCopy, notices)
  Services/ChecklistDocument.php                                (☑ for on_file)
  Services/Attestations/{AttestationService,AttestationDocument,PdfConverter,AttestationGenerator}.php  (parked fixes)
  Http/Controllers/Admin/TermController.php                     (close via workflow)
  Http/Controllers/Admin/ApplicationController.php              (requestFreshCopy)
  Mail/DocumentsRejected.php                                    (rows may be renewal rows)
database/migrations/2026_09_30_200000_create_checklist_renewals_table.php
database/factories/ChecklistRenewalFactory.php
resources/views/admin/terms/index.blade.php                     (close blockers)
resources/views/instructor/application.blade.php, instructor/_upload.blade.php
resources/views/admin/applications/show.blade.php
resources/views/emails/documents-rejected.blade.php
resources/views/admin/attestations/show.blade.php               (regenerate hidden without assignments)
scripts/build-kh3-template.py, resources/forms/kh3-template.docx (note cell underline)
routes/web.php
lang/ar/app.php, lang/en/app.php
tests/Feature/Admin/TermCloseTest.php, OnFileTest.php, FreshCopyTest.php
tests/Feature/Admin/{ReviewTest,ChecklistDocumentTest,AttestationsTest,AttestationExportTest}.php (additions)
tests/Unit/Attestations/{AttestationGeneratorTest,AttestationDocumentTest,PdfConverterTest}.php (additions)
tests/Feature/Instructor/ApplicationTest.php (additions)
CLAUDE.md, PROGRESS.md
```

---

### Task 1: Term close — refuse while unfinished, withdraw drafts, drop `archived`

**Files:**
- Create: `app/Exceptions/TermCloseBlockedException.php`, `tests/Feature/Admin/TermCloseTest.php`
- Modify: `app/Models/Application.php` (add `UNFINISHED_STATUSES`), `app/Models/Term.php` (remove `STATUS_ARCHIVED`), `app/Services/ApplicationWorkflow.php` (add `closeTerm()`), `app/Http/Controllers/Admin/TermController.php` (`close()`), `resources/views/admin/terms/index.blade.php`, `lang/ar/app.php`, `lang/en/app.php`, `tests/Feature/Admin/TermTest.php` (replace the `STATUS_ARCHIVED` use)

**Interfaces:**
- Consumes: `Term::isOpen()`, `Application::STATUS_*`, `AuditLog::record()`.
- Produces: `Application::UNFINISHED_STATUSES = [submitted, under_review, incomplete, complete]`; `ApplicationWorkflow::closeTerm(Term $term, User $admin): int` (drafts withdrawn; throws `TermCloseBlockedException` with `->applications` (Collection with `instructor` loaded)); `app.terms.close_blocked`, `app.terms.closed_with_drafts` (`:n`).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Admin/TermCloseTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Attestation;
use App\Models\Instructor;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TermCloseTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Term $term;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->term = Term::factory()->open()->create();
    }

    private function application(string $status, string $name): Application
    {
        $instructor = Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => $name]);

        return Application::factory()->for($this->term)->for($instructor)->create(['status' => $status]);
    }

    public function test_close_refused_while_an_application_is_unfinished_and_lists_it(): void
    {
        $this->application(Application::STATUS_UNDER_REVIEW, 'سعود فهد');
        $this->application(Application::STATUS_APPROVED, 'ناصر علي');

        $r = $this->actingAs($this->admin)->from(route('admin.terms.index'))->post(route('admin.terms.close', $this->term));

        $r->assertRedirect(route('admin.terms.index'))->assertSessionHasErrors(['close' => __('app.terms.close_blocked')]);
        $this->assertSame(Term::STATUS_OPEN, $this->term->fresh()->status);
        $page = $this->actingAs($this->admin)->get(route('admin.terms.index'));
        $page->assertSee('سعود فهد')->assertSee(__('app.applications.statuses.under_review'));
        $page->assertDontSee('ناصر علي');
        $this->assertDatabaseMissing('audit_log', ['action' => 'close_term']);
    }

    public function test_close_withdraws_drafts_keeps_final_ones_and_audits_the_count(): void
    {
        $draft = $this->application(Application::STATUS_DRAFT, 'أ');
        $draft2 = $this->application(Application::STATUS_DRAFT, 'ب');
        $approved = $this->application(Application::STATUS_APPROVED, 'ج');
        $rejected = $this->application(Application::STATUS_REJECTED, 'د');
        $withdrawn = $this->application(Application::STATUS_WITHDRAWN, 'ه');

        $this->actingAs($this->admin)->post(route('admin.terms.close', $this->term))
            ->assertRedirect()->assertSessionHas('status', __('app.terms.closed_with_drafts', ['n' => 2]));

        $this->assertSame(Term::STATUS_CLOSED, $this->term->fresh()->status);
        $this->assertSame(Application::STATUS_WITHDRAWN, $draft->fresh()->status);
        $this->assertNotNull($draft2->fresh()->decided_at);
        $this->assertSame(Application::STATUS_APPROVED, $approved->fresh()->status);
        $this->assertSame(Application::STATUS_REJECTED, $rejected->fresh()->status);
        $this->assertSame(Application::STATUS_WITHDRAWN, $withdrawn->fresh()->status);
        $this->assertDatabaseHas('audit_log', ['action' => 'close_term', 'subject_id' => $this->term->id, 'details' => 'drafts_withdrawn=2', 'user_id' => $this->admin->id]);
    }

    public function test_close_succeeds_with_unexported_attestation_and_downloads_still_work(): void
    {
        $approved = $this->application(Application::STATUS_APPROVED, 'خالد');
        Assignment::factory()->for($approved)->for(Section::factory()->for($this->term)->withMeetings()->create())->create();
        $a = Attestation::factory()->for($approved)->create(['year' => $this->term->teaching_starts_on->year, 'month' => $this->term->teaching_starts_on->month]);

        $this->actingAs($this->admin)->post(route('admin.terms.close', $this->term))->assertSessionHasNoErrors();

        $this->assertSame(Term::STATUS_CLOSED, $this->term->fresh()->status);
        $this->actingAs($this->admin)->get(route('admin.attestations.download', [$a, 'format' => 'docx']))->assertOk();
    }

    public function test_close_on_closed_term_is_forbidden_and_instructor_cannot_close(): void
    {
        $this->term->update(['status' => Term::STATUS_CLOSED]);
        $this->actingAs($this->admin)->post(route('admin.terms.close', $this->term))->assertForbidden();

        $open = Term::factory()->open()->create(['academic_year' => '2027-2028']);
        $this->actingAs(User::factory()->instructor()->create())->post(route('admin.terms.close', $open))->assertForbidden();
        $this->assertSame(Term::STATUS_OPEN, $open->fresh()->status);
    }
}
```

In `tests/Feature/Admin/TermTest.php`, `test_close_only_allowed_on_open_term`: replace `Term::STATUS_ARCHIVED` with `Term::STATUS_CLOSED` and the `'archived'` assertion with `'closed'`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Admin/TermCloseTest.php tests/Feature/Admin/TermTest.php`
Expected: FAIL — close succeeds despite the unfinished application; `closed_with_drafts` missing.

- [ ] **Step 3: Implement**

`app/Exceptions/TermCloseBlockedException.php`:

```php
<?php

namespace App\Exceptions;

use DomainException;
use Illuminate\Support\Collection;

/** Thrown by ApplicationWorkflow::closeTerm() while applications are still unfinished (spec §3.1). */
class TermCloseBlockedException extends DomainException
{
    /** @param  Collection<int, \App\Models\Application>  $applications  with `instructor` loaded */
    public function __construct(public readonly Collection $applications)
    {
        parent::__construct(__('app.terms.close_blocked'));
    }
}
```

`app/Models/Application.php` — add next to the other status lists:

```php
    /** Not final and not a draft: the term cannot close while any application is here. */
    public const UNFINISHED_STATUSES = [self::STATUS_SUBMITTED, self::STATUS_UNDER_REVIEW, self::STATUS_INCOMPLETE, self::STATUS_COMPLETE];
```

`app/Models/Term.php` — delete the `STATUS_ARCHIVED` constant (and its blank line). `lang/ar/app.php` and `lang/en/app.php`: remove the `'archived'` entry from `terms.statuses`.

`app/Services/ApplicationWorkflow.php` — add (imports `App\Exceptions\TermCloseBlockedException`, `Illuminate\Support\Facades\DB`):

```php
    /**
     * Spec §3.1: refuse while any application is unfinished; otherwise withdraw never-submitted
     * drafts and close, in one transaction. Returns the number of drafts withdrawn.
     */
    public function closeTerm(Term $term, User $admin): int
    {
        if (! $term->isOpen()) {
            throw new \DomainException(__('app.applications.term_closed'));
        }
        $blocking = $term->applications()->whereIn('status', Application::UNFINISHED_STATUSES)->with('instructor')->get();
        if ($blocking->isNotEmpty()) {
            throw new TermCloseBlockedException($blocking);
        }

        return DB::transaction(function () use ($term, $admin) {
            $n = $term->applications()->where('status', Application::STATUS_DRAFT)
                ->update(['status' => Application::STATUS_WITHDRAWN, 'decided_at' => now()]);
            $term->update(['status' => Term::STATUS_CLOSED]);
            AuditLog::record($admin->id, 'close_term', $term, null, 'drafts_withdrawn='.$n);

            return $n;
        });
    }
```

`app/Http/Controllers/Admin/TermController.php` — inject the workflow and replace `close()` (imports `App\Exceptions\TermCloseBlockedException`, `App\Services\ApplicationWorkflow`, `Illuminate\Http\Request`):

```php
    public function __construct(private ApplicationWorkflow $workflow) {}

    public function close(Request $request, Term $term): RedirectResponse
    {
        abort_unless($term->isOpen(), 403);
        try {
            $n = $this->workflow->closeTerm($term, $request->user());
        } catch (TermCloseBlockedException $e) {
            return redirect()->route('admin.terms.index')
                ->withErrors(['close' => $e->getMessage()])
                ->with('close_blockers', $e->applications->map(fn ($a) => [
                    'name' => $a->instructor->full_name,
                    'status' => __('app.applications.statuses.'.$a->status),
                    'url' => route('admin.applications.show', $a),
                ])->all());
        }

        return redirect()->route('admin.terms.index')->with('status', __('app.terms.closed_with_drafts', ['n' => $n]));
    }
```

(Remove the now-unused `AuditLog` import from the controller only if nothing else in it uses it — `store()` and `update()` still do, so keep it.)

`resources/views/admin/terms/index.blade.php` — after the heading `div`, before the table:

```blade
@if ($errors->has('close'))
    <div class="alert alert-danger">
        <div>{{ $errors->first('close') }}</div>
        <ul class="mb-0 mt-2">
            @foreach (session('close_blockers', []) as $b)
                <li><a href="{{ $b['url'] }}">{{ $b['name'] }}</a> — {{ $b['status'] }}</li>
            @endforeach
        </ul>
    </div>
@endif
@if (session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif
```

(The admin layout does not print `session('status')` itself, so keep the second block.)

Lang, `terms` block — ar: `'close_blocked' => 'لا يمكن إغلاق الفصل قبل البت في الطلبات غير المنتهية التالية:', 'closed_with_drafts' => 'تم إغلاق الفصل وسحب :n مسودة لم تقدم.',`; en: `'close_blocked' => 'The term cannot be closed until these unfinished applications are resolved:', 'closed_with_drafts' => 'Term closed; :n unsubmitted draft(s) withdrawn.',`. Keep the existing `'closed'` key (other places may use it).

- [ ] **Step 4: Run the tests**

Run: `php artisan test --compact tests/Feature/Admin/TermCloseTest.php tests/Feature/Admin/TermTest.php`
Expected: PASS. Then `grep -rn "STATUS_ARCHIVED\|'archived'" app lang tests` → nothing. Whole suite: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint app/Exceptions/TermCloseBlockedException.php app/Models/Application.php app/Models/Term.php app/Services/ApplicationWorkflow.php app/Http/Controllers/Admin/TermController.php tests/Feature/Admin/TermCloseTest.php tests/Feature/Admin/TermTest.php
git add app resources/views/admin/terms lang tests
git commit -m "feat(terms): closing refuses unfinished applications, withdraws drafts, drops archived"
```

---

### Task 2: `checklist_renewals`, on-file derivation, completeness rules

**Files:**
- Create: `database/migrations/2026_09_30_200000_create_checklist_renewals_table.php`, `app/Models/ChecklistRenewal.php`, `database/factories/ChecklistRenewalFactory.php`, `tests/Feature/Admin/OnFileTest.php`
- Modify: `app/Models/Application.php` (`renewals()`), `app/Services/ApplicationWorkflow.php` (`checklist()`, `onFileDocuments()`, `allRequiredAccepted()`, `STATE_ON_FILE`), `app/Mail/DocumentsRejected.php` (docblock only), `lang/ar/app.php`, `lang/en/app.php`

**Interfaces:**
- Consumes: `Application::latestDocuments()`, `Instructor::civilIdExpired()`, `ChecklistItem::renews_each_term`, `Document::STATUS_ACCEPTED`, `Term::teaching_starts_on`.
- Produces: `ChecklistRenewal` model (fillable `application_id, checklist_item_id, reason, requested_by, requested_at, notified_at`; relations `application()`, `item()`, `requester()`; casts datetimes); `Application::renewals(): HasMany`; `ApplicationWorkflow::STATE_ON_FILE = 'on_file'`; the five-key checklist row; `ApplicationWorkflow::onFileDocuments(Application): Collection<string, Document>` keyed by item code with `application.term` and `checklistItem` loaded; lang `app.documents.states.on_file`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Admin/OnFileTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\ChecklistItem;
use App\Models\ChecklistRenewal;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use App\Services\ApplicationWorkflow;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnFileTest extends TestCase
{
    use RefreshDatabase;

    private Instructor $instructor;

    private Term $old;

    private Term $current;

    private Application $previous;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChecklistItemSeeder::class);
        $this->instructor = Instructor::factory()->for(User::factory()->instructor())->create(['civil_id_expires_on' => now()->addYear()->toDateString()]);
        $this->old = Term::factory()->create(['academic_year' => '2025-2026', 'type' => 'second', 'teaching_starts_on' => '2026-01-11', 'teaching_ends_on' => '2026-05-14', 'status' => Term::STATUS_CLOSED]);
        $this->current = Term::factory()->open()->create(['academic_year' => '2026-2027', 'type' => 'first']);
        $this->previous = Application::factory()->for($this->old)->for($this->instructor)->create(['status' => Application::STATUS_APPROVED]);
        $this->application = Application::factory()->for($this->current)->for($this->instructor)->create();
    }

    private function workflow(): ApplicationWorkflow
    {
        return app(ApplicationWorkflow::class);
    }

    private function accepted(Application $app, string $code, ?string $reviewedAt = null): Document
    {
        return Document::factory()->for($app)->forItem($code)->accepted()->create(['reviewed_at' => $reviewedAt ?? now()->subMonth()]);
    }

    public function test_accepted_earlier_document_is_on_file_with_its_source(): void
    {
        $src = $this->accepted($this->previous, 'degree');

        $row = $this->workflow()->checklist($this->application)['degree'];

        $this->assertSame('on_file', $row['state']);
        $this->assertTrue($row['source']->is($src));
        $this->assertNull($row['document']);
        $this->assertNull($row['renewal']);
    }

    public function test_renewing_items_and_pending_or_rejected_copies_are_never_on_file(): void
    {
        $this->accepted($this->previous, 'salary_cert');
        Document::factory()->for($this->previous)->forItem('iban')->create();           // pending
        Document::factory()->for($this->previous)->forItem('civil_id')->rejected()->create();

        $c = $this->workflow()->checklist($this->application);

        $this->assertSame('missing', $c['salary_cert']['state']);
        $this->assertSame('missing', $c['iban']['state']);
        $this->assertSame('missing', $c['civil_id']['state']);
    }

    public function test_civil_id_on_file_only_while_not_expired(): void
    {
        $this->accepted($this->previous, 'civil_id');

        $this->instructor->update(['civil_id_expires_on' => now()->addDay()->toDateString()]);
        $this->assertSame('on_file', $this->workflow()->checklist($this->application->fresh())['civil_id']['state']);

        $this->instructor->update(['civil_id_expires_on' => now()->toDateString()]);
        $this->assertSame('missing', $this->workflow()->checklist($this->application->fresh())['civil_id']['state']);
    }

    public function test_latest_accepted_copy_wins_and_rejected_newer_copy_is_ignored(): void
    {
        $older = Term::factory()->create(['academic_year' => '2024-2025', 'type' => 'first', 'teaching_starts_on' => '2024-09-08', 'teaching_ends_on' => '2024-12-19', 'status' => Term::STATUS_CLOSED]);
        $oldest = Application::factory()->for($older)->for($this->instructor)->create(['status' => Application::STATUS_WITHDRAWN]);
        $a = $this->accepted($oldest, 'degree', '2024-10-01 10:00:00');
        $b = $this->accepted($this->previous, 'degree', '2026-02-01 10:00:00');
        $this->assertTrue($this->workflow()->checklist($this->application)['degree']['source']->is($b));

        Document::factory()->for($this->previous)->forItem('iban')->rejected()->create(['reviewed_at' => '2026-02-02 10:00:00']);
        $this->accepted($oldest, 'iban', '2024-10-02 10:00:00');
        $row = $this->workflow()->checklist($this->application->fresh())['iban'];
        $this->assertSame('on_file', $row['state']);
        $this->assertSame($oldest->id, $row['source']->application_id);
    }

    public function test_only_earlier_terms_and_other_applications_count(): void
    {
        $later = Term::factory()->create(['academic_year' => '2027-2028', 'type' => 'first', 'teaching_starts_on' => '2027-09-12', 'teaching_ends_on' => '2027-12-23', 'status' => Term::STATUS_CLOSED]);
        $future = Application::factory()->for($later)->for($this->instructor)->create(['status' => Application::STATUS_APPROVED]);
        $this->accepted($future, 'degree');
        $this->assertSame('missing', $this->workflow()->checklist($this->application)['degree']['state']);

        $stranger = Application::factory()->for($this->old)->create(['status' => Application::STATUS_APPROVED]);
        $this->accepted($stranger, 'iban');
        $this->assertSame('missing', $this->workflow()->checklist($this->application->fresh())['iban']['state']);
    }

    public function test_unreviewed_earlier_draft_or_withdrawn_application_gives_nothing(): void
    {
        $draft = Application::factory()->for($this->old)->for($this->instructor)->create(['status' => Application::STATUS_DRAFT, 'term_id' => $this->old->id]);
        $this->previous->update(['status' => Application::STATUS_WITHDRAWN]);
        Document::factory()->for($this->previous)->forItem('degree')->create();

        foreach ($this->workflow()->checklist($this->application) as $row) {
            $this->assertNotSame('on_file', $row['state']);
        }
    }

    public function test_document_in_this_application_and_renewal_row_take_precedence(): void
    {
        $this->accepted($this->previous, 'degree');
        $this->accepted($this->previous, 'iban');
        Document::factory()->for($this->application)->forItem('degree')->create();
        $item = ChecklistItem::where('code', 'iban')->first();
        $renewal = ChecklistRenewal::factory()->for($this->application)->create(['checklist_item_id' => $item->id, 'reason' => 'الآيبان تغير']);

        $c = $this->workflow()->checklist($this->application);

        $this->assertSame('pending', $c['degree']['state']);
        $this->assertNull($c['degree']['source']);
        $this->assertSame('missing', $c['iban']['state']);
        $this->assertTrue($c['iban']['renewal']->is($renewal));
    }

    public function test_on_file_counts_as_uploaded_and_accepted(): void
    {
        foreach (['civil_id', 'degree', 'iban'] as $code) {
            $this->accepted($this->previous, $code);
        }
        foreach (['salary_cert', 'employer_approval', 'undertaking'] as $code) {
            Document::factory()->for($this->application)->forItem($code)->accepted()->create();
        }

        $this->assertTrue($this->workflow()->allRequiredUploaded($this->application));
        $this->assertTrue($this->workflow()->allRequiredAccepted($this->application));
    }
}
```

(The instructor factory's defaults give a Kuwaiti master's holder in the government sector, so the required items are exactly `civil_id, degree, salary_cert, iban, employer_approval, undertaking`, as `ReviewTest::setUp` relies on.)

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Admin/OnFileTest.php`
Expected: FAIL — `ChecklistRenewal` missing, states `missing` where `on_file` is expected.

- [ ] **Step 3: Migration, model, factory**

`database/migrations/2026_09_30_200000_create_checklist_renewals_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_renewals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checklist_item_id')->constrained()->restrictOnDelete();
            $table->string('reason', 500);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->unique(['application_id', 'checklist_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_renewals');
    }
};
```

`app/Models/ChecklistRenewal.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An admin's "request a new copy" for an item that would otherwise be on file (spec §4.4). */
class ChecklistRenewal extends Model
{
    use HasFactory;

    protected $fillable = ['application_id', 'checklist_item_id', 'reason', 'requested_by', 'requested_at', 'notified_at'];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'notified_at' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ChecklistItem::class, 'checklist_item_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
```

`database/factories/ChecklistRenewalFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\ChecklistItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class ChecklistRenewalFactory extends Factory
{
    public function definition(): array
    {
        return [
            'application_id' => Application::factory()->submitted(),
            'checklist_item_id' => fn () => ChecklistItem::where('code', 'degree')->value('id'),
            'reason' => 'يرجى إرفاق نسخة أوضح', 'requested_at' => now(),
        ];
    }
}
```

`app/Models/Application.php` — add `public function renewals(): HasMany { return $this->hasMany(ChecklistRenewal::class); }`.

- [ ] **Step 4: Derivation**

In `app/Services/ApplicationWorkflow.php` add the constant and replace `checklist()` and `allRequiredAccepted()`; add `onFileDocuments()` (imports `Illuminate\Support\Collection`):

```php
    public const STATE_ON_FILE = 'on_file';

    /**
     * Spec §4.2. One row per required item:
     * ['item' => ChecklistItem, 'document' => ?Document, 'state' => string, 'source' => ?Document, 'renewal' => ?ChecklistRenewal]
     *
     * @return array<string, array{item: ChecklistItem, document: ?Document, state: string, source: ?Document, renewal: ?ChecklistRenewal}>
     */
    public function checklist(Application $application): array
    {
        $plan = $this->resolver->for($application->instructor);
        $docs = $application->latestDocuments();
        $renewals = $application->renewals()->get()->keyBy('checklist_item_id');
        $onFile = null;
        $out = [];
        foreach ($plan->required as $item) {
            $doc = $docs->get($item->code);
            $row = ['item' => $item, 'document' => $doc, 'state' => 'missing', 'source' => null, 'renewal' => $renewals->get($item->id)];
            if ($doc) {
                $row['state'] = $doc->status;
            } elseif ($row['renewal'] === null && ! $item->renews_each_term
                && ! ($item->code === 'civil_id' && $application->instructor->civilIdExpired())) {
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

    /**
     * Latest accepted document per item code from the instructor's other applications in earlier terms
     * (spec §4.2 rule 5). Keyed by item code; `checklistItem` and `application.term` are loaded.
     *
     * @return Collection<string, Document>
     */
    public function onFileDocuments(Application $application): Collection
    {
        $termStart = $application->term->teaching_starts_on;

        return Document::query()
            ->where('status', Document::STATUS_ACCEPTED)
            ->whereHas('application', fn ($q) => $q->where('instructor_id', $application->instructor_id)
                ->whereKeyNot($application->id)
                ->whereHas('term', fn ($t) => $t->where('teaching_starts_on', '<', $termStart)))
            ->with(['checklistItem', 'application.term'])
            ->orderByDesc('reviewed_at')->orderByDesc('id')
            ->get()
            ->unique('checklist_item_id')
            ->keyBy(fn (Document $d) => $d->checklistItem->code);
    }

    public function allRequiredAccepted(Application $application): bool
    {
        foreach ($this->checklist($application) as $row) {
            if (! in_array($row['state'], [Document::STATUS_ACCEPTED, self::STATE_ON_FILE], true)) {
                return false;
            }
        }

        return true;
    }
```

`allRequiredUploaded()` already treats anything but `missing`/`rejected` as uploaded; leave it. Update the docblocks of `pendingRejectionNotices()` and `DocumentsRejected::__construct` to the five-key row shape (Task 3 changes their behaviour).

Note `Instructor::civilIdExpired()` uses `isPast()` on a date cast (midnight): an expiry date of today is past by the time the check runs, tomorrow is not — this matches "`<= today` is expired".

Lang, `documents.states` block: ar `'on_file' => 'على الملف'`, en `'on_file' => 'On file'`.

- [ ] **Step 5: Run the tests**

Run: `php artisan test --compact tests/Feature/Admin/OnFileTest.php`
Expected: PASS (8 tests). Whole suite: PASS (the milestone 1/2 review tests still hold because they create documents in the same application).

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint app/Models/ChecklistRenewal.php app/Models/Application.php app/Services/ApplicationWorkflow.php app/Mail/DocumentsRejected.php database/factories/ChecklistRenewalFactory.php database/migrations/2026_09_30_200000_create_checklist_renewals_table.php tests/Feature/Admin/OnFileTest.php
git add app database lang tests
git commit -m "feat(checklist): on-file derivation from earlier accepted documents, renewal overrides table"
```

---

### Task 3: "طلب نسخة جديدة" — action, status change, notice email

**Files:**
- Create: `tests/Feature/Admin/FreshCopyTest.php`
- Modify: `app/Services/ApplicationWorkflow.php` (`requestFreshCopy()`, `pendingRejectionNotices()`, `notifyRejections()`), `app/Http/Controllers/Admin/ApplicationController.php` (`requestFreshCopy()`), `routes/web.php`, `resources/views/emails/documents-rejected.blade.php`, `lang/ar/app.php`, `lang/en/app.php`

**Interfaces:**
- Consumes: Task 2 row shape, `ChecklistRenewal`, `Application::UNFINISHED_STATUSES`, `ApplicationPolicy::review`.
- Produces: `ApplicationWorkflow::requestFreshCopy(Application, ChecklistItem, User $admin, string $reason): void`; route `admin.applications.renewals.store` POST `applications/{application}/renewals/{item:code}` (with `withoutScopedBindings()`), body `reason` (required, max 500); `pendingRejectionNotices()` also returns renewal rows not yet notified; `notifyRejections()` marks them notified; lang keys `app.review.request_fresh_copy`, `fresh_copy_reason`, `fresh_copy_requested`, `fresh_copy_wrong_state`, `fresh_copy_wrong_status`, `app.documents.renewal_requested` (`:reason`).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Admin/FreshCopyTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Mail\DocumentsRejected;
use App\Models\Application;
use App\Models\ChecklistRenewal;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use App\Services\ApplicationWorkflow;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class FreshCopyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Instructor $instructor;

    private Application $previous;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(ChecklistItemSeeder::class);
        $this->admin = User::factory()->admin()->create();
        $this->instructor = Instructor::factory()->for(User::factory()->instructor())->create();
        $old = Term::factory()->create(['academic_year' => '2025-2026', 'type' => 'second', 'teaching_starts_on' => '2026-01-11', 'teaching_ends_on' => '2026-05-14', 'status' => Term::STATUS_CLOSED]);
        $current = Term::factory()->open()->create(['academic_year' => '2026-2027', 'type' => 'first']);
        $this->previous = Application::factory()->for($old)->for($this->instructor)->create(['status' => Application::STATUS_APPROVED]);
        foreach (['civil_id', 'degree', 'iban'] as $code) {
            Document::factory()->for($this->previous)->forItem($code)->accepted()->create(['reviewed_at' => now()->subMonth()]);
        }
        $this->application = Application::factory()->for($current)->for($this->instructor)->create(['status' => Application::STATUS_UNDER_REVIEW, 'submitted_at' => now(), 'reviewed_at' => now()]);
        foreach (['salary_cert', 'employer_approval', 'undertaking'] as $code) {
            Document::factory()->for($this->application)->forItem($code)->accepted()->create();
        }
    }

    private function url(string $code): string
    {
        return route('admin.applications.renewals.store', [$this->application, $code]);
    }

    public function test_request_creates_row_marks_incomplete_and_audits(): void
    {
        $this->actingAs($this->admin)->post($this->url('degree'), ['reason' => 'الشهادة غير واضحة'])
            ->assertRedirect()->assertSessionHas('status', __('app.review.fresh_copy_requested'));

        $this->assertDatabaseHas('checklist_renewals', ['application_id' => $this->application->id, 'reason' => 'الشهادة غير واضحة', 'requested_by' => $this->admin->id, 'notified_at' => null]);
        $this->assertSame(Application::STATUS_INCOMPLETE, $this->application->fresh()->status);
        $this->assertNull($this->application->fresh()->complete_at);
        $this->assertDatabaseHas('audit_log', ['action' => 'request_fresh_copy', 'subject_id' => $this->application->id, 'details' => 'degree']);
        $this->assertSame('missing', app(ApplicationWorkflow::class)->checklist($this->application->fresh())['degree']['state']);
        Mail::assertNothingSent();
    }

    public function test_request_refused_for_non_on_file_row_closed_term_wrong_status_and_instructor(): void
    {
        $this->actingAs($this->admin)->post($this->url('salary_cert'), ['reason' => 'x'])->assertSessionHasErrors(['renewal' => __('app.review.fresh_copy_wrong_state')]);
        $this->actingAs($this->admin)->post($this->url('degree'), [])->assertSessionHasErrors('reason');

        $this->application->update(['status' => Application::STATUS_APPROVED]);
        $this->actingAs($this->admin)->post($this->url('degree'), ['reason' => 'x'])->assertSessionHasErrors(['renewal' => __('app.review.fresh_copy_wrong_status')]);
        $this->application->update(['status' => Application::STATUS_UNDER_REVIEW]);

        $this->application->term->update(['status' => Term::STATUS_CLOSED]);
        $this->actingAs($this->admin)->post($this->url('degree'), ['reason' => 'x'])->assertSessionHasErrors('renewal');
        $this->application->term->update(['status' => Term::STATUS_OPEN]);

        $this->actingAs($this->instructor->user)->post($this->url('degree'), ['reason' => 'x'])->assertForbidden();
        $this->assertSame(0, ChecklistRenewal::count());
    }

    public function test_request_is_included_in_the_notice_email_once(): void
    {
        $this->actingAs($this->admin)->post($this->url('degree'), ['reason' => 'الشهادة غير واضحة']);
        $wf = app(ApplicationWorkflow::class);
        $this->assertCount(1, $wf->pendingRejectionNotices($this->application->fresh()));

        $this->actingAs($this->admin)->post(route('admin.applications.notify_rejections', $this->application))->assertRedirect();

        Mail::assertSent(DocumentsRejected::class, function (DocumentsRejected $m) {
            $html = $m->render();

            return str_contains($html, 'صورة من المؤهل العلمي') && str_contains($html, 'الشهادة غير واضحة');
        });
        $this->assertNotNull(ChecklistRenewal::first()->notified_at);
        $this->assertCount(0, $wf->pendingRejectionNotices($this->application->fresh()));
    }

    public function test_fresh_upload_after_request_is_accepted_and_application_resubmits(): void
    {
        $this->actingAs($this->admin)->post($this->url('degree'), ['reason' => 'x']);
        $wf = app(ApplicationWorkflow::class);

        $doc = Document::factory()->for($this->application)->forItem('degree')->create();
        $wf->afterUpload($this->application->fresh());
        $this->assertSame(Application::STATUS_SUBMITTED, $this->application->fresh()->status);

        $wf->markUnderReview($this->application->fresh());
        $wf->reviewDocument($doc, $this->admin, Document::STATUS_ACCEPTED, null);

        $row = $wf->checklist($this->application->fresh())['degree'];
        $this->assertSame('accepted', $row['state']);
        $this->assertNotNull($row['renewal']);
        $this->assertTrue($wf->allRequiredAccepted($this->application->fresh()));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Admin/FreshCopyTest.php`
Expected: FAIL — route undefined.

- [ ] **Step 3: Workflow**

Add to `ApplicationWorkflow` (imports `App\Models\ChecklistRenewal`):

```php
    /** Spec §4.4: an admin demands a fresh copy of an on-file item. */
    public function requestFreshCopy(Application $application, ChecklistItem $item, User $admin, string $reason): void
    {
        if (! $application->term->isOpen()) {
            throw new \DomainException(__('app.applications.term_closed'));
        }
        if (! in_array($application->status, Application::UNFINISHED_STATUSES, true)) {
            throw new \DomainException(__('app.review.fresh_copy_wrong_status'));
        }
        $row = $this->checklist($application)[$item->code] ?? null;
        if (($row['state'] ?? null) !== self::STATE_ON_FILE) {
            throw new \DomainException(__('app.review.fresh_copy_wrong_state'));
        }

        DB::transaction(function () use ($application, $item, $admin, $reason) {
            ChecklistRenewal::updateOrCreate(
                ['application_id' => $application->id, 'checklist_item_id' => $item->id],
                ['reason' => $reason, 'requested_by' => $admin->id, 'requested_at' => now(), 'notified_at' => null],
            );
            $application->update(['status' => Application::STATUS_INCOMPLETE, 'complete_at' => null, 'reviewed_at' => $application->reviewed_at ?? now()]);
            AuditLog::record($admin->id, 'request_fresh_copy', $application, null, $item->code);
        });
    }
```

Replace `pendingRejectionNotices()` and the marking loop in `notifyRejections()`:

```php
    /** Rows the applicant has not been told about yet: rejected documents and fresh-copy requests. */
    public function pendingRejectionNotices(Application $application): array
    {
        return array_values(array_filter($this->checklist($application), fn ($row) => $this->needsNotice($row) && $this->noticeSent($row) === false));
    }

    private function needsNotice(array $row): bool
    {
        return $row['state'] === Document::STATUS_REJECTED || ($row['renewal'] !== null && $row['document'] === null);
    }

    private function noticeSent(array $row): bool
    {
        return $row['state'] === Document::STATUS_REJECTED
            ? $row['document']->notified_at !== null
            : $row['renewal']->notified_at !== null;
    }
```

and in `notifyRejections()`:

```php
        $rows = array_values(array_filter($this->checklist($application), fn ($row) => $this->needsNotice($row)));
        foreach ($rows as $row) {
            ($row['state'] === Document::STATUS_REJECTED ? $row['document'] : $row['renewal'])->update(['notified_at' => now()]);
        }
        AuditLog::record($admin->id, 'notify_rejections', $application);
        $this->safeSend($application->instructor->user->email, new DocumentsRejected($application, $rows));

        return count($rows);
```

(Rename the local `$rejected` to `$rows`; the guard `pendingRejectionNotices($application) === []` stays.)

- [ ] **Step 4: Controller, route, email, lang**

`app/Http/Controllers/Admin/ApplicationController.php` — add (imports `App\Models\ChecklistItem`):

```php
    public function requestFreshCopy(Request $request, Application $application, ChecklistItem $item): RedirectResponse
    {
        $this->authorize('review', $application);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        try {
            $this->workflow->requestFreshCopy($application, $item, $request->user(), $data['reason']);
        } catch (\DomainException $e) {
            return back()->withErrors(['renewal' => $e->getMessage()]);
        }

        return back()->with('status', __('app.review.fresh_copy_requested'));
    }
```

Route, in the admin group next to the other `applications/{application}/...` routes:

```php
    Route::post('applications/{application}/renewals/{item:code}', [AdminApplicationController::class, 'requestFreshCopy'])->name('applications.renewals.store')->withoutScopedBindings();
```

`resources/views/emails/documents-rejected.blade.php` — the list item becomes:

```blade
            <li>
                {{ $row['item']->label_ar }}
                @if ($row['state'] === 'rejected' && $row['document']?->rejection_reason)
                    — {{ $row['document']->rejection_reason }}
                @elseif ($row['renewal'])
                    — {{ __('app.documents.renewal_requested', ['reason' => $row['renewal']->reason], 'ar') }}
                @endif
            </li>
```

Lang — `review` block, ar: `'request_fresh_copy' => 'طلب نسخة جديدة', 'fresh_copy_reason' => 'سبب طلب النسخة الجديدة', 'fresh_copy_requested' => 'تم طلب نسخة جديدة من المستند وإعادة الطلب إلى حالة غير مكتمل.', 'fresh_copy_wrong_state' => 'لا يمكن طلب نسخة جديدة إلا لمستند على الملف.', 'fresh_copy_wrong_status' => 'لا يمكن طلب نسخة جديدة في حالة الطلب الحالية.',`; en: `'request_fresh_copy' => 'Request a new copy', 'fresh_copy_reason' => 'Reason for the new copy', 'fresh_copy_requested' => 'A new copy was requested; the application is back to incomplete.', 'fresh_copy_wrong_state' => 'A new copy can only be requested for an on-file document.', 'fresh_copy_wrong_status' => 'A new copy cannot be requested in the application\'s current status.',`. `documents` block, ar: `'renewal_requested' => 'مطلوب نسخة جديدة: :reason'`, en: `'renewal_requested' => 'A new copy is required: :reason'`.

- [ ] **Step 5: Run the tests**

Run: `php artisan test --compact tests/Feature/Admin/FreshCopyTest.php tests/Feature/Admin/RejectionNotifyTest.php tests/Feature/Admin/ReviewTest.php`
Expected: PASS. Whole suite: PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint app/Services/ApplicationWorkflow.php app/Http/Controllers/Admin/ApplicationController.php tests/Feature/Admin/FreshCopyTest.php
git add app routes resources/views/emails lang tests
git commit -m "feat(checklist): admin can request a fresh copy of an on-file document; notice email covers it"
```

---

### Task 4: Screens and the printed Check List

**Files:**
- Modify: `resources/views/instructor/application.blade.php`, `resources/views/instructor/_upload.blade.php`, `resources/views/admin/applications/show.blade.php`, `app/Services/ChecklistDocument.php`, `lang/ar/app.php`, `lang/en/app.php`
- Test: `tests/Feature/Instructor/ApplicationTest.php` (add), `tests/Feature/Admin/ReviewTest.php` (add), `tests/Feature/Admin/ChecklistDocumentTest.php` (add)

**Interfaces:**
- Consumes: the row shape (`source`, `renewal`), route `admin.applications.renewals.store`, `Application::UNFINISHED_STATUSES`, `$termOpen` in the admin view, admin document routes.
- Produces: lang `app.documents.on_file_from` (`:term`), `app.documents.newer_copy`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Instructor/ApplicationTest.php` (mirror its `setUp`; if it has no seeded checklist or instructor, create them in the test as `OnFileTest` does):

```php
    public function test_on_file_row_shows_badge_source_term_and_optional_upload(): void
    {
        $this->seed(\Database\Seeders\ChecklistItemSeeder::class);
        $user = \App\Models\User::factory()->instructor()->create();
        $instructor = \App\Models\Instructor::factory()->for($user)->create();
        $old = \App\Models\Term::factory()->create(['academic_year' => '2025-2026', 'type' => 'second', 'teaching_starts_on' => '2026-01-11', 'teaching_ends_on' => '2026-05-14', 'status' => \App\Models\Term::STATUS_CLOSED]);
        $previous = \App\Models\Application::factory()->for($old)->for($instructor)->create(['status' => \App\Models\Application::STATUS_APPROVED]);
        \App\Models\Document::factory()->for($previous)->forItem('degree')->accepted()->create(['reviewed_at' => now()->subMonth()]);
        $application = \App\Models\Application::factory()->for(\App\Models\Term::factory()->open()->create(['academic_year' => '2026-2027']))->for($instructor)->create();
        $item = \App\Models\ChecklistItem::where('code', 'iban')->first();
        \App\Models\ChecklistRenewal::factory()->for($application)->create(['checklist_item_id' => $item->id, 'reason' => 'الآيبان تغير']);

        $r = $this->actingAs($user)->get(route('instructor.applications.show', $application))->assertOk();

        $r->assertSee(__('app.documents.states.on_file'));
        $r->assertSee(__('app.documents.on_file_from', ['term' => $old->label()]));
        $r->assertSee(__('app.documents.newer_copy'));
        $r->assertSee(__('app.documents.renewal_requested', ['reason' => 'الآيبان تغير']));
    }
```

Append to `tests/Feature/Admin/ReviewTest.php`:

```php
    public function test_admin_sees_on_file_link_and_fresh_copy_form_only_when_allowed(): void
    {
        $instructor = $this->application->instructor;
        $old = Term::factory()->create(['academic_year' => '2025-2026', 'type' => 'second', 'teaching_starts_on' => '2026-01-11', 'teaching_ends_on' => '2026-05-14', 'status' => Term::STATUS_CLOSED]);
        $previous = Application::factory()->for($old)->for($instructor)->create(['status' => Application::STATUS_APPROVED]);
        $src = Document::factory()->for($previous)->forItem('degree')->accepted()->create(['reviewed_at' => now()->subMonth()]);
        $this->application->latestDocuments()->get('degree')->delete();

        $r = $this->actingAs($this->admin)->get(route('admin.applications.show', $this->application))->assertOk();
        $r->assertSee(__('app.documents.states.on_file'));
        $r->assertSee(route('admin.documents.view', $src));
        $r->assertSee(route('admin.applications.renewals.store', [$this->application, 'degree']));

        $this->application->update(['status' => Application::STATUS_APPROVED]);
        $this->actingAs($this->admin)->get(route('admin.applications.show', $this->application))
            ->assertDontSee(route('admin.applications.renewals.store', [$this->application, 'degree']));
    }
```

Append to `tests/Feature/Admin/ChecklistDocumentTest.php` (its `setUp` only fakes the disk; it has a `docxText(string $path)` helper):

```php
    public function test_on_file_items_print_as_present(): void
    {
        $this->seed(ChecklistItemSeeder::class);
        $admin = User::factory()->admin()->create();
        $instructor = Instructor::factory()->for(User::factory()->instructor())->create();
        $old = Term::factory()->create(['academic_year' => '2025-2026', 'type' => 'second', 'teaching_starts_on' => '2026-01-11', 'teaching_ends_on' => '2026-05-14', 'status' => Term::STATUS_CLOSED]);
        $previous = Application::factory()->for($old)->for($instructor)->create(['status' => Application::STATUS_APPROVED]);
        Document::factory()->for($previous)->forItem('degree')->accepted()->create(['reviewed_at' => now()->subMonth()]);
        $app = Application::factory()->for(Term::factory()->open()->create(['academic_year' => '2026-2027', 'type' => 'first']))->for($instructor)->create();

        $text = $this->docxText(app(ChecklistDocument::class)->build($app, $admin));

        $this->assertStringContainsString('☑ صورة من المؤهل العلمي', $text);
        $this->assertStringContainsString('☐ صورة البطاقة المدنية سارية المفعول', $text);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Instructor/ApplicationTest.php tests/Feature/Admin/ReviewTest.php tests/Feature/Admin/ChecklistDocumentTest.php`
Expected: FAIL — lang keys and markup missing; the Check List prints ☐.

- [ ] **Step 3: Views and document**

`resources/views/instructor/application.blade.php` — in the state cell:

```blade
                    <td>
                        <span class="badge {{ $row['state'] === 'on_file' ? 'bg-info text-dark' : 'bg-secondary' }}">{{ __('app.documents.states.'.$row['state']) }}</span>
                        @if ($row['state'] === 'on_file')
                            <div class="small text-muted">{{ __('app.documents.on_file_from', ['term' => $row['source']->application->term->label()]) }}</div>
                        @endif
                        @if ($row['state'] === 'rejected' && $document?->rejection_reason)
                            <div class="small text-danger">{{ $document->rejection_reason }}</div>
                        @endif
                        @if ($row['renewal'] && ! $document)
                            <div class="small text-danger">{{ __('app.documents.renewal_requested', ['reason' => $row['renewal']->reason]) }}</div>
                        @endif
                    </td>
```

and pass the state to the upload partial: `@include('instructor._upload', ['application' => $application, 'item' => $item, 'document' => $document, 'onFile' => $row['state'] === 'on_file'])`.

`resources/views/instructor/_upload.blade.php` — the button label:

```blade
    <button class="btn btn-sm btn-eet text-nowrap">{{ ($onFile ?? false) ? __('app.documents.newer_copy') : ($document ? __('app.documents.replace') : __('app.documents.upload')) }}</button>
```

`resources/views/admin/applications/show.blade.php` — state cell and file cell and actions cell:

```blade
                <td>
                    <span class="badge {{ $row['state'] === 'on_file' ? 'bg-info text-dark' : 'bg-secondary' }}">{{ __('app.documents.states.'.$row['state']) }}</span>
                    @if ($row['state'] === 'on_file')
                        <div class="small text-muted">{{ __('app.documents.on_file_from', ['term' => $row['source']->application->term->label()]) }}</div>
                    @endif
                    @if ($row['state'] === 'rejected' && $document?->rejection_reason)
                        <div class="small text-danger">{{ $document->rejection_reason }}</div>
                    @endif
                    @if ($row['renewal'] && ! $document)
                        <div class="small text-danger">{{ __('app.documents.renewal_requested', ['reason' => $row['renewal']->reason]) }} ({{ $row['renewal']->requester?->name }})</div>
                    @endif
                </td>
                <td>
                    @php($file = $document ?? $row['source'])
                    @if ($file)
                        <a href="{{ route('admin.documents.view', $file) }}" target="_blank">{{ __('app.review.view') }}</a>
                        —
                        <a href="{{ route('admin.documents.download', $file) }}">{{ __('app.documents.download') }}</a>
                        ({{ __('app.documents.version') }} {{ $file->version }})
                    @endif
                </td>
                <td>
                    @if ($document && ! $application->isFinal() && $termOpen)
                        … existing accept/reject forms unchanged …
                    @elseif ($row['state'] === 'on_file' && $termOpen && in_array($application->status, \App\Models\Application::UNFINISHED_STATUSES, true))
                        <form method="post" action="{{ route('admin.applications.renewals.store', [$application, $item->code]) }}" class="d-flex gap-1">
                            @csrf
                            <input type="text" name="reason" class="form-control form-control-sm" placeholder="{{ __('app.review.fresh_copy_reason') }}" maxlength="500" required>
                            <button type="submit" class="btn btn-sm btn-outline-warning text-nowrap">{{ __('app.review.request_fresh_copy') }}</button>
                        </form>
                    @endif
                </td>
```

Add `@if ($errors->has('renewal')) <div class="alert alert-danger">{{ $errors->first('renewal') }}</div> @endif` near the page's other error alerts.

`app/Services/ChecklistDocument.php` — the mark: `$mark = in_array($checklist[$item->code]['state'], ['accepted', ApplicationWorkflow::STATE_ON_FILE], true) ? '☑' : '☐';`.

Lang `documents` block — ar: `'on_file_from' => 'على الملف من :term', 'newer_copy' => 'رفع نسخة أحدث (اختياري)',`; en: `'on_file_from' => 'On file from :term', 'newer_copy' => 'Upload a newer copy (optional)',`.

- [ ] **Step 4: Run the tests**

Run: `php artisan test --compact tests/Feature/Instructor tests/Feature/Admin/ReviewTest.php tests/Feature/Admin/ChecklistDocumentTest.php`
Expected: PASS. Whole suite: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint app/Services/ChecklistDocument.php tests/Feature/Instructor/ApplicationTest.php tests/Feature/Admin/ReviewTest.php tests/Feature/Admin/ChecklistDocumentTest.php
git add app resources lang tests
git commit -m "feat(checklist): on-file rows on instructor and admin screens and the printed Check List"
```

---

### Task 5: Parked attestation fixes, template underline, docs

**Files:**
- Modify: `app/Services/Attestations/AttestationService.php` (`update()`), `app/Services/Attestations/AttestationDocument.php` (`plain()`), `app/Services/Attestations/PdfConverter.php`, `app/Services/Attestations/AttestationGenerator.php` (`holdsLastTeachingDay()`), `app/Models/Term.php` (remove `attestations()`), `resources/views/admin/attestations/show.blade.php`, `scripts/build-kh3-template.py` + `resources/forms/kh3-template.docx` (rebuilt), `lang/ar/app.php`, `lang/en/app.php`, `CLAUDE.md`, `PROGRESS.md`
- Test: `tests/Feature/Admin/AttestationsTest.php`, `tests/Unit/Attestations/AttestationDocumentTest.php`, `tests/Unit/Attestations/PdfConverterTest.php`, `tests/Unit/Attestations/AttestationGeneratorTest.php`, `tests/Fixtures/fake-soffice.sh`

**Interfaces:**
- Consumes: milestone 3 classes as they are on `main`.
- Produces: `AttestationService::update()` throws `DomainException(__('app.attestations.stale_form'))` when no posted week id belongs to the attestation; `Kh3` template without underline in the note cell.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Admin/AttestationsTest.php`:

```php
    public function test_save_with_stale_week_ids_is_refused_and_writes_nothing(): void
    {
        $a = $this->generated();
        $oldId = $a->weeks[0]->id;
        $this->actingAs($this->admin)->post(route('admin.attestations.regenerate', $a));   // new week rows, new ids

        $this->actingAs($this->admin)->put(route('admin.attestations.update', $a), ['weeks' => [
            $oldId => ['courses_text' => 'x', 'student_count' => 5, 'theory_hours' => '1', 'practical_hours' => '0', 'field_hours' => '0', 'note_ar' => ''],
        ]])->assertSessionHasErrors(['attestation' => __('app.attestations.stale_form')]);

        $this->assertSame(0, $a->fresh()->weeks->where('student_count', 5)->count());
        $this->assertDatabaseMissing('audit_log', ['action' => 'update_attestation']);
    }

    public function test_regenerate_button_hidden_and_action_refused_without_assignments(): void
    {
        $a = $this->generated();
        \App\Models\Assignment::where('application_id', $this->assigned->id)->delete();

        $this->actingAs($this->admin)->get(route('admin.attestations.show', $a))->assertDontSee(route('admin.attestations.regenerate', $a));
        $this->actingAs($this->admin)->post(route('admin.attestations.regenerate', $a))->assertSessionHasErrors(['attestation' => __('app.attestations.no_assignments')]);
    }
```

Append to `tests/Unit/Attestations/AttestationDocumentTest.php`:

```php
    public function test_nested_placeholder_markers_in_free_text_print_literally(): void
    {
        $a = $this->attestation();
        $a->weeks[0]->update(['note_ar' => 'ملاحظة $${{cid1#1} ونهاية']);

        $xml = $this->documentXml(app(AttestationDocument::class)->docx($a->fresh()->load('weeks', 'application.instructor', 'application.term')));

        $this->assertStringContainsString('ملاحظة {cid1#1} ونهاية', $xml);
        $this->assertStringNotContainsString('ملاحظة 2 ونهاية', $xml);
    }

    public function test_template_note_cell_has_no_underline(): void
    {
        $zip = new \ZipArchive;
        $zip->open(resource_path('forms/kh3-template.docx'));
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        preg_match('~<w:tc>(?:(?!<w:tc>).)*?\$\{week_note\}.*?</w:tc>~s', $xml, $m);
        $this->assertNotEmpty($m, 'week_note cell not found');
        $this->assertStringNotContainsString('<w:u ', $m[0]);
        $this->assertStringNotContainsString('<w:u/>', $m[0]);
    }
```

Append to `tests/Unit/Attestations/PdfConverterTest.php`:

```php
    public function test_failed_run_that_still_wrote_a_pdf_leaves_nothing_behind(): void
    {
        $fake = base_path('tests/Fixtures/fake-soffice.sh');
        chmod($fake, 0755);
        $dir = storage_path('app/private/generated/tmp');
        \Illuminate\Support\Facades\File::ensureDirectoryExists($dir);
        $docx = $dir.'/unit-'.uniqid().'.docx';
        file_put_contents($docx, 'x');
        putenv('FAKE_SOFFICE_WRITE_THEN_FAIL=1');
        $_SERVER['FAKE_SOFFICE_WRITE_THEN_FAIL'] = '1';
        try {
            $this->expectException(\RuntimeException::class);
            (new PdfConverter($fake))->convert($docx);
        } finally {
            putenv('FAKE_SOFFICE_WRITE_THEN_FAIL');
            unset($_SERVER['FAKE_SOFFICE_WRITE_THEN_FAIL']);
            $this->assertFileDoesNotExist(substr($docx, 0, -5).'.pdf');
            unlink($docx);
        }
    }

    public function test_process_runs_in_the_output_directory(): void
    {
        $fake = base_path('tests/Fixtures/fake-soffice.sh');
        chmod($fake, 0755);
        $dir = storage_path('app/private/generated/tmp');
        \Illuminate\Support\Facades\File::ensureDirectoryExists($dir);
        $docx = $dir.'/unit-'.uniqid().'.docx';
        file_put_contents($docx, 'x');
        putenv('FAKE_SOFFICE_RECORD_CWD=1');
        $_SERVER['FAKE_SOFFICE_RECORD_CWD'] = '1';
        try {
            $pdf = (new PdfConverter($fake))->convert($docx);
            $this->assertSame(realpath($dir), trim(file_get_contents($dir.'/cwd.txt')));
        } finally {
            putenv('FAKE_SOFFICE_RECORD_CWD');
            unset($_SERVER['FAKE_SOFFICE_RECORD_CWD']);
            @unlink($dir.'/cwd.txt');
            @unlink($docx);
            @unlink($pdf ?? '');
        }
    }
```

Extend `tests/Fixtures/fake-soffice.sh` before the final `printf`:

```sh
[ "${FAKE_SOFFICE_RECORD_CWD:-0}" = "1" ] && pwd > "$outdir/cwd.txt"
if [ "${FAKE_SOFFICE_WRITE_THEN_FAIL:-0}" = "1" ]; then printf '%%PDF-1.4 partial\n' > "$outdir/$base.pdf"; echo "crashed after writing" >&2; exit 1; fi
```

Append to `tests/Unit/Attestations/AttestationGeneratorTest.php`:

```php
    public function test_last_day_line_on_a_saturday_first_of_month_goes_to_the_previous_month(): void
    {
        $term = Term::factory()->open()->create(['academic_year' => '2026-2027', 'type' => 'summer', 'teaching_starts_on' => '2026-06-07', 'teaching_ends_on' => '2026-08-01']); // Saturday
        $app = Application::factory()->approved()->for($term)->create();
        Assignment::factory()->for($app)->for(Section::factory()->for($term)->withMeetings()->create())->create();

        $july = $this->generator()->generate($app, 2026, 7);
        $this->assertStringContainsString('آخر يوم دراسي 1 أغسطس 2026', $july->weeks->last()->note_ar);

        $august = $this->generator()->generate($app, 2026, 8);
        $this->assertCount(0, $august->weeks);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Admin/AttestationsTest.php tests/Unit/Attestations`
Expected: FAIL on the five new tests.

- [ ] **Step 3: Implement**

`AttestationService::update()` becomes:

```php
    public function update(Attestation $attestation, array $weeks, User $by): array
    {
        return DB::transaction(function () use ($attestation, $weeks, $by) {
            $attestation = Attestation::whereKey($attestation->id)->lockForUpdate()->with(['weeks', 'application.term'])->firstOrFail();
            $this->assertEditable($attestation);
            $matched = false;
            $changed = [];
            foreach ($attestation->weeks as $week) {
                if (! isset($weeks[$week->id])) {
                    continue;
                }
                $matched = true;
                $data = array_intersect_key($weeks[$week->id], array_flip(AttestationWeek::EDITABLE));
                foreach ($data as $col => $value) {
                    if ((string) $week->$col !== (string) $value) {
                        $changed[] = $col;
                    }
                }
                $week->fill($data)->save();
            }
            if (! $matched) {
                throw new DomainException(__('app.attestations.stale_form'));
            }
            $changed = array_values(array_unique($changed));
            sort($changed);
            if ($changed !== []) {
                AuditLog::record($by->id, 'update_attestation', $attestation, null, implode(',', $changed));
            }

            return $changed;
        });
    }
```

(`use Illuminate\Support\Facades\DB;`.) `regenerate()` gains, after `assertEditable`: `if ($attestation->application->assignments()->doesntExist()) { throw new DomainException(__('app.attestations.no_assignments')); }`. Lang: `'stale_form' => 'تغيرت بيانات المزاولة منذ فتح الصفحة؛ أعد تحميلها ثم كرر التعديل.'` / `'stale_form' => 'The attestation changed since the page was opened; reload and try again.'`.

`show.blade.php`: wrap the regenerate form in `@if ($editable && $attestation->application->assignments()->exists())` (load once in the controller as `$hasAssignments` if you prefer; either is acceptable).

`AttestationDocument::plain()`:

```php
    private function plain(string $text): string
    {
        do {
            $before = $text;
            $text = str_replace('${', '', $text);
        } while ($text !== $before);

        return $text;
    }
```

`PdfConverter::convert()`: after constructing the process add `$process->setWorkingDirectory($dir);`; in the failure branch, before throwing: `if (is_file($pdf)) { @unlink($pdf); }`.

`AttestationGenerator::holdsLastTeachingDay()`:

```php
    private function holdsLastTeachingDay(Term $term, Carbon $sunday, Carbon $monthStart, Carbon $monthEnd): bool
    {
        $end = $term->teaching_ends_on->copy()->startOfDay();
        if (! $end->between($sunday, $sunday->copy()->addDays(6))) {
            return false;
        }
        if ($end->between($monthStart, $monthEnd)) {
            return true;
        }
        // A Friday/Saturday end on the 1st/2nd of the next month: this block's Thursday is still in this month.
        return $end->gt($monthEnd) && $sunday->copy()->addDays(4)->between($monthStart, $monthEnd);
    }
```

`Term::attestations()` and its `HasManyThrough` import (if unused elsewhere): remove.

`scripts/build-kh3-template.py` — in `set_cell`, accept a keyword `strip_underline=False`; when true, remove `<w:u\b[^>]*/>` from the run's rPr before writing. Call it with `strip_underline=True` for the `week_note` cell (index 8 in `names`). Rebuild: `python3 scripts/build-kh3-template.py "<official blank docx path from task-5-brief of milestone 3>" resources/forms/kh3-template.docx`, then run the milestone 3 document tests.

Docs: `CLAUDE.md` status header (milestone 4 implemented on branch `milestone-4-term-close-on-file`: term close rules, on-file documents with admin renewal requests, attestation fixes; test count from the final run); `PROGRESS.md` status, "Next" (deploy milestone 4 with `./deploy/deploy.sh`, then the remaining post-deploy items), a log line dated 2026-09-30.

- [ ] **Step 4: Run the tests**

Run: `php artisan test --compact` → PASS (one skip without `soffice`). Lang parity and tashkeel check as in earlier milestones:

```bash
php -r '$a=include "lang/ar/app.php"; $e=include "lang/en/app.php"; $f=function($x,$p="") use (&$f){$o=[];foreach($x as $k=>$v){$o=array_merge($o,is_array($v)?$f($v,"$p$k."):["$p$k"]);}return $o;}; $da=array_diff($f($a),$f($e)); $de=array_diff($f($e),$f($a)); echo count($f($a))," ar / ",count($f($e))," en; missing in en: ",implode(",",$da),"; missing in ar: ",implode(",",$de),"\n";'
grep -nP '[\x{064B}-\x{0652}]' lang/ar/app.php resources/views -r || echo "no tashkeel"
```

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint app/Services/Attestations app/Models/Term.php tests/Feature/Admin/AttestationsTest.php tests/Unit/Attestations
git add app resources scripts lang tests CLAUDE.md PROGRESS.md
git commit -m "fix(attestations): locked saves, nested placeholders, converter cwd and leftovers, last-day edge, no note underline; docs"
```

---

## Self-review notes (done while writing)

- Spec coverage: §3 → Task 1; §4.1–4.2 → Task 2; §4.3 (upload over on-file, unchanged policy) → Task 3's last test; §4.4 → Task 3; §4.5 → Task 4; §5 (policies, audits) → Tasks 1, 3; §6 → Task 5; §7 tests distributed as named.
- Type consistency: the row shape with `source`/`renewal` is used identically in Tasks 2, 3, 4 and the mail view; `requestFreshCopy(Application, ChecklistItem, User, string)` in Tasks 3 and 4; `UNFINISHED_STATUSES` in Tasks 1, 3, 4.
- Review Focus pins: #1, #2 → Task 2 tests; #3 → Task 1 test; #4 → Task 3 last test; #5 → Task 5 first test.
- Deviation ruled here: `requestFreshCopy` moves `submitted` straight to `incomplete` (setting `reviewed_at` when null) instead of passing through `under_review`; the end state is identical.

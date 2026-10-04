# Milestone 7a — Degrees, Checklist Round 2, Timetable Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One degree row per level held with per-degree certificate, transcript and equivalency items; CV required; employer approval and the department schedule retired; the instructor's Banner timetable required per term after assignment; the college's undertaking form downloadable; the (خ-3) student total summed per course.

**Architecture:** New `instructor_degrees` table replaces the three highest-degree profile columns (data migrated, columns dropped; `highest_degree` kept and derived). Checklist items gain `official_line` (replacing `official`) and new conditions evaluated with the application in hand (`appliesTo(Instructor, ?Application)`); retired items (`condition = never`) vanish from plans. Existing `degree`/`equivalency` documents, renewals and exemptions are re-pointed to the highest level's items by a data migration. The printed Check List iterates 12 fixed lines. The attestation stores a per-course `student_total` set by the generator.

**Tech Stack:** Laravel 12, PHP 8.4, MySQL / SQLite (tests), PHPUnit, Blade + Bootstrap 5.3 RTL, PhpWord.

**Spec:** `docs/superpowers/specs/2026-10-04-milestone-7a-checklist-degrees-design.md`

## Global Constraints

- Formal, undiacritized Arabic (no tashkeel); every new `ar` key has an `en` twin at the same path; no hard-coded UI strings (item labels come from the DB; the printed Check List's 12 official labels are a fixed table in code, in Arabic, as today).
- Sensitive fields never in logs/audit details/flashes; audit details for profile edits carry flat field names only (`degree_<level>_title` etc.).
- No new application status. Retired items (`condition = never`) are never deleted and never shown.
- Migrations must work on MySQL 8 and SQLite in both directions; data migrations run in a transaction and never delete documents or files.
- `php artisan test --compact` green after every task; Pint only on touched PHP files, never on `lang/`.
- Never read, copy or reference `../part-time/`.
- Commit after each task with the given message, ending with `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.
- Sweep iCloud duplicates before tests: `find . -path ./vendor -prune -o -name '* 2*' -print` must print nothing.

## Review Focus

1. A profile saved with a master's degree must produce two degree rows and `highest_degree = master`; unchecking the master box later must delete that row and set `highest_degree = bachelor` — Task 1 tests.
2. A doctorate holder with a foreign master's must get `equivalency_master` required and `equivalency_phd` not, when the doctorate is from Kuwait — Task 2 plan test.
3. After the document migration, an instructor whose highest degree was master must find the old `degree` upload under `degree_master`, still accepted — Task 2 migration test.
4. The timetable item must not appear before assignment and must block stage-2 readiness after it — Task 2 tests.
5. The student total for three weeks of the same two sections must equal the two sections' seats once — Task 4 test.

---

### Task 1: Degrees per instructor

**Files:**
- Create: `database/migrations/2026_10_05_100000_create_instructor_degrees_table.php`, `database/migrations/2026_10_05_100001_drop_single_degree_columns_from_instructors_table.php`, `app/Models/InstructorDegree.php`, `database/factories/InstructorDegreeFactory.php`, `resources/views/instructor/_degree_fields.blade.php`
- Modify: `app/Models/Instructor.php`, `app/Http/Requests/ProfileRequest.php`, `app/Http/Controllers/Instructor/ProfileController.php`, `app/Http/Controllers/Admin/ProfileController.php`, `app/Support/ProfileDiff.php`, `resources/views/instructor/_profile_fields.blade.php`, `resources/views/admin/applications/show.blade.php` (profile card), `resources/views/admin/instructors/show.blade.php`, `app/Services/ChecklistDocument.php` (header line), `app/Services/AcademicBundle.php` (summary lines), `app/Services/RenewalListDocument.php` (degree cell), `database/factories/InstructorFactory.php`, `tests/Feature/Instructor/ProfileTest.php` (payload), `tests/Feature/Instructor/ApplicationTest.php` (foreign-degree setup), `lang/*`
- Test: `tests/Feature/DegreesTest.php`

**Interfaces:**
- Produces: `InstructorDegree` (`LEVELS = ['bachelor','master','phd']`, fields `level,title,country,obtained_on`, `isForeign()`); `Instructor::degrees()`, `degree(string $level)`, `holds(string $level)`, `isForeignDegree(string $level = null)` (no argument = the highest degree, keeping old callers working), `highestDegreeRow(): ?InstructorDegree`, `syncHighestDegree(): void`; `InstructorFactory::withDegrees(array $levels = ['bachelor','master'])`, `foreignDegree(string $level = null)` (sets `country = GB` on that level, default highest), `bachelor()` (one bachelor row); `ProfileRequest::degreeRows(): array<string, array{title,country,obtained_on}>` and `profileAttributes()` without degree fields; `ProfileDiff::changedFields(Instructor, array $validated, array $degrees)` returns flat keys.

- [ ] **Step 1: Failing tests** — `tests/Feature/DegreesTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Instructor;
use App\Models\User;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Instructor\ProfileTest;
use Tests\TestCase;

class DegreesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChecklistItemSeeder::class);
        $this->user = User::factory()->instructor()->create();
    }

    public function test_profile_saves_one_two_and_three_degrees_and_derives_highest(): void
    {
        $this->actingAs($this->user)->put(route('instructor.profile.update'), ProfileTest::payload())->assertRedirect(route('instructor.home'))->assertSessionHasNoErrors();
        $i = $this->user->instructor()->first();
        $this->assertSame(['bachelor', 'master'], $i->degrees->pluck('level')->all());
        $this->assertSame('master', $i->highest_degree);
        $this->assertSame('ماجستير هندسة كهربائية', $i->degree('master')->title);

        $this->actingAs($this->user)->put(route('instructor.profile.update'), ProfileTest::payload(['degrees' => [
            'bachelor' => ['title' => 'بكالوريوس هندسة كهربائية', 'country' => 'KW', 'obtained_on' => '2012-06-01'],
            'master' => ['held' => '1', 'title' => 'ماجستير هندسة كهربائية', 'country' => 'GB', 'obtained_on' => '2018-06-01'],
            'phd' => ['held' => '1', 'title' => 'دكتوراه هندسة كهربائية', 'country' => 'US', 'obtained_on' => '2024-06-01'],
        ]]))->assertSessionHasNoErrors();
        $i = $i->fresh();
        $this->assertSame(['bachelor', 'master', 'phd'], $i->degrees->pluck('level')->all());
        $this->assertSame('phd', $i->highest_degree);
        $this->assertTrue($i->isForeignDegree('master'));
        $this->assertTrue($i->isForeignDegree());   // highest = phd, US

        $this->actingAs($this->user)->put(route('instructor.profile.update'), ProfileTest::payload(['degrees' => [
            'bachelor' => ['title' => 'بكالوريوس هندسة كهربائية', 'country' => 'KW', 'obtained_on' => '2012-06-01'],
        ], 'experience_years' => '12']))->assertSessionHasNoErrors();
        $i = $i->fresh();
        $this->assertSame(['bachelor'], $i->degrees->pluck('level')->all());
        $this->assertSame('bachelor', $i->highest_degree);
    }

    public function test_bachelor_required_and_checked_levels_need_all_fields(): void
    {
        $this->actingAs($this->user)->put(route('instructor.profile.update'), ProfileTest::payload(['degrees' => []]))->assertSessionHasErrors('degrees.bachelor.title');
        $this->actingAs($this->user)->put(route('instructor.profile.update'), ProfileTest::payload(['degrees' => [
            'bachelor' => ['title' => 'بكالوريوس', 'country' => 'KW', 'obtained_on' => '2012-06-01'],
            'master' => ['held' => '1', 'title' => '', 'country' => 'KW', 'obtained_on' => ''],
        ]]))->assertSessionHasErrors(['degrees.master.title', 'degrees.master.obtained_on']);
        $this->actingAs($this->user)->put(route('instructor.profile.update'), ProfileTest::payload(['degrees' => [
            'bachelor' => ['title' => 'بكالوريوس', 'country' => 'KW', 'obtained_on' => '2012-06-01'],
        ]]))->assertSessionHasErrors('experience_years');   // bachelor-only needs experience
    }

    public function test_audit_names_flat_degree_keys_only(): void
    {
        $this->actingAs($this->user)->put(route('instructor.profile.update'), ProfileTest::payload());
        $this->actingAs($this->user)->put(route('instructor.profile.update'), ProfileTest::payload(['degrees' => [
            'bachelor' => ['title' => 'بكالوريوس هندسة كهربائية', 'country' => 'KW', 'obtained_on' => '2012-06-01'],
            'master' => ['held' => '1', 'title' => 'ماجستير هندسة كهربائية', 'country' => 'GB', 'obtained_on' => '2018-06-01'],
        ]]));
        $this->assertDatabaseHas('audit_log', ['action' => 'edit_profile', 'details' => 'degree_master_country']);
        $this->assertDatabaseMissing('audit_log', ['details' => 'GB']);
    }

    public function test_migration_copies_old_fields_into_the_highest_degree_row(): void
    {
        // The data migration ran on an empty table in RefreshDatabase; exercise the copy logic directly.
        $i = Instructor::factory()->for(User::factory()->instructor())->create();
        $i->degrees()->delete();
        \App\Models\InstructorDegree::copyFromLegacy($i->id, 'master', 'ماجستير قديم', 'EG', '2016-01-01');
        $this->assertSame('ماجستير قديم', $i->fresh()->degree('master')->title);
        $this->assertSame('master', $i->fresh()->highest_degree);
    }

    public function test_pages_list_every_degree(): void
    {
        $i = Instructor::factory()->withDegrees(['bachelor', 'master', 'phd'])->for($this->user)->create();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('admin.instructors.show', $i))->assertOk()->assertSee(__('app.profile.degrees.phd'))->assertSee(__('app.profile.degrees.bachelor'));
        $this->actingAs($this->user)->get(route('instructor.profile.edit'))->assertOk()->assertSee('degrees[phd][held]', false);
    }
}
```

- [ ] **Step 2: Migrations**

`2026_10_05_100000_create_instructor_degrees_table.php`: table `instructor_degrees` (`id`, `instructor_id` FK cascade, `level` string 10, `title` string 150, `country` string 2, `obtained_on` date, timestamps, unique `(instructor_id, level)`). In the same `up()`, after creating the table, copy legacy data with a query (no models): for each instructor row with a non-null `degree_title`, insert one degree row with `level = highest_degree`, `title = degree_title`, `country = degree_country`, `obtained_on = degree_obtained_on`. Use `DB::table('instructors')->select(...)->orderBy('id')->chunk(200, ...)`.

`2026_10_05_100001_drop_single_degree_columns_from_instructors_table.php`: `dropColumn(['degree_title', 'degree_country', 'degree_obtained_on'])` (separate `Schema::table` calls per column if SQLite complains); `down()` re-adds them nullable and copies back from the highest row.

- [ ] **Step 3: Models**

`InstructorDegree`: `LEVELS`, `$fillable`, `casts` (`obtained_on` date), `instructor()`, `isForeign(): bool` (`strtoupper($this->country) !== 'KW'`), `static copyFromLegacy(int $instructorId, string $level, string $title, string $country, string $date): self` (used by the migration and the test; also sets `instructors.highest_degree`).

`Instructor`: remove the three columns from `$fillable`/casts; add `degrees(): HasMany` ordered by `FIELD(level)` equivalent (order in PHP: sort by `array_search($level, LEVELS)`); `degree($level)`, `holds($level)`, `highestDegreeRow()`, `isForeignDegree(?string $level = null)` (null → highest row; false when no row), `syncHighestDegree()` (sets `highest_degree` to the highest level present and saves; `isBachelorOnly()` unchanged).

Factory: `definition()` no longer sets the three columns; `configure()`/`afterCreating` creates a bachelor + master row by default (titles like today's `degree_title`); `withDegrees(array $levels)`, `foreignDegree(?string $level = null)` (after creating: set `country = 'GB'` on the given/highest row), `bachelor(int $years = 12)` → `highest_degree = bachelor`, only a bachelor row, `experience_years`.

- [ ] **Step 4: Request, controllers, diff**

`ProfileRequest::rules()`: remove `highest_degree`, `degree_*`; add:

```php
            'degrees' => ['required', 'array'],
            'degrees.bachelor.title' => ['required', 'string', 'max:150'],
            'degrees.bachelor.country' => ['required', Rule::in(array_keys(__('app.countries')))],
            'degrees.bachelor.obtained_on' => ['required', 'date', 'before_or_equal:today'],
            'degrees.master.held' => ['nullable', 'boolean'],
            'degrees.master.title' => ['nullable', 'string', 'max:150', Rule::requiredIf(fn () => $this->boolean('degrees.master.held'))],
            'degrees.master.country' => ['nullable', Rule::in(array_keys(__('app.countries'))), Rule::requiredIf(fn () => $this->boolean('degrees.master.held'))],
            'degrees.master.obtained_on' => ['nullable', 'date', 'before_or_equal:today', Rule::requiredIf(fn () => $this->boolean('degrees.master.held'))],
            // same three for phd
            'experience_years' => ['nullable', 'integer', 'min:0', 'max:60', Rule::requiredIf(fn () => ! $this->boolean('degrees.master.held') && ! $this->boolean('degrees.phd.held'))],
```

`prepareForValidation()` upper-cases each `degrees.*.country`. `attributes()` maps `degrees.bachelor.title` → `__('app.profile.degree_title_of', ['level' => __('app.profile.degrees.bachelor')])` etc. `degreeRows()` returns `['bachelor' => [...], 'master' => [...] (only when held), 'phd' => ...]`. `profileAttributes()` drops `degrees` from the returned array and adds `'highest_degree' => <max level present>`.

Both controllers: after `updateOrCreate`/`fill()->save()`, sync the rows: `$instructor->degrees()->whereNotIn('level', array_keys($rows))->delete(); foreach ($rows as $level => $row) $instructor->degrees()->updateOrCreate(['level' => $level], $row);` then `syncHighestDegree()`. `experience_years` null unless bachelor-only.

`ProfileDiff::changedFields(Instructor $instructor, array $validated, array $degrees = [])`: existing comparison for scalar fields (remove `degree_obtained_on` from `DATE_FIELDS`), then for each level in `LEVELS`: compare the stored row (or none) with the submitted row (or none) field by field and emit `degree_<level>_title|country|obtained_on` for differences (a removed or added row emits all three). Sorted.

- [ ] **Step 5: Views and lang**

`resources/views/instructor/_degree_fields.blade.php`: a card "المؤهلات العلمية" with three blocks; bachelor block (title, country select, date); master and phd blocks each start with a checkbox `degrees[<level>][held]` (checked when `old()` or a stored row exists) that toggles the block's fields (`d-none`) with a tiny script pushed to `scripts`. Field names `degrees[<level>][title|country|obtained_on]`, values from `old('degrees.<level>.*', $instructor->degree(<level>)?->…)`. Include it from `_profile_fields.blade.php` in place of the old degree row; remove the `highest_degree` select; keep the experience field, toggled now by "no master and no phd checked".

`admin/applications/show.blade.php` profile card: replace the three degree lines with one line per degree: `__('app.profile.degrees.'.$d->level)` — title — country name — date. `admin/instructors/show.blade.php`: same list in the header card. `ChecklistDocument` header: highest row's title/date (fallback `—`). `AcademicBundle` summary: one line per degree; `RenewalListDocument` degree cell: highest row label + title.

Lang (ar): `profile.degrees_title => 'المؤهلات العلمية'`, `profile.holds_degree => 'أحمل هذا المؤهل'`, `profile.degree_title_of => 'المؤهل (:level)'`, `profile.degree_country_of => 'بلد إصدار :level'`, `profile.degree_obtained_on_of => 'تاريخ الحصول على :level'`; keep `degrees.*`, `degree_title`, `degree_country`, `degree_obtained_on` (reused as column labels). En twins.

- [ ] **Step 6: Existing tests** — `ProfileTest::payload()` replaces the four degree keys with `'degrees' => ['bachelor' => [...KW 2012...], 'master' => ['held' => '1', 'title' => 'ماجستير هندسة كهربائية', 'country' => 'KW', 'obtained_on' => '2018-06-01']]`; `ApplicationTest` foreign-degree setup uses `$instructor->degree('master')->update(['country' => 'GB'])` (and back). Any test using `Instructor::factory()->foreignDegree()` keeps working. Run the suite; fix only setups.

- [ ] **Step 7: Suite, Pint, commit** — `feat(profile): one degree row per level held; highest degree derived`

---

### Task 2: Items per degree, conditions, document migration, Check List lines

**Files:**
- Create: `database/migrations/2026_10_05_100002_add_official_line_to_checklist_items_table.php`, `database/migrations/2026_10_05_100003_repoint_degree_documents.php`
- Modify: `database/seeders/ChecklistItemSeeder.php`, `app/Models/ChecklistItem.php`, `app/Services/ChecklistResolver.php`, `app/Services/ApplicationWorkflow.php` (`plan`, `checklist` pass the application), `app/Services/ChecklistDocument.php` (12-line printer), `app/Services/AcademicBundle.php` (`ITEMS`), `app/Policies/DocumentPolicy.php` and `ApplicationPolicy.php` (resolver calls pass the application), `lang/*`, existing tests that name `degree`/`equivalency`/`schedule`/`employer_approval`
- Test: `tests/Feature/ChecklistRound2Test.php`

**Interfaces:**
- Produces: `ChecklistItem::appliesTo(Instructor, ?Application = null)`, `isRetired()`; `ChecklistResolver::for(Instructor, ?Application = null)`; `ChecklistItem::OFFICIAL_LINES` (1..12 → label, note) used by `ChecklistDocument`; `ChecklistItem::CODES` updated; `AcademicBundle::ITEMS = ['civil_id','cv','degree_bachelor','degree_master','degree_phd','transcript_bachelor','transcript_master','transcript_phd','equivalency_bachelor','equivalency_master','equivalency_phd']`.

- [ ] **Step 1: Failing tests** — `tests/Feature/ChecklistRound2Test.php` (seed in `setUp`):
  - `test_seeder_items_stages_conditions_and_lines`: 21 rows; `cv` stage 1 always no line; `degree_master` `holds_master` line 5; `equivalency_phd` `foreign_phd` line 6; `timetable` stage 2 renews `assigned` line 1; `employer_approval` and `schedule` `never`; `official` column gone.
  - `test_plan_for_bachelor_only_local`: required = civil_id, cv, degree_bachelor, transcript_bachelor, experience, salary_cert, iban, undertaking (order by stage then sort_order); `employer_approval`/`schedule` absent from every collection; `assignment_letter`/`attestation` in department.
  - `test_plan_for_phd_with_foreign_master`: `equivalency_master` required, `equivalency_phd` and `equivalency_bachelor` not; all three certificates and transcripts required.
  - `test_timetable_appears_only_after_assignment_and_gates_stage_two`: approved application, stage-2 docs accepted, salary set → `stageTwoComplete()` true; add an assignment (Section + Assignment factories, see `AttestationsTest`) → `checklist()` has `timetable` row `missing`, `stageTwoComplete()` false, `stageTwoMissing()` contains the label; accept a timetable upload → true. Instructor upload allowed after approval (stage 2).
  - `test_continuation_term_papers_include_timetable_once_assigned`: continuation with assignment → `requiredMissing()` contains the timetable label.
  - `test_document_migration_repoints_degree_and_equivalency_to_the_highest_level`: create items with the OLD codes `degree`/`equivalency` via `ChecklistItem::create`, an instructor with highest master, documents + an exemption + a renewal on them, run `(new (require database/migrations/..._repoint_degree_documents.php))->up()` (or call a public static `RepointDegreeDocuments::run()` extracted into `app/Support/`), assert the rows now point at `degree_master` / `equivalency_master`, old items deleted, counts unchanged.
  - `test_printed_check_list_prints_twelve_lines_with_marks`: foreign master holder with accepted `degree_bachelor`, `degree_master`, `equivalency_master`, pending `civil_id`: line 5 ☑, line 6 ☑, line 4 ☐, line 11 "لا ينطبق", line 1 "لا ينطبق" (not assigned), lines 2/3 ☐; CV and transcripts absent; exactly 12 item lines.

- [ ] **Step 2: Migrations**

`..._add_official_line_...`: add `official_line` unsigned tinyint nullable after `exemptable`; copy: `official = true` rows get their line from a code map (`schedule` 1, `assignment_letter` 2, `attestation` 3, `civil_id` 4, `degree` 5, `equivalency` 6, `social_insurance` 7, `experience` 8, `salary_cert` 9, `iban` 10, `employer_approval` 11, `undertaking` 12); drop `official`. `down()` reverses.

`..._repoint_degree_documents.php`: delegates to `App\Support\RepointDegreeDocuments::run()`:

```php
public static function run(): void
{
    DB::transaction(function () {
        $old = DB::table('checklist_items')->whereIn('code', ['degree', 'equivalency'])->pluck('id', 'code');
        if ($old->isEmpty()) {
            return;
        }
        // Ensure the per-level items exist (seeder may not have run yet on a fresh deploy): create minimal rows.
        foreach (['bachelor', 'master', 'phd'] as $level) {
            foreach (['degree', 'equivalency'] as $kind) {
                DB::table('checklist_items')->updateOrInsert(['code' => "{$kind}_{$level}"], ['label_ar' => "{$kind}_{$level}", 'sort_order' => 99, 'provided_by' => 'applicant', 'condition' => 'never', 'stage' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $new = DB::table('checklist_items')->where('code', 'like', 'degree_%')->orWhere('code', 'like', 'equivalency_%')->pluck('id', 'code');
        foreach (['documents', 'checklist_exemptions', 'checklist_renewals'] as $table) {
            $rows = DB::table($table)->join('applications', "{$table}.application_id", '=', 'applications.id')
                ->join('instructors', 'applications.instructor_id', '=', 'instructors.id')
                ->whereIn("{$table}.checklist_item_id", $old->values())
                ->select("{$table}.id", "{$table}.checklist_item_id", 'instructors.highest_degree')->get();
            foreach ($rows as $r) {
                $kind = $r->checklist_item_id === $old['degree'] ? 'degree' : 'equivalency';
                DB::table($table)->where('id', $r->id)->update(['checklist_item_id' => $new["{$kind}_{$r->highest_degree}"]]);
            }
        }
        DB::table('checklist_items')->whereIn('id', $old->values())->delete();
    });
}
```

(The seeder then fixes labels/sort orders of the minimal rows.) Note the unique key `(application, item, version, part)`: re-pointing cannot collide because no application has both old and new items.

- [ ] **Step 3: Seeder and model** — seeder rows exactly as spec §3.2 (column order: code, label, note, provided_by, condition, renews, stage, exemptable, official_line); `ChecklistItem`: `$fillable` + casts updated (`official_line` int), `CODES`, `PROFILE_FIELDS` per spec §4, `appliesTo(Instructor $i, ?Application $a = null)` with the new conditions, `isRetired(): bool` (`condition === 'never'`), `OFFICIAL_LINES` constant (12 entries: label, note from the official form as in `docs/forms/checklist.md`).

- [ ] **Step 4: Resolver, workflow, policies** — `ChecklistResolver::for(Instructor, ?Application = null)`: filter out retired items first; pass `$application` to `appliesTo`. `ApplicationWorkflow::plan()` and `checklist()` call `for($application->instructor, $application)`. `DocumentPolicy::create` and `ApplicationPolicy::requestExemption` pass the application too. `AcademicBundle::ITEMS` updated; its summary line for transcripts iterates the three transcript codes; equivalency per level.

- [ ] **Step 5: Check List printer** — `ChecklistDocument`: iterate `ChecklistItem::OFFICIAL_LINES`; for each line, applicable applicant items = rows of `$checklist` whose `item->official_line === $line` (department lines 2, 3 → ☐); mark per spec §5; label = fixed official label + note.

- [ ] **Step 6: Existing tests** — replace `'degree'` with `'degree_master'` (or `degree_bachelor` for bachelor fixtures), `'equivalency'` with the level's item, drop `employer_approval` from required lists, `'schedule'` from department assertions; `OnFileTest`/`FreshCopyTest` etc. setups only. `ChecklistResolverTest` and `StageSeederTest` rewritten to the new seed (counts: 21 rows; department = assignment_letter, attestation). List every change in the report.

- [ ] **Step 7: Lang** — `app.documents.form_download => 'تحميل نموذج الكلية'` is Task 3; here only labels come from the seeder. No new keys unless a page needs one.

- [ ] **Step 8: Suite, Pint, commit** — `feat(checklist): items per degree, CV, timetable after assignment, retired items, official lines; re-point old degree uploads`

---

### Task 3: Undertaking form download

**Files:**
- Create: `resources/forms/undertaking-cts.pdf` (copy from `/Users/malshar/.claude/jobs/4a0bd2b9/tmp/undertaking-cts.pdf`), `app/Http/Controllers/FormController.php`
- Modify: `routes/web.php`, `resources/views/instructor/_checklist_table.blade.php`, `resources/views/admin/applications/_checklist_table.blade.php`, `lang/*`
- Test: `tests/Feature/FormDownloadTest.php`

- [ ] Tests: guest → redirect to login; instructor and admin → 200, `content-type: application/pdf`, `content-disposition: inline`; the undertaking row on both pages shows `route('forms.undertaking')`.
- [ ] Route `Route::get('forms/undertaking', [FormController::class, 'undertaking'])->middleware('auth')->name('forms.undertaking');` → `response()->file(resource_path('forms/undertaking-cts.pdf'), ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="undertaking.pdf"'])`.
- [ ] Rows: when `$row['item']->code === 'undertaking'` show `<a href="{{ route('forms.undertaking') }}" target="_blank">{{ __('app.documents.form_download') }}</a>` under the note (both tables). Lang `documents.form_download` ar `تحميل نموذج الكلية` / en `Download the college form`.
- [ ] Commit — `feat(forms): host the college undertaking form for download`

---

### Task 4: Student total per course

**Files:**
- Create: `database/migrations/2026_10_05_100004_add_student_total_to_attestations_table.php`
- Modify: `app/Models/Attestation.php`, `app/Services/Attestations/AttestationGenerator.php`, `app/Services/Attestations/AttestationService.php` (`update`), `app/Http/Requests/AttestationUpdateRequest.php`, `resources/views/admin/attestations/show.blade.php` (editable total field), `lang/*`
- Test: `tests/Feature/Admin/AttestationsTest.php` (new test) and `tests/Unit/Attestations/AttestationGeneratorTest.php` if present

- [ ] Test: two sections (seats 20 and 15) with meetings on three weekdays over a 4-week month → every week's `student_count` = 35 (unchanged), `totals()['student_count']` = 35 (not 140); the show page and the docx `sum_students` print 35; editing the total through the update form to 30 changes `totals()`; `generated_student_total` keeps 35.
- [ ] Migration: `student_total` and `generated_student_total` unsigned int default 0 on `attestations`.
- [ ] Generator: after building rows, `student_total = sum of seats_registered over the distinct sections that appear in any week` (collect `$weekSections` ids across weeks); save both columns with the attestation. `Attestation::totals()`: `student_count` = `$this->student_total` (the weekly loop no longer sums it). `AttestationService::update()` accepts `student_total` like the week columns (audited among changed columns). Request: `student_total` nullable integer min 0. Show page: a total input in the totals row. Lang: `attestations.student_total => 'إجمالي الطلبة (مجموع الشعب)'` + en.
- [ ] Commit — `fix(attestations): student total is the sum per course, stored and editable`

---

### Task 5: Docs

- [ ] `CLAUDE.md` status + Next step; `PROGRESS.md` 2026-10-05 line; `deploy/DEPLOY.md`: "Milestone 7a: five migrations including a data migration (degrees copied, old degree uploads re-pointed to the highest level); seeder re-run; no server steps."
- [ ] Commit — `docs: milestone 7a status, deploy note`

## Self-review notes

- Spec coverage: §3.1 → T1; §3.2–3.3, §4, §5 → T2; §6 → T3; §7 → T4; §8 copy across T1–T3; §9 security in T1 (audit keys), T3 (auth); §10 tests per task.
- Type consistency: `appliesTo(Instructor, ?Application)` and `for(Instructor, ?Application)` used by workflow and policies (T2); `Instructor::isForeignDegree(?string)` keeps the old no-argument call sites working (T1) until T2 replaces the `foreign_degree` condition.
- Order: T1 before T2 because T2's conditions read degree rows; T2's data migration uses `instructors.highest_degree`, which T1 keeps.

# Milestone 3 — Monthly (خ-3) Attestation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Generate the official monthly استمارة مزاولة فعلية (نموذج خ-3) for every approved, assigned instructor from the timetable and the term calendar, let the admin adjust and reprint it, and export it as Word, PDF and one combined monthly PDF.

**Architecture:** Same Laravel 12 monolith. A pure `AttestationGenerator` (application + month → week rows) writes stored `attestations`/`attestation_weeks`; an `AttestationService` wraps generation, edits, locking and audit; an `AttestationDocument` fills a Word template made from the official blank form (PhpWord `TemplateProcessor`, page block cloned per instructor, week row cloned per week); a `PdfConverter` runs LibreOffice headless. Admin-only screens under `/admin/attestations`; dashboard alerts. No instructor screens, no scheduler, no email.

**Tech Stack:** PHP ≥ 8.2, Laravel 12, MySQL/SQLite, PHPUnit, Bootstrap 5 RTL (CDN), `phpoffice/phpword` ^1.3 (already installed; `TemplateProcessor`), `symfony/process` (already installed via Laravel), LibreOffice 7.3 on the server (`soffice`), Python 3 for the one-time template build script.

**Spec:** `docs/superpowers/specs/2026-09-30-milestone-3-attestation-design.md` (binding), which extends `docs/superpowers/specs/2026-09-29-milestone-2-assignment-design.md` and `docs/superpowers/specs/2026-09-28-parttime-system-design.md`.

## Global Constraints

- Laravel 12; SQLite `:memory:` in tests; PHPUnit; TDD per task; `vendor/bin/pint` on touched files before each commit.
- **Arabic-first, RTL.** Every new UI string in `lang/ar/app.php` (block `attestations`) with an English twin in `lang/en/app.php`; formal undiacritized Arabic (no tashkeel); no hard-coded UI strings in views/controllers. The fixed Arabic of the official form lives in the template, not in code, except the generator's note phrases and the Arabic month/day names in `App\Support\ArabicDate`.
- Admin-only: all routes in the existing `admin` group (`auth`, `role:admin`), plus `$this->authorize(...)` against `AttestationPolicy`. Instructors get 403 on every attestation route.
- Every generate, save, regenerate, unlock and export writes `AuditLog::record($userId, $action, $subject, null, $details)`; audit details never contain sensitive values (only column names, formats, counts).
- Sensitive fields (`civil_id`, `iban`, `basic_salary`, `total_salary`) are decrypted only inside `AttestationDocument`; screens keep the milestone 1 masking; nothing sensitive goes to session, flash, logs or file names.
- Load is `weekly_minutes` and meeting `minutes`; the form prints hours through `Section::hoursForForm()` (whole number when whole, else up to two decimals trimmed).
- Statuses: `generated` → `exported` on any download or combined export; `unlock` sets `generated`. Save and regenerate need an open term and `generated`; unlock needs an open term and `exported`; downloads always allowed.
- Term months: calendar months from `teaching_starts_on` to `teaching_ends_on`, indexed from 1; the generator refuses months outside that range.
- Generated files live under `storage/app/private/generated/tmp/` with random names and are deleted after send (also on failure).
- Commit after every task; messages end with the `Co-Authored-By:` trailer the environment specifies.
- Never read, copy or reference `../part-time/`. The blank official form (no personal data) is the only external file copied in, as the template source.

## Review Focus

1. **A holiday that falls on a Friday or Saturday.** Expected: no effect on any week (never a negative or missing day). Pinned in Task 2.
2. **Two assigned sections of the same course with different registered seats.** Expected: one course line, seats summed per section, never doubled by course. Pinned in Task 2.
3. **The admin types hours with a comma or more than two decimals ("2,5", "1.333").** Expected: validation rejects the comma with a message; "1.333" is stored as 80 minutes and displayed as "1.33". Pinned in Task 4.
4. **Two admins export the same attestation at the same moment, or a PDF conversion fails midway.** Expected: a single audit row per download, no leftover files in `generated/tmp`, and the Word download still works after a PDF failure. Pinned in Task 6.
5. **An instructor whose assignments are removed after a month was generated.** Expected: the existing attestation stays as printed; the month page still lists it (it exists) but the instructor drops out of "listed" once they have no assignments, and "generate missing" never re-creates it. Pinned in Task 3.

## File Structure

```
app/
  Models/Attestation.php, AttestationWeek.php                       (new)
  Models/Term.php                                                   (modified: months(), monthIndex(), attestations())
  Models/Application.php                                            (modified: attestations())
  Models/Section.php                                                (modified: hoursForForm())
  Support/ArabicDate.php                                            (month names, ordinals, day names, long date)
  Services/Attestations/AttestationGenerator.php                    (pure: rows for a month; writes the rows in a transaction)
  Services/Attestations/AttestationService.php                      (listed(), generateMissing(), update(), regenerate(), unlock(), markExported())
  Services/Attestations/AttestationDocument.php                     (template → docx, single and combined)
  Services/Attestations/Kh3TemplateProcessor.php                    (TemplateProcessor subclass: stripFirstPageBreak())
  Services/Attestations/PdfConverter.php                            (soffice headless)
  Policies/AttestationPolicy.php
  Http/Controllers/Admin/AttestationController.php                  (index, generate, show, update, regenerate, unlock, download, combined)
  Http/Requests/AttestationUpdateRequest.php
  Http/Controllers/Admin/DashboardController.php                    (modified: two alert kinds)
config/services.php                                                 (soffice.path)
database/migrations/2026_09_30_100000_create_attestations_table.php
database/migrations/2026_09_30_100001_create_attestation_weeks_table.php
database/factories/AttestationFactory.php, AttestationWeekFactory.php, TermHolidayFactory.php
resources/forms/kh3-template.docx                                   (built by scripts/build-kh3-template.py from the official blank)
scripts/build-kh3-template.py
resources/views/admin/attestations/index.blade.php, show.blade.php
resources/views/admin/layout.blade.php                              (modified: nav link)
routes/web.php                                                      (modified)
lang/ar/app.php, lang/en/app.php                                    (modified: attestations block)
tests/Unit/Attestations/ArabicDateTest.php, TermMonthsTest.php, AttestationGeneratorTest.php, AttestationDocumentTest.php, PdfConverterTest.php, AttestationPolicyTest.php
tests/Feature/Admin/AttestationsTest.php, AttestationExportTest.php
tests/Fixtures/fake-soffice.sh
deploy/deploy.sh, deploy/DEPLOY.md, CLAUDE.md, PROGRESS.md          (modified)
```

---

### Task 1: Schema, models, factories, `Term::months()`, `ArabicDate`, `hoursForForm()`

**Files:**
- Create: `database/migrations/2026_09_30_100000_create_attestations_table.php`, `database/migrations/2026_09_30_100001_create_attestation_weeks_table.php`
- Create: `app/Models/Attestation.php`, `app/Models/AttestationWeek.php`, `app/Support/ArabicDate.php`
- Create: `database/factories/AttestationFactory.php`, `database/factories/AttestationWeekFactory.php`, `database/factories/TermHolidayFactory.php`
- Modify: `app/Models/Term.php` (add `months()`, `monthIndex()`, `attestations()`), `app/Models/Application.php` (add `attestations()`), `app/Models/Section.php` (add `hoursForForm()`), `app/Models/TermHoliday.php` (add `HasFactory`)
- Test: `tests/Unit/Attestations/ArabicDateTest.php`, `tests/Unit/Attestations/TermMonthsTest.php`, `tests/Unit/SectionModelTest.php` (add one test)

**Interfaces:**
- Consumes: `Term` (`teaching_starts_on`, `teaching_ends_on` date casts, `holidays()`), `SectionMeeting::DAY_NAMES_AR`, `Section::hoursFromMinutes()`.
- Produces:
  - `Attestation` model: `STATUS_GENERATED = 'generated'`, `STATUS_EXPORTED = 'exported'`; fillable `application_id, year, month, status, generated_at, generated_by, exported_at, admin_note`; relations `application()`, `generatedBy()`, `weeks()` (ordered by `week_number`); `isExported(): bool`; `monthIndex(): ?int`; `monthTitle(): string`; `totals(): array{student_count:int, theory_minutes:int, practical_minutes:int, field_minutes:int, total_minutes:int}`.
  - `AttestationWeek` model: fillable `attestation_id, week_number, date_from, date_to, working_days, courses_text, student_count, theory_minutes, practical_minutes, field_minutes, note_ar` and the six `generated_*` twins; casts `working_days` array, `date_from`/`date_to` date; `EDITABLE = ['courses_text','student_count','theory_minutes','practical_minutes','field_minutes','note_ar']`; `totalMinutes(): int`; `generatedTotalMinutes(): int`; `datesLabel(): string` ("7-11", or "7" when a single day); `isEdited(): bool`.
  - `Term::months(): array` of `['year' => int, 'month' => int, 'index' => int, 'label' => string]`; `Term::monthIndex(int $year, int $month): ?int`; `Term::attestations()` (HasManyThrough via applications).
  - `ArabicDate::MONTHS` (1..12), `ArabicDate::ORDINALS` (1..10), `ArabicDate::DAYS` (0=Sunday..6=Saturday), `monthTitle(int $index, int $month): string` ("الشهر الأول/ يونيو"), `long(CarbonInterface $d): string` ("16 يونيو 2026"), `dayName(CarbonInterface $d): string`.
  - `Section::hoursForForm(int $minutes): string`.

- [ ] **Step 1: Write the failing unit tests**

`tests/Unit/Attestations/ArabicDateTest.php`:

```php
<?php

namespace Tests\Unit\Attestations;

use App\Support\ArabicDate;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class ArabicDateTest extends TestCase
{
    public function test_month_title_uses_ordinal_and_arabic_month(): void
    {
        $this->assertSame('الشهر الأول/ يونيو', ArabicDate::monthTitle(1, 6));
        $this->assertSame('الشهر الثاني/ يوليو', ArabicDate::monthTitle(2, 7));
        $this->assertSame('الشهر الخامس/ يناير', ArabicDate::monthTitle(5, 1));
    }

    public function test_long_date_and_day_name(): void
    {
        $d = Carbon::create(2026, 6, 16);
        $this->assertSame('16 يونيو 2026', ArabicDate::long($d));
        $this->assertSame('الثلاثاء', ArabicDate::dayName($d));
        $this->assertSame('الجمعة', ArabicDate::dayName(Carbon::create(2026, 6, 19)));
        $this->assertSame('السبت', ArabicDate::dayName(Carbon::create(2026, 6, 20)));
    }
}
```

`tests/Unit/Attestations/TermMonthsTest.php`:

```php
<?php

namespace Tests\Unit\Attestations;

use App\Models\Term;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TermMonthsTest extends TestCase
{
    use RefreshDatabase;

    public function test_summer_term_has_two_indexed_months(): void
    {
        $term = Term::factory()->create(['type' => 'summer', 'academic_year' => '2025-2026', 'teaching_starts_on' => '2026-06-07', 'teaching_ends_on' => '2026-07-23']);

        $this->assertSame([
            ['year' => 2026, 'month' => 6, 'index' => 1, 'label' => 'الشهر الأول/ يونيو'],
            ['year' => 2026, 'month' => 7, 'index' => 2, 'label' => 'الشهر الثاني/ يوليو'],
        ], $term->months());
        $this->assertSame(2, $term->monthIndex(2026, 7));
        $this->assertNull($term->monthIndex(2026, 8));
    }

    public function test_regular_term_spanning_a_year_end(): void
    {
        $term = Term::factory()->create(['type' => 'second', 'teaching_starts_on' => '2026-12-20', 'teaching_ends_on' => '2027-04-15']);

        $months = $term->months();
        $this->assertCount(5, $months);
        $this->assertSame(['year' => 2026, 'month' => 12, 'index' => 1, 'label' => 'الشهر الأول/ ديسمبر'], $months[0]);
        $this->assertSame(['year' => 2027, 'month' => 4, 'index' => 5, 'label' => 'الشهر الخامس/ أبريل'], $months[4]);
    }
}
```

Add to `tests/Unit/SectionModelTest.php`:

```php
    public function test_hours_for_form_trims_decimals(): void
    {
        $this->assertSame('2', \App\Models\Section::hoursForForm(120));
        $this->assertSame('2.5', \App\Models\Section::hoursForForm(150));
        $this->assertSame('1.25', \App\Models\Section::hoursForForm(75));
        $this->assertSame('1.33', \App\Models\Section::hoursForForm(80));
        $this->assertSame('0', \App\Models\Section::hoursForForm(0));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Unit/Attestations tests/Unit/SectionModelTest.php`
Expected: FAIL — class `App\Support\ArabicDate` not found, `months()` undefined, `hoursForForm()` undefined.

- [ ] **Step 3: Migrations**

`database/migrations/2026_09_30_100000_create_attestations_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attestations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('status', 20)->default('generated');
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('exported_at')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamps();
            $table->unique(['application_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attestations');
    }
};
```

`database/migrations/2026_09_30_100001_create_attestation_weeks_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attestation_weeks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attestation_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('week_number');
            $table->date('date_from');
            $table->date('date_to');
            $table->json('working_days');
            foreach (['courses_text', 'note_ar'] as $col) {
                $table->text($col)->nullable();
                $table->text('generated_'.$col)->nullable();
            }
            foreach (['student_count', 'theory_minutes', 'practical_minutes', 'field_minutes'] as $col) {
                $table->unsignedInteger($col)->default(0);
                $table->unsignedInteger('generated_'.$col)->default(0);
            }
            $table->timestamps();
            $table->unique(['attestation_id', 'week_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attestation_weeks');
    }
};
```

- [ ] **Step 4: `ArabicDate`**

`app/Support/ArabicDate.php`:

```php
<?php

namespace App\Support;

use Carbon\CarbonInterface;

/** Arabic calendar words as printed on PAAET forms (Kuwait month names, Sunday-first day names). */
final class ArabicDate
{
    public const MONTHS = [1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل', 5 => 'مايو', 6 => 'يونيو',
        7 => 'يوليو', 8 => 'أغسطس', 9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر'];

    public const ORDINALS = [1 => 'الأول', 2 => 'الثاني', 3 => 'الثالث', 4 => 'الرابع', 5 => 'الخامس',
        6 => 'السادس', 7 => 'السابع', 8 => 'الثامن', 9 => 'التاسع', 10 => 'العاشر'];

    /** Carbon dayOfWeek: 0 = Sunday … 6 = Saturday. */
    public const DAYS = [0 => 'الأحد', 1 => 'الاثنين', 2 => 'الثلاثاء', 3 => 'الأربعاء', 4 => 'الخميس', 5 => 'الجمعة', 6 => 'السبت'];

    /** "الشهر الأول/ يونيو" — the schedule table's first column heading. */
    public static function monthTitle(int $index, int $month): string
    {
        return 'الشهر '.self::ORDINALS[$index].'/ '.self::MONTHS[$month];
    }

    /** "16 يونيو 2026" */
    public static function long(CarbonInterface $d): string
    {
        return $d->day.' '.self::MONTHS[$d->month].' '.$d->year;
    }

    public static function dayName(CarbonInterface $d): string
    {
        return self::DAYS[$d->dayOfWeek];
    }
}
```

- [ ] **Step 5: Models**

`app/Models/Attestation.php`:

```php
<?php

namespace App\Models;

use App\Support\ArabicDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One (خ-3) form: an application's month, with its week rows (spec §3). */
class Attestation extends Model
{
    use HasFactory;

    public const STATUS_GENERATED = 'generated';

    public const STATUS_EXPORTED = 'exported';

    protected $fillable = ['application_id', 'year', 'month', 'status', 'generated_at', 'generated_by', 'exported_at', 'admin_note'];

    protected function casts(): array
    {
        return ['generated_at' => 'datetime', 'exported_at' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function weeks(): HasMany
    {
        return $this->hasMany(AttestationWeek::class)->orderBy('week_number');
    }

    public function isExported(): bool
    {
        return $this->status === self::STATUS_EXPORTED;
    }

    public function monthIndex(): ?int
    {
        return $this->application->term->monthIndex((int) $this->year, (int) $this->month);
    }

    public function monthTitle(): string
    {
        return ArabicDate::monthTitle($this->monthIndex() ?? 1, (int) $this->month);
    }

    /** @return array{student_count:int, theory_minutes:int, practical_minutes:int, field_minutes:int, total_minutes:int} */
    public function totals(): array
    {
        $t = ['student_count' => 0, 'theory_minutes' => 0, 'practical_minutes' => 0, 'field_minutes' => 0];
        foreach ($this->weeks as $w) {
            foreach (array_keys($t) as $k) {
                $t[$k] += (int) $w->$k;
            }
        }
        $t['total_minutes'] = $t['theory_minutes'] + $t['practical_minutes'] + $t['field_minutes'];

        return $t;
    }
}
```

`app/Models/AttestationWeek.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttestationWeek extends Model
{
    use HasFactory;

    /** Columns the admin may edit; each has a generated_ twin holding the generator's value. */
    public const EDITABLE = ['courses_text', 'student_count', 'theory_minutes', 'practical_minutes', 'field_minutes', 'note_ar'];

    protected $fillable = ['attestation_id', 'week_number', 'date_from', 'date_to', 'working_days',
        'courses_text', 'student_count', 'theory_minutes', 'practical_minutes', 'field_minutes', 'note_ar',
        'generated_courses_text', 'generated_student_count', 'generated_theory_minutes', 'generated_practical_minutes', 'generated_field_minutes', 'generated_note_ar'];

    protected function casts(): array
    {
        return ['working_days' => 'array', 'date_from' => 'date', 'date_to' => 'date'];
    }

    public function attestation(): BelongsTo
    {
        return $this->belongsTo(Attestation::class);
    }

    public function totalMinutes(): int
    {
        return (int) $this->theory_minutes + (int) $this->practical_minutes + (int) $this->field_minutes;
    }

    public function generatedTotalMinutes(): int
    {
        return (int) $this->generated_theory_minutes + (int) $this->generated_practical_minutes + (int) $this->generated_field_minutes;
    }

    /** "7-11" as printed on the form; "7" when the block is a single day. */
    public function datesLabel(): string
    {
        return $this->date_from->day === $this->date_to->day ? (string) $this->date_from->day : $this->date_from->day.'-'.$this->date_to->day;
    }

    public function isEdited(): bool
    {
        foreach (self::EDITABLE as $col) {
            if ((string) $this->$col !== (string) $this->{'generated_'.$col}) {
                return true;
            }
        }

        return false;
    }
}
```

Add to `app/Models/Term.php` (imports `App\Support\ArabicDate`, `Illuminate\Database\Eloquent\Relations\HasManyThrough`):

```php
    public function attestations(): HasManyThrough
    {
        return $this->hasManyThrough(Attestation::class, Application::class);
    }

    /** Calendar months of the teaching window, indexed from 1 (spec §3 "Months of a term"). */
    public function months(): array
    {
        $out = [];
        $cursor = $this->teaching_starts_on->copy()->startOfMonth();
        $end = $this->teaching_ends_on->copy()->startOfMonth();
        for ($i = 1; $cursor->lte($end); $i++, $cursor->addMonthNoOverflow()) {
            $out[] = ['year' => $cursor->year, 'month' => $cursor->month, 'index' => $i, 'label' => ArabicDate::monthTitle($i, $cursor->month)];
        }

        return $out;
    }

    public function monthIndex(int $year, int $month): ?int
    {
        foreach ($this->months() as $m) {
            if ($m['year'] === $year && $m['month'] === $month) {
                return $m['index'];
            }
        }

        return null;
    }
```

Add to `app/Models/Application.php`:

```php
    public function attestations(): HasMany
    {
        return $this->hasMany(Attestation::class);
    }
```

Add to `app/Models/Section.php` after `hoursFromMinutes()`:

```php
    /** Hours as printed on the (خ-3) form: "2", "2.5", "1.25" — never a trailing ".0". */
    public static function hoursForForm(int $minutes): string
    {
        return rtrim(rtrim(number_format($minutes / 60, 2, '.', ''), '0'), '.');
    }
```

Add `use HasFactory;` to `app/Models/TermHoliday.php` (with the import).

- [ ] **Step 6: Factories**

`database/factories/TermHolidayFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

class TermHolidayFactory extends Factory
{
    public function definition(): array
    {
        return ['term_id' => Term::factory(), 'date' => '2026-06-16', 'name' => 'إجازة رأس السنة الهجرية'];
    }
}
```

`database/factories/AttestationFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\Attestation;
use Illuminate\Database\Eloquent\Factories\Factory;

class AttestationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'application_id' => Application::factory()->approved(),
            'year' => 2026, 'month' => 9,
            'status' => Attestation::STATUS_GENERATED, 'generated_at' => now(),
        ];
    }

    public function exported(): static
    {
        return $this->state(fn () => ['status' => Attestation::STATUS_EXPORTED, 'exported_at' => now()]);
    }
}
```

`database/factories/AttestationWeekFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Attestation;
use Illuminate\Database\Eloquent\Factories\Factory;

class AttestationWeekFactory extends Factory
{
    public function definition(): array
    {
        return [
            'attestation_id' => Attestation::factory(),
            'week_number' => 1, 'date_from' => '2026-09-13', 'date_to' => '2026-09-17',
            'working_days' => ['2026-09-13', '2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17'],
            'courses_text' => 'الدوائر الكهربائية (7230101)', 'generated_courses_text' => 'الدوائر الكهربائية (7230101)',
            'student_count' => 20, 'generated_student_count' => 20,
            'theory_minutes' => 150, 'generated_theory_minutes' => 150,
            'practical_minutes' => 100, 'generated_practical_minutes' => 100,
            'field_minutes' => 0, 'generated_field_minutes' => 0,
            'note_ar' => 'أسبوع كامل', 'generated_note_ar' => 'أسبوع كامل',
        ];
    }
}
```

- [ ] **Step 7: Run the tests**

Run: `php artisan test --compact tests/Unit/Attestations tests/Unit/SectionModelTest.php`
Expected: PASS. Then `php artisan migrate:fresh --database=sqlite --env=testing` is not needed; the RefreshDatabase run above proves the migrations. Also run `php artisan test --compact` (whole suite) once: PASS.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint app/Models app/Support database/factories database/migrations/2026_09_30_* tests/Unit/Attestations tests/Unit/SectionModelTest.php
git add app/Models app/Support database tests/Unit
git commit -m "feat(attestations): schema, models, term months, ArabicDate, hoursForForm"
```

---

### Task 2: `AttestationGenerator` with the summer 2026 fixture

**Files:**
- Create: `app/Services/Attestations/AttestationGenerator.php`
- Test: `tests/Unit/Attestations/AttestationGeneratorTest.php`

**Interfaces:**
- Consumes: Task 1 models; `Application::sections()` (HasManyThrough), `Section::meetings` (`day_of_week` 0–4, `type`, `minutes`), `Term::holidays`, `Term::isOpen()`, `Term::monthIndex()`.
- Produces: `AttestationGenerator::generate(Application $application, int $year, int $month, ?User $by = null): Attestation` (throws `DomainException` with a translated message: `app.attestations.not_approved`, `app.attestations.term_closed`, `app.attestations.month_outside_term`, `app.attestations.no_assignments`, `app.attestations.locked`); `AttestationGenerator::weeks(Term $term, Collection $sections, int $year, int $month): array` (list of row arrays with keys `week_number, date_from, date_to, working_days, courses_text, student_count, theory_minutes, practical_minutes, field_minutes, note_ar`).

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Attestations/AttestationGeneratorTest.php`:

```php
<?php

namespace Tests\Unit\Attestations;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Attestation;
use App\Models\Section;
use App\Models\Term;
use App\Models\TermHoliday;
use App\Models\User;
use App\Services\Attestations\AttestationGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttestationGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private Term $term;

    private Application $application;

    private Section $section;

    /** Spec §4 worked example: summer 2025-2026, one 10 h/week section. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->term = Term::factory()->open()->create(['type' => 'summer', 'academic_year' => '2025-2026', 'teaching_starts_on' => '2026-06-07', 'teaching_ends_on' => '2026-07-23']);
        TermHoliday::factory()->for($this->term)->create(['date' => '2026-06-16', 'name' => 'إجازة رأس السنة الهجرية']);
        $this->application = Application::factory()->approved()->for($this->term)->create();
        $this->section = Section::factory()->for($this->term)->create(['course_code' => '7230101', 'course_name_ar' => 'الدوائر الكهربائية', 'seats_registered' => 18]);
        foreach ([0, 2] as $day) {
            $this->section->meetings()->create(['day_of_week' => $day, 'type' => 'theory', 'starts_at' => '08:00', 'ends_at' => '10:00', 'minutes' => 120, 'activity_ar' => 'محاضرة']);
        }
        foreach ([1, 3, 4] as $day) {
            $this->section->meetings()->create(['day_of_week' => $day, 'type' => 'practical', 'starts_at' => '10:00', 'ends_at' => '12:00', 'minutes' => 120, 'activity_ar' => 'مختبر']);
        }
        Assignment::factory()->for($this->application)->for($this->section)->create();
    }

    private function generator(): AttestationGenerator
    {
        return app(AttestationGenerator::class);
    }

    public function test_june_rows_match_the_official_form(): void
    {
        $a = $this->generator()->generate($this->application, 2026, 6);

        $rows = $a->weeks->map(fn ($w) => [$w->week_number, $w->datesLabel(), $w->working_days, $w->theory_minutes, $w->practical_minutes, $w->field_minutes, $w->note_ar])->all();
        $this->assertSame([
            [1, '7-11', ['2026-06-07', '2026-06-08', '2026-06-09', '2026-06-10', '2026-06-11'], 240, 360, 0, 'أسبوع كامل'],
            [2, '14-18', ['2026-06-14', '2026-06-15', '2026-06-17', '2026-06-18'], 120, 360, 0, "الأحد- الاثنين- الأربعاء- الخميس (فقط)\nيوم الثلاثاء 16 يونيو 2026 إجازة رأس السنة الهجرية"],
            [3, '21-25', ['2026-06-21', '2026-06-22', '2026-06-23', '2026-06-24', '2026-06-25'], 240, 360, 0, 'أسبوع كامل'],
            [4, '28-30', ['2026-06-28', '2026-06-29', '2026-06-30'], 240, 120, 0, 'الأحد- الاثنين- الثلاثاء (فقط)'],
        ], $rows);
        $this->assertSame('الدوائر الكهربائية (7230101)', $a->weeks[0]->courses_text);
        $this->assertSame(18, $a->weeks[0]->student_count);
        $this->assertSame(['student_count' => 72, 'theory_minutes' => 840, 'practical_minutes' => 1200, 'field_minutes' => 0, 'total_minutes' => 2040], $a->totals());
        $this->assertSame(Attestation::STATUS_GENERATED, $a->status);
        $this->assertSame(1, $a->monthIndex());
    }

    public function test_july_rows_including_last_teaching_day(): void
    {
        $a = $this->generator()->generate($this->application, 2026, 7);

        $rows = $a->weeks->map(fn ($w) => [$w->week_number, $w->datesLabel(), $w->theory_minutes, $w->practical_minutes, $w->note_ar])->all();
        $this->assertSame([
            [1, '1-2', 0, 240, 'الأربعاء- الخميس (فقط)'],
            [2, '5-9', 240, 360, 'أسبوع كامل'],
            [3, '12-16', 240, 360, 'أسبوع كامل'],
            [4, '19-23', 240, 360, "أسبوع كامل\nآخر يوم دراسي 23 يوليو 2026"],
        ], $rows);
        $this->assertSame(2, $a->monthIndex());
    }

    public function test_generated_twins_equal_values_and_regeneration_resets_edits(): void
    {
        $a = $this->generator()->generate($this->application, 2026, 6);
        $w = $a->weeks[0];
        $this->assertSame($w->theory_minutes, $w->generated_theory_minutes);
        $w->update(['theory_minutes' => 60, 'note_ar' => 'معدل']);

        $again = $this->generator()->generate($this->application, 2026, 6, User::factory()->admin()->create());

        $this->assertSame($a->id, $again->id);
        $this->assertSame(240, $again->weeks[0]->theory_minutes);
        $this->assertSame('أسبوع كامل', $again->weeks[0]->note_ar);
        $this->assertNotNull($again->generated_by);
        $this->assertCount(4, $again->weeks);
    }

    public function test_teaching_starting_midweek_and_friday_holiday(): void
    {
        $term = Term::factory()->open()->create(['teaching_starts_on' => '2026-09-16', 'teaching_ends_on' => '2026-12-24']); // a Wednesday
        TermHoliday::factory()->for($term)->create(['date' => '2026-09-18', 'name' => 'عطلة يوم الجمعة']);
        $app = Application::factory()->approved()->for($term)->create();
        $s = Section::factory()->for($term)->withMeetings()->create(); // theory Sun+Tue 75, practical Mon 100
        Assignment::factory()->for($app)->for($s)->create();

        $a = $this->generator()->generate($app, 2026, 9);

        $this->assertSame('16-17', $a->weeks[0]->datesLabel());
        $this->assertSame(['2026-09-16', '2026-09-17'], $a->weeks[0]->working_days);
        $this->assertSame(0, $a->weeks[0]->theory_minutes);
        $this->assertSame('الأربعاء- الخميس (فقط)', $a->weeks[0]->note_ar);
        $this->assertSame('20-24', $a->weeks[1]->datesLabel());
        $this->assertSame(150, $a->weeks[1]->theory_minutes);
        $this->assertSame(100, $a->weeks[1]->practical_minutes);
    }

    public function test_whole_holiday_week_is_a_zero_row_with_holiday_note(): void
    {
        foreach (['2026-06-21', '2026-06-22', '2026-06-23', '2026-06-24', '2026-06-25'] as $d) {
            TermHoliday::factory()->for($this->term)->create(['date' => $d, 'name' => 'إجازة عيد الأضحى']);
        }

        $a = $this->generator()->generate($this->application, 2026, 6);

        $w = $a->weeks[2];
        $this->assertSame('21-25', $w->datesLabel());
        $this->assertSame([], $w->working_days);
        $this->assertSame(0, $w->totalMinutes());
        $this->assertSame('', $w->courses_text);
        $this->assertSame(0, $w->student_count);
        $this->assertStringStartsWith('يوم الأحد 21 يونيو 2026 إجازة عيد الأضحى', $w->note_ar);
        $this->assertSame(5, substr_count($w->note_ar, 'إجازة عيد الأضحى'));
    }

    public function test_same_course_twice_gives_one_line_and_summed_seats(): void
    {
        $s2 = Section::factory()->for($this->term)->create(['course_code' => '7230101', 'course_name_ar' => 'الدوائر الكهربائية', 'section_number' => '2', 'seats_registered' => 7]);
        $s2->meetings()->create(['day_of_week' => 2, 'type' => 'practical', 'starts_at' => '12:00', 'ends_at' => '13:00', 'minutes' => 60, 'activity_ar' => 'مختبر']);
        Assignment::factory()->for($this->application)->for($s2)->create();

        $a = $this->generator()->generate($this->application, 2026, 6);

        $this->assertSame('الدوائر الكهربائية (7230101)', $a->weeks[0]->courses_text);
        $this->assertSame(25, $a->weeks[0]->student_count);
        $this->assertSame(420, $a->weeks[0]->practical_minutes);
        // week 2: Tuesday is a holiday, so section 2 does not meet and its seats are not counted
        $this->assertSame(18, $a->weeks[1]->student_count);
    }

    public function test_courses_are_listed_in_course_code_order_one_per_line(): void
    {
        $s2 = Section::factory()->for($this->term)->create(['course_code' => '7210050', 'course_name_ar' => 'الرسم الهندسي', 'seats_registered' => 10]);
        $s2->meetings()->create(['day_of_week' => 0, 'type' => 'field', 'starts_at' => '12:00', 'ends_at' => '14:00', 'minutes' => 120, 'activity_ar' => 'ميداني']);
        Assignment::factory()->for($this->application)->for($s2)->create();

        $a = $this->generator()->generate($this->application, 2026, 6);

        $this->assertSame("الرسم الهندسي (7210050)\nالدوائر الكهربائية (7230101)", $a->weeks[0]->courses_text);
        $this->assertSame(120, $a->weeks[0]->field_minutes);
    }

    public function test_refusals(): void
    {
        $g = $this->generator();

        $this->expectExceptionMessage(__('app.attestations.month_outside_term'));
        $g->generate($this->application, 2026, 8);
    }

    public function test_refuses_exported_non_approved_closed_term_and_no_assignments(): void
    {
        $g = $this->generator();
        $a = $g->generate($this->application, 2026, 6);
        $a->update(['status' => Attestation::STATUS_EXPORTED]);
        try {
            $g->generate($this->application, 2026, 6);
            $this->fail('exported attestation was regenerated');
        } catch (\DomainException $e) {
            $this->assertSame(__('app.attestations.locked'), $e->getMessage());
        }

        $other = Application::factory()->approved()->for($this->term)->create();
        try {
            $g->generate($other, 2026, 6);
            $this->fail('application without assignments was generated');
        } catch (\DomainException $e) {
            $this->assertSame(__('app.attestations.no_assignments'), $e->getMessage());
        }

        $submitted = Application::factory()->submitted()->for($this->term)->create();
        try {
            $g->generate($submitted, 2026, 6);
            $this->fail('non-approved application was generated');
        } catch (\DomainException $e) {
            $this->assertSame(__('app.attestations.not_approved'), $e->getMessage());
        }

        $this->term->update(['status' => Term::STATUS_CLOSED]);
        $this->application->refresh();
        try {
            $g->generate($this->application, 2026, 7);
            $this->fail('closed term was generated');
        } catch (\DomainException $e) {
            $this->assertSame(__('app.attestations.term_closed'), $e->getMessage());
        }
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Unit/Attestations/AttestationGeneratorTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Lang keys (both files)**

Add a new block to `lang/ar/app.php` (the rest of the block grows in later tasks; keep it in one place):

```php
    'attestations' => [
        'not_approved' => 'لا تولد المزاولة إلا لطلب معتمد.',
        'term_closed' => 'الفصل الدراسي مغلق ولا يمكن توليد المزاولة أو تعديلها.',
        'month_outside_term' => 'الشهر خارج مدة الفصل الدراسي.',
        'no_assignments' => 'لا توجد شعب مسندة لهذا المنتدب.',
        'locked' => 'المزاولة مصدرة، افتحها للتعديل أولا.',
    ],
```

and in `lang/en/app.php`:

```php
    'attestations' => [
        'not_approved' => 'An attestation can only be generated for an approved application.',
        'term_closed' => 'The term is closed; attestations cannot be generated or edited.',
        'month_outside_term' => 'The month is outside the term.',
        'no_assignments' => 'This instructor has no assigned sections.',
        'locked' => 'The attestation has been exported; unlock it first.',
    ],
```

- [ ] **Step 4: The generator**

`app/Services/Attestations/AttestationGenerator.php`:

```php
<?php

namespace App\Services\Attestations;

use App\Models\Application;
use App\Models\Attestation;
use App\Models\AttestationWeek;
use App\Models\Term;
use App\Models\User;
use App\Support\ArabicDate;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Spec §4: application + month → stored week rows. Pure computation in weeks(); generate() persists. */
final class AttestationGenerator
{
    public function generate(Application $application, int $year, int $month, ?User $by = null): Attestation
    {
        $term = $application->term;
        if ($application->status !== Application::STATUS_APPROVED) {
            throw new DomainException(__('app.attestations.not_approved'));
        }
        if (! $term->isOpen()) {
            throw new DomainException(__('app.attestations.term_closed'));
        }
        if ($term->monthIndex($year, $month) === null) {
            throw new DomainException(__('app.attestations.month_outside_term'));
        }
        $sections = $application->sections()->with('meetings')->get();
        if ($sections->isEmpty()) {
            throw new DomainException(__('app.attestations.no_assignments'));
        }
        $existing = Attestation::where(['application_id' => $application->id, 'year' => $year, 'month' => $month])->first();
        if ($existing?->isExported()) {
            throw new DomainException(__('app.attestations.locked'));
        }

        $rows = $this->weeks($term, $sections, $year, $month);

        return DB::transaction(function () use ($application, $year, $month, $by, $existing, $rows) {
            $attestation = $existing ?? new Attestation(['application_id' => $application->id, 'year' => $year, 'month' => $month]);
            $attestation->fill(['status' => Attestation::STATUS_GENERATED, 'generated_at' => now(), 'generated_by' => $by?->id, 'exported_at' => null])->save();
            $attestation->weeks()->delete();
            foreach ($rows as $row) {
                foreach (AttestationWeek::EDITABLE as $col) {
                    $row['generated_'.$col] = $row[$col];
                }
                $attestation->weeks()->create($row);
            }

            return $attestation->refresh()->load('weeks');
        });
    }

    /**
     * @param  Collection<int, \App\Models\Section>  $sections  with meetings loaded
     * @return list<array<string, mixed>>
     */
    public function weeks(Term $term, Collection $sections, int $year, int $month): array
    {
        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth()->startOfDay();
        $from = $term->teaching_starts_on->greaterThan($monthStart) ? $term->teaching_starts_on->copy()->startOfDay() : $monthStart;
        $to = $term->teaching_ends_on->lessThan($monthEnd) ? $term->teaching_ends_on->copy()->startOfDay() : $monthEnd;
        $holidays = $term->holidays->keyBy(fn ($h) => $h->date->toDateString());

        $rows = [];
        $n = 0;
        for ($sunday = $from->copy()->subDays($from->dayOfWeek); $sunday->lte($to); $sunday->addWeek()) {
            $days = [];
            $working = [];
            $weekHolidays = [];
            for ($d = 0; $d < 5; $d++) {
                $day = $sunday->copy()->addDays($d);
                if ($day->lt($from) || $day->gt($to)) {
                    continue;
                }
                $days[] = $day;
                if (isset($holidays[$day->toDateString()])) {
                    $weekHolidays[] = $holidays[$day->toDateString()];
                } else {
                    $working[] = $day;
                }
            }
            if ($days === []) {
                continue;
            }
            $n++;
            $weekdays = array_map(fn (Carbon $d) => $d->dayOfWeek, $working);
            $weekSections = $sections->filter(fn ($s) => $s->meetings->contains(fn ($m) => in_array((int) $m->day_of_week, $weekdays, true)))->values();
            $minutes = ['theory' => 0, 'practical' => 0, 'field' => 0];
            foreach ($weekSections as $s) {
                foreach ($s->meetings as $m) {
                    if (in_array((int) $m->day_of_week, $weekdays, true)) {
                        $minutes[$m->type] += (int) $m->minutes;
                    }
                }
            }
            $rows[] = [
                'week_number' => $n,
                'date_from' => $days[0]->toDateString(),
                'date_to' => end($days)->toDateString(),
                'working_days' => array_map(fn (Carbon $d) => $d->toDateString(), $working),
                'courses_text' => $weekSections->unique('course_code')->sortBy('course_code')->map(fn ($s) => $s->course_name_ar.' ('.$s->course_code.')')->implode("\n"),
                'student_count' => (int) $weekSections->unique('id')->sum(fn ($s) => (int) $s->seats_registered),
                'theory_minutes' => $minutes['theory'],
                'practical_minutes' => $minutes['practical'],
                'field_minutes' => $minutes['field'],
                'note_ar' => $this->note($working, $weekHolidays, $days, $term),
            ];
        }

        return $rows;
    }

    /** @param  list<Carbon>  $working  @param  list<\App\Models\TermHoliday>  $holidays  @param  list<Carbon>  $days */
    private function note(array $working, array $holidays, array $days, Term $term): string
    {
        $lines = [];
        if (count($working) === 5) {
            $lines[] = 'أسبوع كامل';
        } elseif ($working !== []) {
            $lines[] = implode('- ', array_map(fn (Carbon $d) => ArabicDate::dayName($d), $working)).' (فقط)';
        }
        foreach ($holidays as $h) {
            $lines[] = 'يوم '.ArabicDate::dayName($h->date).' '.ArabicDate::long($h->date).' '.$h->name;
        }
        foreach ($days as $d) {
            if ($d->isSameDay($term->teaching_ends_on)) {
                $lines[] = 'آخر يوم دراسي '.ArabicDate::long($term->teaching_ends_on);
            }
        }

        return implode("\n", $lines);
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --compact tests/Unit/Attestations/AttestationGeneratorTest.php`
Expected: PASS (9 tests). If `test_teaching_starting_midweek_and_friday_holiday` fails on `'16-17'`, check that `$from` is the term start (Wednesday 2026-09-16) and that the block loop starts on the preceding Sunday.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint app/Services/Attestations tests/Unit/Attestations lang
git add app/Services/Attestations lang tests/Unit/Attestations
git commit -m "feat(attestations): generator reproducing the official summer 2026 form"
```

---

### Task 3: `AttestationService`, `AttestationPolicy`, routes, month page, "توليد الناقص"

**Files:**
- Create: `app/Services/Attestations/AttestationService.php`, `app/Policies/AttestationPolicy.php`, `app/Http/Controllers/Admin/AttestationController.php` (index + generate only in this task), `resources/views/admin/attestations/index.blade.php`
- Modify: `routes/web.php` (admin group), `app/Providers/AppServiceProvider.php` (register policy), `resources/views/admin/layout.blade.php` (nav link), `lang/ar/app.php`, `lang/en/app.php`
- Test: `tests/Unit/Attestations/AttestationPolicyTest.php`, `tests/Feature/Admin/AttestationsTest.php`

**Interfaces:**
- Consumes: Task 2 generator; `Term::current()`, `Term::months()`; `AuditLog::record()`.
- Produces:
  - `AttestationService::listed(Term $term): Collection<Application>` (approved, ≥1 assignment, with `instructor`, sorted by `full_name`, values re-indexed).
  - `AttestationService::generateMissing(Term $term, int $year, int $month, User $by): int` (count generated; each audited `generate_attestation` / `new`).
  - `AttestationService::resolveMonth(Term $term, ?int $index): ?array` (the month entry for `$index`, or the current calendar month if inside the term, else index 1).
  - `AttestationPolicy` abilities: `viewAny`, `view`, `generate` (class), `update`, `regenerate`, `export`, `unlock`, `exportAny` (class) → `isAdmin()`.
  - Routes (names under `admin.`): `attestations.index` GET `attestations`, `attestations.generate` POST `attestations/generate`; later tasks add `attestations.show`, `.update`, `.regenerate`, `.unlock`, `.download`, `.combined`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Attestations/AttestationPolicyTest.php`:

```php
<?php

namespace Tests\Unit\Attestations;

use App\Models\Attestation;
use App\Models\User;
use App\Policies\AttestationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttestationPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_allowed_instructor_denied_on_every_ability(): void
    {
        $policy = new AttestationPolicy;
        $admin = User::factory()->admin()->create();
        $instructor = User::factory()->instructor()->create();
        $attestation = Attestation::factory()->create();

        foreach (['viewAny', 'generate', 'exportAny'] as $ability) {
            $this->assertTrue($policy->$ability($admin), $ability);
            $this->assertFalse($policy->$ability($instructor), $ability);
        }
        foreach (['view', 'update', 'regenerate', 'export', 'unlock'] as $ability) {
            $this->assertTrue($policy->$ability($admin, $attestation), $ability);
            $this->assertFalse($policy->$ability($instructor, $attestation), $ability);
        }
    }
}
```

`tests/Feature/Admin/AttestationsTest.php` (this task's part; Task 4 adds more tests to the same class):

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Attestation;
use App\Models\AuditLog;
use App\Models\Instructor;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttestationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Term $term;

    private Application $assigned;

    private Application $unassigned;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->term = Term::factory()->open()->create(['type' => 'summer', 'academic_year' => '2025-2026', 'teaching_starts_on' => '2026-06-07', 'teaching_ends_on' => '2026-07-23']);
        $this->assigned = Application::factory()->approved()->for($this->term)->for(Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'أحمد سالم']))->create();
        Assignment::factory()->for($this->assigned)->for(Section::factory()->for($this->term)->withMeetings()->create())->create();
        $this->unassigned = Application::factory()->approved()->for($this->term)->for(Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'بدر ناصر']))->create();
    }

    public function test_instructor_is_forbidden_on_index_and_generate(): void
    {
        $user = User::factory()->instructor()->create();
        $this->actingAs($user)->get(route('admin.attestations.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.attestations.generate'), ['term' => $this->term->id, 'month' => 1])->assertForbidden();
    }

    public function test_index_lists_only_assigned_approved_applications_with_status(): void
    {
        Attestation::factory()->exported()->for($this->assigned)->create(['year' => 2026, 'month' => 6]);

        $r = $this->actingAs($this->admin)->get(route('admin.attestations.index', ['term' => $this->term->id, 'month' => 1]))->assertOk();

        $r->assertSee('أحمد سالم');
        $r->assertDontSee('بدر ناصر');
        $r->assertSee(__('app.attestations.status_exported'));
        $r->assertSee('الشهر الأول/ يونيو');
        $r->assertSee('الشهر الثاني/ يوليو');
    }

    public function test_index_defaults_to_the_current_month_inside_the_term(): void
    {
        $this->travelTo('2026-07-10');

        $r = $this->actingAs($this->admin)->get(route('admin.attestations.index'))->assertOk();

        $r->assertSee('<option value="2" selected', false);
        $r->assertSee(__('app.attestations.status_none'));
    }

    public function test_generate_missing_creates_only_missing_and_audits(): void
    {
        $second = Application::factory()->approved()->for($this->term)->for(Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'خالد عيسى']))->create();
        Assignment::factory()->for($second)->for(Section::factory()->for($this->term)->withMeetings()->create())->create();
        Attestation::factory()->for($this->assigned)->create(['year' => 2026, 'month' => 6]);

        $this->actingAs($this->admin)->post(route('admin.attestations.generate'), ['term' => $this->term->id, 'month' => 1])
            ->assertRedirect(route('admin.attestations.index', ['term' => $this->term->id, 'month' => 1]))
            ->assertSessionHas('status', __('app.attestations.generated_count', ['n' => 1]));

        $this->assertSame(1, Attestation::where('application_id', $second->id)->count());
        $this->assertSame(1, Attestation::where('application_id', $this->assigned->id)->count());
        $this->assertDatabaseHas('audit_log', ['action' => 'generate_attestation', 'subject_id' => Attestation::where('application_id', $second->id)->value('id'), 'details' => 'new', 'user_id' => $this->admin->id]);
    }

    public function test_generate_missing_on_closed_term_is_refused(): void
    {
        $this->term->update(['status' => Term::STATUS_CLOSED]);

        $this->actingAs($this->admin)->post(route('admin.attestations.generate'), ['term' => $this->term->id, 'month' => 1])
            ->assertSessionHasErrors('attestation');
        $this->assertSame(0, Attestation::count());
    }

    public function test_generate_missing_skips_instructor_whose_assignments_were_removed_but_keeps_existing(): void
    {
        $a = Attestation::factory()->for($this->assigned)->create(['year' => 2026, 'month' => 6]);
        Assignment::where('application_id', $this->assigned->id)->delete();

        $this->actingAs($this->admin)->post(route('admin.attestations.generate'), ['term' => $this->term->id, 'month' => 1])
            ->assertSessionHas('status', __('app.attestations.generated_count', ['n' => 0]));

        $this->assertDatabaseHas('attestations', ['id' => $a->id]);
        $r = $this->actingAs($this->admin)->get(route('admin.attestations.index', ['term' => $this->term->id, 'month' => 1]));
        $r->assertDontSee('أحمد سالم');
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Unit/Attestations/AttestationPolicyTest.php tests/Feature/Admin/AttestationsTest.php`
Expected: FAIL — policy class missing, routes undefined.

- [ ] **Step 3: Policy and registration**

`app/Policies/AttestationPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\Attestation;
use App\Models\User;

/** Attestations are admin-only (spec §7), on top of the role:admin middleware; state rules live in AttestationService. */
class AttestationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function generate(User $user): bool
    {
        return $user->isAdmin();
    }

    public function exportAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Attestation $attestation): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Attestation $attestation): bool
    {
        return $user->isAdmin();
    }

    public function regenerate(User $user, Attestation $attestation): bool
    {
        return $user->isAdmin();
    }

    public function export(User $user, Attestation $attestation): bool
    {
        return $user->isAdmin();
    }

    public function unlock(User $user, Attestation $attestation): bool
    {
        return $user->isAdmin();
    }
}
```

In `app/Providers/AppServiceProvider.php`, next to the existing three: `Gate::policy(Attestation::class, AttestationPolicy::class);` (add the two imports).

- [ ] **Step 4: Service**

`app/Services/Attestations/AttestationService.php`:

```php
<?php

namespace App\Services\Attestations;

use App\Models\Application;
use App\Models\Attestation;
use App\Models\AttestationWeek;
use App\Models\AuditLog;
use App\Models\Term;
use App\Models\User;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Collection;

/** Admin-facing operations around AttestationGenerator, each audited (spec §5, §7). */
final class AttestationService
{
    public function __construct(private AttestationGenerator $generator) {}

    /** Approved applications with at least one assignment, by instructor name. */
    public function listed(Term $term): Collection
    {
        return $term->applications()->where('status', Application::STATUS_APPROVED)->has('assignments')->with('instructor')->get()
            ->sortBy(fn ($a) => $a->instructor->full_name)->values();
    }

    /** @return array{year:int, month:int, index:int, label:string}|null */
    public function resolveMonth(Term $term, ?int $index): ?array
    {
        $months = $term->months();
        if ($months === []) {
            return null;
        }
        if ($index !== null) {
            foreach ($months as $m) {
                if ($m['index'] === $index) {
                    return $m;
                }
            }

            return null;
        }
        $today = Carbon::today();
        foreach ($months as $m) {
            if ($m['year'] === $today->year && $m['month'] === $today->month) {
                return $m;
            }
        }

        return $months[0];
    }

    public function generateMissing(Term $term, int $year, int $month, User $by): int
    {
        if (! $term->isOpen()) {
            throw new DomainException(__('app.attestations.term_closed'));
        }
        $n = 0;
        foreach ($this->listed($term) as $application) {
            if ($application->attestations()->where(['year' => $year, 'month' => $month])->exists()) {
                continue;
            }
            $attestation = $this->generator->generate($application, $year, $month, $by);
            AuditLog::record($by->id, 'generate_attestation', $attestation, null, 'new');
            $n++;
        }

        return $n;
    }

    public function regenerate(Attestation $attestation, User $by): Attestation
    {
        $this->assertEditable($attestation);
        $fresh = $this->generator->generate($attestation->application, (int) $attestation->year, (int) $attestation->month, $by);
        AuditLog::record($by->id, 'generate_attestation', $fresh, null, 'regenerated');

        return $fresh;
    }

    /**
     * @param  array<int, array<string, mixed>>  $weeks  week id => editable columns (minutes already converted)
     * @return list<string> changed column names, sorted
     */
    public function update(Attestation $attestation, array $weeks, User $by): array
    {
        $this->assertEditable($attestation);
        $changed = [];
        foreach ($attestation->weeks as $week) {
            if (! isset($weeks[$week->id])) {
                continue;
            }
            $data = array_intersect_key($weeks[$week->id], array_flip(AttestationWeek::EDITABLE));
            foreach ($data as $col => $value) {
                if ((string) $week->$col !== (string) $value) {
                    $changed[] = $col;
                }
            }
            $week->fill($data)->save();
        }
        $changed = array_values(array_unique($changed));
        sort($changed);
        AuditLog::record($by->id, 'update_attestation', $attestation, null, implode(',', $changed));

        return $changed;
    }

    public function unlock(Attestation $attestation, User $by): void
    {
        if (! $attestation->application->term->isOpen()) {
            throw new DomainException(__('app.attestations.term_closed'));
        }
        if (! $attestation->isExported()) {
            throw new DomainException(__('app.attestations.not_exported'));
        }
        $attestation->update(['status' => Attestation::STATUS_GENERATED]);
        AuditLog::record($by->id, 'unlock_attestation', $attestation);
    }

    /** Called by every download; $format is docx | pdf | combined_pdf. */
    public function markExported(Attestation $attestation, User $by, string $format): void
    {
        $attestation->update(['status' => Attestation::STATUS_EXPORTED, 'exported_at' => now()]);
        AuditLog::record($by->id, 'export_attestation', $attestation, null, $format);
    }

    private function assertEditable(Attestation $attestation): void
    {
        if (! $attestation->application->term->isOpen()) {
            throw new DomainException(__('app.attestations.term_closed'));
        }
        if ($attestation->isExported()) {
            throw new DomainException(__('app.attestations.locked'));
        }
    }
}
```

- [ ] **Step 5: Controller (index + generate), routes, nav**

`app/Http/Controllers/Admin/AttestationController.php` (Tasks 4 and 6 add methods to this class):

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attestation;
use App\Models\Term;
use App\Services\Attestations\AttestationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AttestationController extends Controller
{
    public function __construct(private AttestationService $service) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Attestation::class);
        $terms = Term::orderByDesc('teaching_starts_on')->get();
        $term = $request->filled('term') ? Term::findOrFail($request->term) : Term::current();
        if (! $term) {
            return view('admin.attestations.index', ['term' => null, 'terms' => $terms, 'months' => [], 'month' => null, 'rows' => collect()]);
        }
        $month = $this->service->resolveMonth($term, $request->filled('month') ? (int) $request->month : null);
        abort_if($month === null, 404);
        $existing = Attestation::whereIn('application_id', $this->service->listed($term)->pluck('id'))
            ->where(['year' => $month['year'], 'month' => $month['month']])->get()->keyBy('application_id');
        $rows = $this->service->listed($term)->map(fn ($a) => ['application' => $a, 'attestation' => $existing[$a->id] ?? null]);

        return view('admin.attestations.index', ['term' => $term, 'terms' => $terms, 'months' => $term->months(), 'month' => $month, 'rows' => $rows, 'existingCount' => $existing->count()]);
    }

    public function generate(Request $request): RedirectResponse
    {
        $this->authorize('generate', Attestation::class);
        $data = $request->validate(['term' => ['required', 'integer', 'exists:terms,id'], 'month' => ['required', 'integer', 'min:1', 'max:12']]);
        $term = Term::findOrFail($data['term']);
        $month = $this->service->resolveMonth($term, (int) $data['month']);
        abort_if($month === null, 404);
        try {
            $n = $this->service->generateMissing($term, $month['year'], $month['month'], $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['attestation' => $e->getMessage()]);
        }

        return redirect()->route('admin.attestations.index', ['term' => $term->id, 'month' => $month['index']])
            ->with('status', __('app.attestations.generated_count', ['n' => $n]));
    }
}
```

Routes, inside the admin group after the assignments routes:

```php
    Route::get('attestations', [AttestationController::class, 'index'])->name('attestations.index');
    Route::post('attestations/generate', [AttestationController::class, 'generate'])->name('attestations.generate');
```

Nav in `resources/views/admin/layout.blade.php` after the assignments link:

```blade
    <a class="btn btn-outline-light btn-sm" href="{{ route('admin.attestations.index') }}">{{ __('app.attestations.title') }}</a>
```

- [ ] **Step 6: Lang keys (add to the `attestations` block, both files)**

Arabic:

```php
        'title' => 'المزاولة الشهرية (خ-3)',
        'term' => 'الفصل الدراسي',
        'month' => 'الشهر',
        'instructor' => 'المنتدب',
        'weekly_hours' => 'الساعات الأسبوعية',
        'status' => 'الحالة',
        'status_none' => 'غير مولدة',
        'status_generated' => 'مولدة',
        'status_exported' => 'مصدرة',
        'generated_at' => 'تاريخ التوليد',
        'exported_at' => 'آخر تصدير',
        'generate_missing' => 'توليد الناقص',
        'generated_count' => 'تم توليد :n مزاولة.',
        'combined_pdf' => 'ملف PDF مجمع',
        'none_for_month' => 'لا توجد مزاولات مولدة لهذا الشهر.',
        'no_listed' => 'لا يوجد منتدبون معتمدون لهم شعب مسندة في هذا الفصل.',
        'open' => 'فتح',
        'word' => 'Word',
        'pdf' => 'PDF',
        'not_exported' => 'المزاولة غير مصدرة.',
```

English:

```php
        'title' => 'Monthly attestation (KH-3)',
        'term' => 'Term',
        'month' => 'Month',
        'instructor' => 'Instructor',
        'weekly_hours' => 'Weekly hours',
        'status' => 'Status',
        'status_none' => 'Not generated',
        'status_generated' => 'Generated',
        'status_exported' => 'Exported',
        'generated_at' => 'Generated on',
        'exported_at' => 'Last export',
        'generate_missing' => 'Generate missing',
        'generated_count' => ':n attestation(s) generated.',
        'combined_pdf' => 'Combined PDF',
        'none_for_month' => 'No attestations have been generated for this month.',
        'no_listed' => 'No approved instructors with assigned sections in this term.',
        'open' => 'Open',
        'word' => 'Word',
        'pdf' => 'PDF',
        'not_exported' => 'The attestation has not been exported.',
```

- [ ] **Step 7: Month page view**

`resources/views/admin/attestations/index.blade.php`:

```blade
@extends('admin.layout')
@section('title', __('app.attestations.title'))
@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">{{ __('app.attestations.title') }}</h1>
</div>

@if ($errors->has('attestation') || $errors->has('export'))
    <div class="alert alert-danger">{{ $errors->first('attestation') ?: $errors->first('export') }}</div>
@endif
@if (session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif

<form method="get" action="{{ route('admin.attestations.index') }}" class="row g-2 align-items-center mb-3">
    <div class="col-auto">
        <label class="visually-hidden" for="term">{{ __('app.attestations.term') }}</label>
        <select id="term" name="term" class="form-select" onchange="if (this.form.month) { this.form.month.value=''; } this.form.submit()">
            @foreach ($terms as $t)
                <option value="{{ $t->id }}" @selected($term && $term->id === $t->id)>{{ $t->label() }}</option>
            @endforeach
        </select>
    </div>
    @if ($term)
        <div class="col-auto">
            <label class="visually-hidden" for="month">{{ __('app.attestations.month') }}</label>
            <select id="month" name="month" class="form-select" onchange="this.form.submit()">
                @foreach ($months as $m)
                    <option value="{{ $m['index'] }}" @selected($month && $month['index'] === $m['index'])>{{ $m['label'] }}</option>
                @endforeach
            </select>
        </div>
    @endif
</form>

@if (! $term)
    <div class="alert alert-info">{{ __('app.terms.none_open') }}</div>
@elseif ($rows->isEmpty())
    <div class="alert alert-info">{{ __('app.attestations.no_listed') }}</div>
@else
    <div class="d-flex gap-2 mb-3">
        @if ($term->isOpen())
            <form method="post" action="{{ route('admin.attestations.generate') }}">
                @csrf
                <input type="hidden" name="term" value="{{ $term->id }}">
                <input type="hidden" name="month" value="{{ $month['index'] }}">
                <button type="submit" class="btn btn-eet">{{ __('app.attestations.generate_missing') }}</button>
            </form>
        @endif
        @if (Route::has('admin.attestations.combined'))
            <a class="btn btn-outline-secondary @if (($existingCount ?? 0) === 0) disabled @endif" href="{{ route('admin.attestations.combined', ['term' => $term->id, 'month' => $month['index']]) }}">{{ __('app.attestations.combined_pdf') }}</a>
        @endif
    </div>

    <div class="table-responsive">
        <table class="table table-striped align-middle">
            <thead>
            <tr>
                <th>{{ __('app.attestations.instructor') }}</th>
                <th>{{ __('app.attestations.weekly_hours') }}</th>
                <th>{{ __('app.attestations.status') }}</th>
                <th>{{ __('app.attestations.generated_at') }}</th>
                <th>{{ __('app.attestations.exported_at') }}</th>
                <th>{{ __('app.common.actions') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($rows as $row)
                @php($a = $row['attestation'])
                <tr>
                    <td>{{ $row['application']->instructor->full_name }}</td>
                    <td>{{ $row['application']->weeklyHoursLabel() }}</td>
                    <td>
                        @if (! $a)
                            <span class="badge bg-secondary">{{ __('app.attestations.status_none') }}</span>
                        @elseif ($a->isExported())
                            <span class="badge bg-success">{{ __('app.attestations.status_exported') }}</span>
                        @else
                            <span class="badge bg-warning text-dark">{{ __('app.attestations.status_generated') }}</span>
                        @endif
                    </td>
                    <td>{{ $a?->generated_at?->format('Y-m-d') }}</td>
                    <td>{{ $a?->exported_at?->format('Y-m-d') }}</td>
                    <td>
                        @if ($a && Route::has('admin.attestations.show'))
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.attestations.show', $a) }}">{{ __('app.attestations.open') }}</a>
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.attestations.download', [$a, 'format' => 'docx']) }}">{{ __('app.attestations.word') }}</a>
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.attestations.download', [$a, 'format' => 'pdf']) }}">{{ __('app.attestations.pdf') }}</a>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
@endsection
```

(The `Route::has()` guards let this task ship before Tasks 4 and 6 define those routes; remove the guards in Task 6 once every route exists.)

- [ ] **Step 8: Run the tests**

Run: `php artisan test --compact tests/Unit/Attestations/AttestationPolicyTest.php tests/Feature/Admin/AttestationsTest.php`
Expected: PASS (7 tests).

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint app/Services/Attestations app/Policies app/Http/Controllers/Admin/AttestationController.php app/Providers/AppServiceProvider.php lang tests
git add app routes resources/views/admin lang tests
git commit -m "feat(attestations): service, policy, month page and generate-missing"
```

---

### Task 4: Attestation page — save, regenerate, unlock

**Files:**
- Create: `app/Http/Requests/AttestationUpdateRequest.php`, `resources/views/admin/attestations/show.blade.php`
- Modify: `app/Http/Controllers/Admin/AttestationController.php` (add `show`, `update`, `regenerate`, `unlock`), `routes/web.php`, `lang/ar/app.php`, `lang/en/app.php`
- Test: `tests/Feature/Admin/AttestationsTest.php` (add tests)

**Interfaces:**
- Consumes: `AttestationService::update()/regenerate()/unlock()`, `AttestationWeek::EDITABLE`, `Section::hoursForForm()`.
- Produces: routes `attestations.show` GET `attestations/{attestation}`, `attestations.update` PUT `attestations/{attestation}`, `attestations.regenerate` POST `attestations/{attestation}/regenerate`, `attestations.unlock` POST `attestations/{attestation}/unlock`; `AttestationUpdateRequest::weeks(): array<int, array>` (week id → editable columns with hours converted to minutes).

- [ ] **Step 1: Write the failing tests** (append to `AttestationsTest`)

```php
    private function generated(): Attestation
    {
        return app(\App\Services\Attestations\AttestationGenerator::class)->generate($this->assigned, 2026, 6, $this->admin);
    }

    public function test_show_page_lists_weeks_and_generated_values(): void
    {
        $a = $this->generated();
        $a->weeks[0]->update(['theory_minutes' => 60]);

        $r = $this->actingAs($this->admin)->get(route('admin.attestations.show', $a))->assertOk();

        $r->assertSee('الشهر الأول/ يونيو');
        $r->assertSee('7-11');
        $r->assertSee(__('app.attestations.generated_value', ['value' => '2.5']));
        $r->assertSee('name="weeks['.$a->weeks[0]->id.'][theory_hours]"', false);
        $r->assertSee('value="1"', false);
    }

    public function test_save_converts_hours_to_minutes_keeps_twins_and_audits_changed_columns(): void
    {
        $a = $this->generated();
        $w = $a->weeks[1];

        $this->actingAs($this->admin)->put(route('admin.attestations.update', $a), ['weeks' => [
            $w->id => ['courses_text' => $w->courses_text, 'student_count' => 21, 'theory_hours' => '1.333', 'practical_hours' => '1.5', 'field_hours' => '0', 'note_ar' => $w->note_ar],
        ]])->assertRedirect(route('admin.attestations.show', $a))->assertSessionHas('status', __('app.attestations.saved'));

        $w->refresh();
        $this->assertSame(21, $w->student_count);
        $this->assertSame(80, $w->theory_minutes);
        $this->assertSame(90, $w->practical_minutes);
        $this->assertSame(150, $w->generated_theory_minutes);
        $this->assertTrue($w->isEdited());
        $this->assertDatabaseHas('audit_log', ['action' => 'update_attestation', 'subject_id' => $a->id, 'details' => 'practical_minutes,student_count,theory_minutes']);
    }

    public function test_save_rejects_comma_decimals_and_negative_counts(): void
    {
        $a = $this->generated();
        $w = $a->weeks[0];

        $this->actingAs($this->admin)->from(route('admin.attestations.show', $a))->put(route('admin.attestations.update', $a), ['weeks' => [
            $w->id => ['courses_text' => '', 'student_count' => -1, 'theory_hours' => '2,5', 'practical_hours' => '0', 'field_hours' => '0', 'note_ar' => ''],
        ]])->assertRedirect(route('admin.attestations.show', $a))->assertSessionHasErrors(["weeks.{$w->id}.theory_hours", "weeks.{$w->id}.student_count"]);

        $this->assertSame(150, $w->fresh()->theory_minutes);
    }

    public function test_save_refused_when_exported_or_term_closed(): void
    {
        $a = $this->generated();
        $w = $a->weeks[0];
        $payload = ['weeks' => [$w->id => ['courses_text' => 'x', 'student_count' => 1, 'theory_hours' => '1', 'practical_hours' => '0', 'field_hours' => '0', 'note_ar' => '']]];

        $a->update(['status' => Attestation::STATUS_EXPORTED]);
        $this->actingAs($this->admin)->put(route('admin.attestations.update', $a), $payload)->assertSessionHasErrors('attestation');

        $a->update(['status' => Attestation::STATUS_GENERATED]);
        $this->term->update(['status' => Term::STATUS_CLOSED]);
        $this->actingAs($this->admin)->put(route('admin.attestations.update', $a), $payload)->assertSessionHasErrors('attestation');
        $this->assertSame(150, $w->fresh()->theory_minutes);
    }

    public function test_regenerate_discards_edits_and_audits(): void
    {
        $a = $this->generated();
        $a->weeks[0]->update(['student_count' => 99]);

        $this->actingAs($this->admin)->post(route('admin.attestations.regenerate', $a))->assertRedirect(route('admin.attestations.show', $a));

        $this->assertSame(0, $a->fresh()->weeks[0]->student_count);
        $this->assertDatabaseHas('audit_log', ['action' => 'generate_attestation', 'subject_id' => $a->id, 'details' => 'regenerated']);
    }

    public function test_unlock_returns_exported_to_generated_and_regenerate_then_works(): void
    {
        $a = $this->generated();
        $a->update(['status' => Attestation::STATUS_EXPORTED, 'exported_at' => now()]);

        $this->actingAs($this->admin)->post(route('admin.attestations.unlock', $a))->assertRedirect(route('admin.attestations.show', $a));
        $this->assertSame(Attestation::STATUS_GENERATED, $a->fresh()->status);
        $this->assertNotNull($a->fresh()->exported_at);
        $this->assertDatabaseHas('audit_log', ['action' => 'unlock_attestation', 'subject_id' => $a->id]);

        $this->actingAs($this->admin)->post(route('admin.attestations.regenerate', $a))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('admin.attestations.unlock', $a))->assertSessionHasErrors('attestation');
    }

    public function test_instructor_forbidden_on_show_update_regenerate_unlock(): void
    {
        $a = $this->generated();
        $user = User::factory()->instructor()->create();
        $this->actingAs($user)->get(route('admin.attestations.show', $a))->assertForbidden();
        $this->actingAs($user)->put(route('admin.attestations.update', $a), [])->assertForbidden();
        $this->actingAs($user)->post(route('admin.attestations.regenerate', $a))->assertForbidden();
        $this->actingAs($user)->post(route('admin.attestations.unlock', $a))->assertForbidden();
    }
```

(`withMeetings()` gives theory 75 + 75 = 150 min and practical 100 min per full week; week 1 of June 2026 (7–11) is full, so `generated_theory_minutes` is 150 and the generated-value label for 150 minutes is "2.5".)

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Admin/AttestationsTest.php`
Expected: FAIL — routes undefined.

- [ ] **Step 3: Form request**

`app/Http/Requests/AttestationUpdateRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttestationUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        $hours = ['required', 'regex:/^\d{1,3}(\.\d{1,3})?$/'];

        return [
            'weeks' => ['required', 'array'],
            'weeks.*.courses_text' => ['nullable', 'string', 'max:2000'],
            'weeks.*.student_count' => ['required', 'integer', 'min:0', 'max:9999'],
            'weeks.*.theory_hours' => $hours,
            'weeks.*.practical_hours' => $hours,
            'weeks.*.field_hours' => $hours,
            'weeks.*.note_ar' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        $msg = __('app.attestations.hours_format');

        return ['weeks.*.theory_hours.regex' => $msg, 'weeks.*.practical_hours.regex' => $msg, 'weeks.*.field_hours.regex' => $msg];
    }

    /** @return array<int, array<string, mixed>> week id => editable columns, hours converted to minutes */
    public function weeks(): array
    {
        $out = [];
        foreach ($this->validated('weeks') as $id => $w) {
            $out[(int) $id] = [
                'courses_text' => (string) ($w['courses_text'] ?? ''),
                'student_count' => (int) $w['student_count'],
                'theory_minutes' => (int) round(((float) $w['theory_hours']) * 60),
                'practical_minutes' => (int) round(((float) $w['practical_hours']) * 60),
                'field_minutes' => (int) round(((float) $w['field_hours']) * 60),
                'note_ar' => (string) ($w['note_ar'] ?? ''),
            ];
        }

        return $out;
    }
}
```

- [ ] **Step 4: Controller methods and routes**

Add to `AttestationController` (imports `App\Http\Requests\AttestationUpdateRequest`):

```php
    public function show(Attestation $attestation): View
    {
        $this->authorize('view', $attestation);
        $attestation->load(['weeks', 'application.instructor', 'application.term']);

        return view('admin.attestations.show', ['attestation' => $attestation, 'totals' => $attestation->totals(), 'term' => $attestation->application->term]);
    }

    public function update(AttestationUpdateRequest $request, Attestation $attestation): RedirectResponse
    {
        $this->authorize('update', $attestation);
        try {
            $this->service->update($attestation, $request->weeks(), $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['attestation' => $e->getMessage()]);
        }

        return redirect()->route('admin.attestations.show', $attestation)->with('status', __('app.attestations.saved'));
    }

    public function regenerate(Request $request, Attestation $attestation): RedirectResponse
    {
        $this->authorize('regenerate', $attestation);
        try {
            $this->service->regenerate($attestation, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['attestation' => $e->getMessage()]);
        }

        return redirect()->route('admin.attestations.show', $attestation)->with('status', __('app.attestations.regenerated'));
    }

    public function unlock(Request $request, Attestation $attestation): RedirectResponse
    {
        $this->authorize('unlock', $attestation);
        try {
            $this->service->unlock($attestation, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['attestation' => $e->getMessage()]);
        }

        return redirect()->route('admin.attestations.show', $attestation)->with('status', __('app.attestations.unlocked'));
    }
```

Routes (after the two from Task 3):

```php
    Route::get('attestations/{attestation}', [AttestationController::class, 'show'])->name('attestations.show');
    Route::put('attestations/{attestation}', [AttestationController::class, 'update'])->name('attestations.update');
    Route::post('attestations/{attestation}/regenerate', [AttestationController::class, 'regenerate'])->name('attestations.regenerate');
    Route::post('attestations/{attestation}/unlock', [AttestationController::class, 'unlock'])->name('attestations.unlock');
```

- [ ] **Step 5: Lang keys (append to the block, both files)**

Arabic:

```php
        'week' => 'الأسبوع',
        'dates' => 'التاريخ',
        'courses' => 'اسم ورقم المقرر',
        'students' => 'الكثافة الطلابية',
        'theory' => 'نظري',
        'practical' => 'عملي',
        'field' => 'ميداني',
        'total' => 'المجموع',
        'note' => 'ملاحظات',
        'monthly_total' => 'المجموع الشهري',
        'generated_value' => 'المولد: :value',
        'hours_format' => 'اكتب الساعات بأرقام إنجليزية ونقطة عشرية، مثل 2 أو 2.5.',
        'saved' => 'تم حفظ التعديلات.',
        'regenerate' => 'إعادة التوليد',
        'regenerate_confirm' => 'إعادة التوليد تلغي التعديلات اليدوية على هذا الشهر. متابعة؟',
        'regenerated' => 'تمت إعادة التوليد.',
        'unlock' => 'فتح للتعديل',
        'unlocked' => 'تم فتح المزاولة للتعديل.',
        'locked_notice' => 'هذه المزاولة مصدرة؛ لتعديلها افتحها للتعديل ثم صدرها من جديد.',
        'decision' => 'قرار التكليف',
        'decision_number' => 'رقم القرار',
        'decision_date' => 'تاريخ القرار',
        'back_to_month' => 'العودة إلى الشهر',
```

English:

```php
        'week' => 'Week',
        'dates' => 'Dates',
        'courses' => 'Course name and code',
        'students' => 'Students',
        'theory' => 'Theory',
        'practical' => 'Practical',
        'field' => 'Field',
        'total' => 'Total',
        'note' => 'Notes',
        'monthly_total' => 'Monthly total',
        'generated_value' => 'Generated: :value',
        'hours_format' => 'Enter hours with Western digits and a decimal point, e.g. 2 or 2.5.',
        'saved' => 'Changes saved.',
        'regenerate' => 'Regenerate',
        'regenerate_confirm' => 'Regenerating discards the manual edits of this month. Continue?',
        'regenerated' => 'Regenerated.',
        'unlock' => 'Unlock for editing',
        'unlocked' => 'The attestation is unlocked for editing.',
        'locked_notice' => 'This attestation has been exported; unlock it to edit, then export again.',
        'decision' => 'Appointment decision',
        'decision_number' => 'Decision number',
        'decision_date' => 'Decision date',
        'back_to_month' => 'Back to the month',
```

- [ ] **Step 6: Show view**

`resources/views/admin/attestations/show.blade.php`:

```blade
@extends('admin.layout')
@section('title', __('app.attestations.title'))
@section('content')
@php($app = $attestation->application)
@php($i = $app->instructor)
@php($editable = $term->isOpen() && ! $attestation->isExported())

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">{{ __('app.attestations.title') }} — {{ $i->full_name }} — {{ $attestation->monthTitle() }}</h1>
    <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.attestations.index', ['term' => $term->id, 'month' => $attestation->monthIndex()]) }}">{{ __('app.attestations.back_to_month') }}</a>
</div>

@if ($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif
@if (session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif
@if ($attestation->isExported())
    <div class="alert alert-warning">{{ __('app.attestations.locked_notice') }}</div>
@endif

<div class="card mb-3"><div class="card-body">
    <div class="row">
        <div class="col-md-4"><strong>{{ __('app.terms.type') }}:</strong> {{ $term->label() }}</div>
        <div class="col-md-4"><strong>{{ __('app.attestations.decision_number') }}:</strong> {{ $app->assignment_decision_number ?: '—' }}</div>
        <div class="col-md-4"><strong>{{ __('app.attestations.decision_date') }}:</strong> {{ $app->assignment_decision_date?->format('Y/m/d') ?: '—' }}</div>
        <div class="col-md-4"><strong>{{ __('app.profile.job_title') }}:</strong> {{ $i->job_title }}</div>
        <div class="col-md-4"><strong>{{ __('app.profile.employer') }}:</strong> {{ $i->employer }}</div>
        <div class="col-md-4"><strong>{{ __('app.attestations.weekly_hours') }}:</strong> {{ $app->weeklyHoursLabel() }}</div>
        <div class="col-md-4"><strong>{{ __('app.attestations.status') }}:</strong>
            {{ $attestation->isExported() ? __('app.attestations.status_exported') : __('app.attestations.status_generated') }}
            @if ($attestation->exported_at) ({{ $attestation->exported_at->format('Y-m-d H:i') }}) @endif
        </div>
    </div>
</div></div>

<form method="post" action="{{ route('admin.attestations.update', $attestation) }}">
    @csrf
    @method('PUT')
    <div class="table-responsive">
        <table class="table table-bordered align-middle">
            <thead>
            <tr>
                <th>{{ __('app.attestations.week') }}</th>
                <th>{{ __('app.attestations.dates') }}</th>
                <th>{{ __('app.attestations.courses') }}</th>
                <th>{{ __('app.attestations.students') }}</th>
                <th>{{ __('app.attestations.theory') }}</th>
                <th>{{ __('app.attestations.practical') }}</th>
                <th>{{ __('app.attestations.field') }}</th>
                <th>{{ __('app.attestations.total') }}</th>
                <th>{{ __('app.attestations.note') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($attestation->weeks as $w)
                @php($n = "weeks[{$w->id}]")
                <tr>
                    <td>{{ $w->week_number }}</td>
                    <td>{{ $w->datesLabel() }}</td>
                    <td>
                        <textarea name="{{ $n }}[courses_text]" class="form-control form-control-sm" rows="2" @disabled(! $editable)>{{ old("weeks.{$w->id}.courses_text", $w->courses_text) }}</textarea>
                        @if ($w->courses_text !== $w->generated_courses_text)<div class="form-text">{{ __('app.attestations.generated_value', ['value' => $w->generated_courses_text]) }}</div>@endif
                    </td>
                    <td>
                        <input type="number" min="0" name="{{ $n }}[student_count]" class="form-control form-control-sm" value="{{ old("weeks.{$w->id}.student_count", $w->student_count) }}" @disabled(! $editable)>
                        @if ((int) $w->student_count !== (int) $w->generated_student_count)<div class="form-text">{{ __('app.attestations.generated_value', ['value' => $w->generated_student_count]) }}</div>@endif
                    </td>
                    @foreach (['theory', 'practical', 'field'] as $type)
                        <td>
                            <input type="text" inputmode="decimal" name="{{ $n }}[{{ $type }}_hours]" class="form-control form-control-sm" value="{{ old("weeks.{$w->id}.{$type}_hours", \App\Models\Section::hoursForForm((int) $w->{$type.'_minutes'})) }}" @disabled(! $editable)>
                            @if ((int) $w->{$type.'_minutes'} !== (int) $w->{'generated_'.$type.'_minutes'})<div class="form-text">{{ __('app.attestations.generated_value', ['value' => \App\Models\Section::hoursForForm((int) $w->{'generated_'.$type.'_minutes'})]) }}</div>@endif
                        </td>
                    @endforeach
                    <td>{{ \App\Models\Section::hoursForForm($w->totalMinutes()) }}</td>
                    <td>
                        <textarea name="{{ $n }}[note_ar]" class="form-control form-control-sm" rows="2" @disabled(! $editable)>{{ old("weeks.{$w->id}.note_ar", $w->note_ar) }}</textarea>
                        @if ($w->note_ar !== $w->generated_note_ar)<div class="form-text">{{ __('app.attestations.generated_value', ['value' => $w->generated_note_ar]) }}</div>@endif
                    </td>
                </tr>
            @endforeach
            <tr class="table-secondary fw-bold">
                <td colspan="3">{{ __('app.attestations.monthly_total') }}</td>
                <td>{{ $totals['student_count'] }}</td>
                <td>{{ \App\Models\Section::hoursForForm($totals['theory_minutes']) }}</td>
                <td>{{ \App\Models\Section::hoursForForm($totals['practical_minutes']) }}</td>
                <td>{{ \App\Models\Section::hoursForForm($totals['field_minutes']) }}</td>
                <td>{{ \App\Models\Section::hoursForForm($totals['total_minutes']) }}</td>
                <td></td>
            </tr>
            </tbody>
        </table>
    </div>
    @if ($editable)
        <button type="submit" class="btn btn-eet">{{ __('app.common.save') }}</button>
    @endif
</form>

<div class="d-flex gap-2 mt-3">
    @if ($editable)
        <form method="post" action="{{ route('admin.attestations.regenerate', $attestation) }}" onsubmit="return confirm(@js(__('app.attestations.regenerate_confirm')))">
            @csrf
            <button type="submit" class="btn btn-outline-danger">{{ __('app.attestations.regenerate') }}</button>
        </form>
    @elseif ($term->isOpen() && $attestation->isExported())
        <form method="post" action="{{ route('admin.attestations.unlock', $attestation) }}">
            @csrf
            <button type="submit" class="btn btn-outline-warning">{{ __('app.attestations.unlock') }}</button>
        </form>
    @endif
    @if (Route::has('admin.attestations.download'))
        <a class="btn btn-outline-secondary" href="{{ route('admin.attestations.download', [$attestation, 'format' => 'docx']) }}">{{ __('app.attestations.word') }}</a>
        <a class="btn btn-outline-secondary" href="{{ route('admin.attestations.download', [$attestation, 'format' => 'pdf']) }}">{{ __('app.attestations.pdf') }}</a>
    @endif
</div>
@endsection
```

(`app.profile.job_title` and `app.profile.employer` exist from milestone 1; verify with `grep -n "'job_title'\|'employer'" lang/ar/app.php` and use the actual keys if they differ.)

- [ ] **Step 7: Run the tests**

Run: `php artisan test --compact tests/Feature/Admin/AttestationsTest.php`
Expected: PASS (14 tests). Then the whole suite: PASS.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint app/Http lang tests/Feature/Admin/AttestationsTest.php
git add app routes resources lang tests
git commit -m "feat(attestations): attestation page with save, regenerate and unlock"
```

---

### Task 5: Word template from the official form, `AttestationDocument`

**Files:**
- Create: `scripts/build-kh3-template.py`, `resources/forms/kh3-template.docx` (generated by the script, committed), `app/Services/Attestations/Kh3TemplateProcessor.php`, `app/Services/Attestations/AttestationDocument.php`
- Modify: `lang/ar/app.php`, `lang/en/app.php` (category + term labels)
- Test: `tests/Unit/Attestations/AttestationDocumentTest.php`

**Interfaces:**
- Consumes: Task 1 models, `Attestation::totals()`, `AttestationWeek::datesLabel()`, `Section::hoursForForm()`, `ArabicDate`, `Instructor` encrypted casts, `Application::assignment_decision_*`, lang `app.dept_name`, `app.attestations.category`, `app.attestations.term_labels.*`.
- Produces: `AttestationDocument::docx(Attestation): string` (absolute path of a temp .docx), `AttestationDocument::combinedDocx(Collection<Attestation>): string`; `Kh3TemplateProcessor extends \PhpOffice\PhpWord\TemplateProcessor` with `stripFirstPageBreak(): void`.

- [ ] **Step 1: Copy the source form and write the build script**

The source is the blank official form (no personal data):
`/Users/malshar/Library/CloudStorage/OneDrive-ThePublicAuthorityforAppliedEducationandTraining/committees/لجنة الجداول 2025-2026/schedule planning/2026-2027-Term-1/منتدبين/forms/استمارة المزاولة الفعلية  الصيفي 2025-2026للمنتدب.docx`
(read it only from that path; do not copy it anywhere except through the script's output). Its structure (inspected 2026-09-30): body = page 1 [title table (1 row, 3 cells; middle cell has 3 paragraphs: form title / "(من خارج الهيئة- كادر عام ) للفصل الصيفي" / " 2025-2026"), header paragraphs, civil-ID table (1 row, 13 cells: label + 12 boxes), more header paragraphs, schedule table (7 rows: heading, sub-heading, 4 week rows of 9 cells, totals row of 7 cells with a gridSpan=3 first cell), the "ملاحظة مهمة" paragraph] followed by an identical page 2; page header "نموذج (خ-3)" and the signature footer are in `header1.xml`/`footer1.xml` and stay untouched.

`scripts/build-kh3-template.py`:

```python
#!/usr/bin/env python3
"""Turn the official blank (خ-3) .docx into resources/forms/kh3-template.docx with PhpWord placeholders.

Usage: python3 scripts/build-kh3-template.py "<official blank .docx>" resources/forms/kh3-template.docx
Keeps page 1 only, wraps it in ${page}…${/page} (cloned per instructor), keeps one week row (${week_no},
cloned per week) and replaces every fillable field with a ${placeholder}. Fixed Arabic text is untouched.
"""
import re
import sys
import zipfile

src, out = sys.argv[1], sys.argv[2]
zin = zipfile.ZipFile(src)
xml = zin.read('word/document.xml').decode('utf-8')

P = r'<w:p\b[^>]*>(?:(?!<w:p\b).)*?</w:p>'   # innermost paragraph (no nested paragraph start)
TC = r'<w:tc>.*?</w:tc>'
TR = r'<w:tr\b[^>]*>.*?</w:tr>'
TBL = r'<w:tbl>.*?</w:tbl>'


def text(x):
    return ''.join(re.findall(r'<w:t[^>]*>([^<]*)</w:t>', x))


def set_para(p, new):
    """Keep pPr and the first run's rPr; replace all runs with one run holding new."""
    ptag = re.match(r'<w:p\b[^>]*>', p).group(0)
    ppr = re.search(r'<w:pPr>.*?</w:pPr>', p, re.S)
    rpr = re.search(r'<w:r\b[^>]*>(<w:rPr>.*?</w:rPr>)', p, re.S)
    return ptag + (ppr.group(0) if ppr else '') + '<w:r>' + (rpr.group(1) if rpr else '') + \
        '<w:t xml:space="preserve">' + new + '</w:t></w:r></w:p>'


def set_cell(tc, new):
    """Keep tcPr; one paragraph (the cell's first) with the new text."""
    tcpr = re.search(r'<w:tcPr>.*?</w:tcPr>', tc, re.S)
    first = re.search(P, tc, re.S).group(0)
    return '<w:tc>' + (tcpr.group(0) if tcpr else '') + set_para(first, new) + '</w:tc>'


def replace_para_containing(body, needle, new, occurrence=1):
    seen = 0
    for m in re.finditer(P, body, re.S):
        if needle in text(m.group(0)):
            seen += 1
            if seen == occurrence:
                return body[:m.start()] + set_para(m.group(0), new) + body[m.end():]
    raise SystemExit(f'paragraph containing {needle!r} not found')


head, rest = xml.split('<w:body>', 1)
body, tail = rest.rsplit('<w:sectPr', 1)
tail = '<w:sectPr' + tail

# 1. page 1 only: cut after the first "ملاحظة مهمة" paragraph
m = next(m for m in re.finditer(P, body, re.S) if 'ملاحظة مهمة' in text(m.group(0)))
body = body[:m.end()]

# 2. header paragraphs
body = replace_para_containing(body, '(من خارج الهيئة', '${category} ${term_label}')
body = replace_para_containing(body, '2025-2026', '${academic_year}')
body = replace_para_containing(body, 'القسم العلمي', 'القسم العلمي: ${dept_name}')
body = replace_para_containing(body, 'طبقا لقرار التكليف', 'طبقا لقرار التكليف الصادر من الهيئة برقم ( ${decision_number} )  بتاريخ ( ${decision_date} )')
body = replace_para_containing(body, 'اسم المنتدب', 'اسم المنتدب/ ${full_name}      المسمى الوظيفي ${job_title}')
body = replace_para_containing(body, 'جهة العمل الأصلية', 'جهة العمل الأصلية  ${employer}')
body = replace_para_containing(body, 'اسم المقرر 1', 'اسم المقرر 1 ${course1}    2 ${course2}    3 ${course3}')
body = replace_para_containing(body, 'رقم الحساب', 'رقم الحساب ${account_number}    اسم البنك ${bank_name}    فرع ${bank_branch}')
body = replace_para_containing(body, 'الراتب الأساسي', 'الراتب الأساسي ${basic_salary}    الراتب الإجمالي ${total_salary}')
body = replace_para_containing(body, 'التلفون/ العمل', 'التلفون/ العمل ${phone_work}    المنزل ${phone_home}    النقال ${phone_mobile}    عدد الساعات ${weekly_hours}')

# 3. tables
tables = list(re.finditer(TBL, body, re.S))
assert len(tables) == 3, len(tables)
# civil-ID boxes: cells 1..12
t1 = tables[1].group(0)
cells = list(re.finditer(TC, t1, re.S))
assert len(cells) == 13, len(cells)
new_t1 = t1
for idx in range(12, 0, -1):
    c = cells[idx]
    new_t1 = new_t1[:c.start()] + set_cell(c.group(0), '${cid%d}' % idx) + new_t1[c.end():]
# schedule table
t2 = tables[2].group(0)
rows = list(re.finditer(TR, t2, re.S))
assert len(rows) == 7, len(rows)
r0 = rows[0].group(0)
c0 = re.search(TC, r0, re.S)
r0 = r0[:c0.start()] + set_cell(c0.group(0), '${month_title}') + r0[c0.end():]
week = rows[2].group(0)
wcells = list(re.finditer(TC, week, re.S))
assert len(wcells) == 9, len(wcells)
names = ['week_no', 'week_dates', 'week_courses', 'week_students', 'week_theory', 'week_practical', 'week_field', 'week_total', 'week_note']
new_week = week
for c, name in reversed(list(zip(wcells, names))):
    new_week = new_week[:c.start()] + set_cell(c.group(0), '${%s}' % name) + new_week[c.end():]
tot = rows[6].group(0)
tcells = list(re.finditer(TC, tot, re.S))
assert len(tcells) == 7, len(tcells)
sums = [None, 'sum_students', 'sum_theory', 'sum_practical', 'sum_field', 'sum_total', '']
new_tot = tot
for c, name in reversed(list(zip(tcells, sums))):
    if name is None:
        continue
    new_tot = new_tot[:c.start()] + set_cell(c.group(0), '${%s}' % name if name else '') + new_tot[c.end():]
new_t2 = t2[:rows[0].start()] + r0 + rows[1].group(0) + new_week + new_tot + t2[rows[6].end():]
body = body[:tables[1].start()] + new_t1 + body[tables[1].end():tables[2].start()] + new_t2 + body[tables[2].end():]

# 4. page block: ${page} + a page-break paragraph before the first table, ${/page} at the end
first_tbl = body.index('<w:tbl>')
body = (body[:first_tbl]
        + '<w:p><w:r><w:t>${page}</w:t></w:r></w:p><w:p><w:pPr><w:pageBreakBefore/></w:pPr></w:p>'
        + body[first_tbl:]
        + '<w:p><w:r><w:t>${/page}</w:t></w:r></w:p>')

new_xml = head + '<w:body>' + body + tail
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as zout:
    for item in zin.infolist():
        data = new_xml.encode('utf-8') if item.filename == 'word/document.xml' else zin.read(item.filename)
        zout.writestr(item, data)
vars_found = sorted(set(re.findall(r'\$\{([^}]+)\}', new_xml)))
print(len(vars_found), 'placeholders:', ' '.join(vars_found))
```

Run it: `mkdir -p resources/forms && python3 scripts/build-kh3-template.py "<source path above>" resources/forms/kh3-template.docx`
Expected output: `46 placeholders: /page academic_year account_number bank_branch bank_name basic_salary category cid1 … cid12 course1 course2 course3 decision_date decision_number dept_name employer full_name job_title month_title page phone_home phone_mobile phone_work sum_field sum_practical sum_students sum_theory sum_total term_label total_salary week_courses week_dates week_field week_no week_note week_practical week_students week_theory week_total weekly_hours`. Open the result once in Word or LibreOffice to confirm it still looks like the form (one page, placeholders visible). If a `replace_para_containing` raises, print the paragraph texts (`python3 - <<< ...` with `text()`) and adjust the needle — the needles above were verified against the 2025-2026 summer file.

- [ ] **Step 2: Write the failing tests**

`tests/Unit/Attestations/AttestationDocumentTest.php`:

```php
<?php

namespace Tests\Unit\Attestations;

use App\Models\Application;
use App\Models\Attestation;
use App\Models\AttestationWeek;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use App\Services\Attestations\AttestationDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpWord\TemplateProcessor;
use Tests\TestCase;

class AttestationDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_exposes_the_expected_placeholders(): void
    {
        $vars = (new TemplateProcessor(resource_path('forms/kh3-template.docx')))->getVariables();

        foreach (['page', '/page', 'term_label', 'academic_year', 'category', 'dept_name', 'decision_number', 'decision_date', 'full_name', 'job_title',
            'cid1', 'cid12', 'employer', 'course1', 'course2', 'course3', 'account_number', 'bank_name', 'bank_branch', 'basic_salary', 'total_salary',
            'phone_work', 'phone_home', 'phone_mobile', 'weekly_hours', 'month_title', 'week_no', 'week_dates', 'week_courses', 'week_students',
            'week_theory', 'week_practical', 'week_field', 'week_total', 'week_note', 'sum_students', 'sum_theory', 'sum_practical', 'sum_field', 'sum_total'] as $v) {
            $this->assertContains($v, $vars, $v);
        }
    }

    private function attestation(string $name = 'أحمد سالم', string $civilId = '290010112345'): Attestation
    {
        $term = Term::factory()->open()->create(['type' => 'summer', 'academic_year' => '2025-2026', 'teaching_starts_on' => '2026-06-07', 'teaching_ends_on' => '2026-07-23']);
        $instructor = Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => $name, 'civil_id' => $civilId, 'job_title' => 'مهندس <أول>', 'work_phone' => '22334455', 'home_phone' => '25556677', 'mobile' => '99887766']);
        $application = Application::factory()->approved()->for($term)->for($instructor)->create(['assignment_decision_number' => '1234', 'assignment_decision_date' => '2026-05-20', 'weekly_minutes' => 600]);
        $a = Attestation::factory()->for($application)->create(['year' => 2026, 'month' => 6]);
        AttestationWeek::factory()->for($a)->create(['week_number' => 1, 'date_from' => '2026-06-07', 'date_to' => '2026-06-11', 'theory_minutes' => 240, 'practical_minutes' => 360, 'student_count' => 18, 'courses_text' => "الدوائر الكهربائية (7230101)\nالرسم الهندسي (7210050)", 'note_ar' => 'أسبوع كامل']);
        AttestationWeek::factory()->for($a)->create(['week_number' => 2, 'date_from' => '2026-06-14', 'date_to' => '2026-06-18', 'theory_minutes' => 120, 'practical_minutes' => 360, 'student_count' => 18, 'note_ar' => "الأحد- الاثنين- الأربعاء- الخميس (فقط)\nيوم الثلاثاء 16 يونيو 2026 إجازة رأس السنة الهجرية"]);

        return $a->load('weeks', 'application.instructor', 'application.term');
    }

    private function documentXml(string $path): string
    {
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($path);

        return $xml;
    }

    public function test_single_document_contains_header_weeks_and_totals(): void
    {
        $a = $this->attestation();

        $xml = $this->documentXml(app(AttestationDocument::class)->docx($a));

        $this->assertStringContainsString('أحمد سالم', $xml);
        $this->assertStringContainsString('مهندس &lt;أول&gt;', $xml);
        $this->assertStringContainsString('للفصل الصيفي', $xml);
        $this->assertStringContainsString('2025-2026', $xml);
        $this->assertStringContainsString(__('app.attestations.category', [], 'ar'), $xml);
        $this->assertStringContainsString(__('app.dept_name', [], 'ar'), $xml);
        $this->assertStringContainsString('( 1234 )', $xml);
        $this->assertStringContainsString('20/5/2026', $xml);
        $this->assertStringContainsString('KW81CBKU0000000000001234560101', $xml);
        $this->assertStringContainsString('1200', $xml);
        $this->assertStringContainsString('الشهر الأول/ يونيو', $xml);
        $this->assertStringContainsString('7-11', $xml);
        $this->assertStringContainsString('14-18', $xml);
        $this->assertStringContainsString('إجازة رأس السنة الهجرية', $xml);
        $this->assertStringContainsString('<w:br/>', $xml);
        $this->assertStringContainsString('>10<', $xml);      // weekly_hours 600 min
        $this->assertStringContainsString('>36<', $xml);      // sum_students
        $this->assertStringContainsString('>6<', $xml);       // sum_theory 360 min
        $this->assertStringContainsString('>12<', $xml);      // sum_practical 720 min
        $this->assertStringContainsString('>18<', $xml);      // sum_total
        $this->assertStringNotContainsString('${', $xml);
        $this->assertSame(1, substr_count($xml, 'استمارة مزاولة فعلية'));
        $this->assertSame(0, substr_count($xml, '<w:pageBreakBefore/>'));
        // twelve civil-ID boxes, in order, each its own cell
        $this->assertMatchesRegularExpression('~'.implode('.*?', array_map(fn ($d) => preg_quote("<w:t xml:space=\"preserve\">$d</w:t>", '~'), str_split('290010112345'))).'~s', $xml);
        $this->assertStringContainsString('الدوائر الكهربائية', $xml);
        $this->assertStringContainsString('الرسم الهندسي', $xml);
    }

    public function test_empty_hours_print_blank_and_course_overflow_joins_into_course3(): void
    {
        $a = $this->attestation();
        $a->weeks[0]->update(['field_minutes' => 0]);
        $courses = [];
        foreach (['7210050' => 'الرسم الهندسي', '7230101' => 'الدوائر الكهربائية', '7230202' => 'الإلكترونيات', '7230303' => 'الآلات الكهربائية'] as $code => $name) {
            $s = \App\Models\Section::factory()->for($a->application->term)->create(['course_code' => $code, 'course_name_ar' => $name]);
            \App\Models\Assignment::factory()->for($a->application)->for($s)->create();
        }

        $xml = $this->documentXml(app(AttestationDocument::class)->docx($a->fresh()->load('weeks', 'application.instructor', 'application.term')));

        $this->assertStringContainsString('الإلكترونيات، الآلات الكهربائية', $xml);
        $this->assertStringContainsString('<w:t xml:space="preserve"></w:t>', $xml);   // a blank field cell
    }

    public function test_combined_document_has_one_page_block_per_attestation_in_name_order(): void
    {
        $b = $this->attestation('يوسف كامل', '290010154321');
        $a = $this->attestation('أحمد سالم', '290010112345');

        $xml = $this->documentXml(app(AttestationDocument::class)->combinedDocx(collect([$b, $a])));

        $this->assertSame(2, substr_count($xml, 'استمارة مزاولة فعلية'));
        $this->assertSame(1, substr_count($xml, '<w:pageBreakBefore/>'));
        $this->assertLessThan(mb_strpos($xml, 'يوسف كامل'), mb_strpos($xml, 'أحمد سالم'));
        $this->assertStringNotContainsString('${', $xml);
    }
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Unit/Attestations/AttestationDocumentTest.php`
Expected: the placeholder test passes (template exists), the other three FAIL — `AttestationDocument` not found.

- [ ] **Step 4: Lang keys (append to the `attestations` block, both files)**

Arabic:

```php
        'category' => '(من خارج الهيئة - كادر عام)',
        'term_labels' => ['first' => 'للفصل الأول', 'second' => 'للفصل الثاني', 'summer' => 'للفصل الصيفي'],
```

English:

```php
        'category' => '(external - general cadre)',
        'term_labels' => ['first' => 'first semester', 'second' => 'second semester', 'summer' => 'summer semester'],
```

(The document always reads these with the `ar` locale, like `ChecklistDocument` does with `__('app.dept_name', [], 'ar')`.)

- [ ] **Step 5: Template processor subclass and document builder**

`app/Services/Attestations/Kh3TemplateProcessor.php`:

```php
<?php

namespace App\Services\Attestations;

use PhpOffice\PhpWord\TemplateProcessor;

/** Adds the one thing the page-block cloning needs: no page break before the first page. */
final class Kh3TemplateProcessor extends TemplateProcessor
{
    public function stripFirstPageBreak(): void
    {
        $this->tempDocumentMainPart = preg_replace('~<w:p><w:pPr><w:pageBreakBefore/></w:pPr></w:p>~', '', $this->tempDocumentMainPart, 1);
    }
}
```

`app/Services/Attestations/AttestationDocument.php`:

```php
<?php

namespace App\Services\Attestations;

use App\Models\Attestation;
use App\Models\AttestationWeek;
use App\Models\Section;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\Settings;

/**
 * Fills resources/forms/kh3-template.docx (spec §6.1). The only place that decrypts civil ID, IBAN and salaries.
 * Returns the path of a temp .docx under storage/app/private/generated/tmp; the caller deletes it after send.
 */
final class AttestationDocument
{
    public function __construct(private ?string $template = null)
    {
        $this->template ??= resource_path('forms/kh3-template.docx');
    }

    public function docx(Attestation $attestation): string
    {
        return $this->build(collect([$attestation]));
    }

    /** @param  Collection<int, Attestation>  $attestations */
    public function combinedDocx(Collection $attestations): string
    {
        return $this->build($attestations->sortBy(fn (Attestation $a) => $a->application->instructor->full_name)->values());
    }

    /** @param  Collection<int, Attestation>  $attestations */
    private function build(Collection $attestations): string
    {
        Settings::setOutputEscapingEnabled(true);
        $tp = new Kh3TemplateProcessor($this->template);
        $tp->cloneBlock('page', $attestations->count(), true, true);
        foreach ($attestations->values() as $i => $attestation) {
            $p = '#'.($i + 1);
            $attestation->loadMissing('weeks', 'application.instructor', 'application.term');
            $tp->setValues($this->headerValues($attestation, $p));
            $weeks = $attestation->weeks->values();
            $tp->cloneRow('week_no'.$p, max(1, $weeks->count()));
            foreach ($weeks as $k => $week) {
                $tp->setValues($this->weekValues($week, $p.'#'.($k + 1)));
            }
            $tp->setValues($this->totalValues($attestation, $p));
        }
        $tp->stripFirstPageBreak();

        $dir = Storage::disk('local')->path('generated/tmp');
        File::ensureDirectoryExists($dir);
        $path = $dir.'/'.Str::random(32).'.docx';
        $tp->saveAs($path);

        return $path;
    }

    /** @return array<string, string> */
    private function headerValues(Attestation $a, string $suffix): array
    {
        $app = $a->application;
        $i = $app->instructor;
        $term = $app->term;
        $courses = $app->sections()->orderBy('course_code')->get()->unique('course_code')->map(fn ($s) => $s->course_name_ar)->values();
        $v = [
            'term_label' => __('app.attestations.term_labels.'.$term->type, [], 'ar'),
            'academic_year' => (string) $term->academic_year,
            'category' => __('app.attestations.category', [], 'ar'),
            'dept_name' => __('app.dept_name', [], 'ar'),
            'decision_number' => (string) ($app->assignment_decision_number ?? ''),
            'decision_date' => $app->assignment_decision_date?->format('j/n/Y') ?? '',
            'full_name' => (string) $i->full_name,
            'job_title' => (string) $i->job_title,
            'employer' => (string) $i->employer,
            'course1' => (string) ($courses[0] ?? ''),
            'course2' => (string) ($courses[1] ?? ''),
            'course3' => $courses->slice(2)->implode('، '),
            'account_number' => (string) $i->iban,
            'bank_name' => (string) $i->bank_name,
            'bank_branch' => (string) $i->bank_branch,
            'basic_salary' => (string) $i->basic_salary,
            'total_salary' => (string) $i->total_salary,
            'phone_work' => (string) $i->work_phone,
            'phone_home' => (string) $i->home_phone,
            'phone_mobile' => (string) $i->mobile,
            'weekly_hours' => Section::hoursForForm((int) $app->weekly_minutes),
            'month_title' => $a->monthTitle(),
        ];
        $digits = str_split(str_pad(preg_replace('/\D/', '', (string) $i->civil_id), 12, ' ', STR_PAD_RIGHT));
        for ($n = 1; $n <= 12; $n++) {
            $v['cid'.$n] = trim($digits[$n - 1]);
        }

        return $this->suffixed($v, $suffix);
    }

    /** @return array<string, string> */
    private function weekValues(AttestationWeek $w, string $suffix): array
    {
        $hours = fn (int $minutes) => $minutes > 0 ? Section::hoursForForm($minutes) : '';

        return $this->suffixed([
            'week_no' => (string) $w->week_number,
            'week_dates' => $w->datesLabel(),
            'week_courses' => (string) $w->courses_text,
            'week_students' => $w->student_count > 0 ? (string) $w->student_count : '',
            'week_theory' => $hours((int) $w->theory_minutes),
            'week_practical' => $hours((int) $w->practical_minutes),
            'week_field' => $hours((int) $w->field_minutes),
            'week_total' => $hours($w->totalMinutes()),
            'week_note' => (string) $w->note_ar,
        ], $suffix);
    }

    /** @return array<string, string> */
    private function totalValues(Attestation $a, string $suffix): array
    {
        $t = $a->totals();

        return $this->suffixed([
            'sum_students' => (string) $t['student_count'],
            'sum_theory' => Section::hoursForForm($t['theory_minutes']),
            'sum_practical' => Section::hoursForForm($t['practical_minutes']),
            'sum_field' => Section::hoursForForm($t['field_minutes']),
            'sum_total' => Section::hoursForForm($t['total_minutes']),
        ], $suffix);
    }

    /** @param  array<string, string>  $values  @return array<string, string> */
    private function suffixed(array $values, string $suffix): array
    {
        $out = [];
        foreach ($values as $k => $v) {
            $out[$k.$suffix] = $v;
        }

        return $out;
    }
}
```

Notes for the implementer: `cloneBlock(..., indexVariables: true)` renames every placeholder inside the block to `${name#1}`, `${name#2}`…; `cloneRow('week_no#1', n)` then renames the row's placeholders to `${week_dates#1#1}`, `${week_dates#1#2}`…, which is why the suffixes are composed as `#page#week`. PhpWord's `setValue` escapes XML when `Settings::setOutputEscapingEnabled(true)` and converts `\n` to `<w:br/>`; if the `<w:br/>` assertion fails on the installed PhpWord version, replace `"\n"` with `'</w:t><w:br/><w:t>'` yourself after escaping (override `setValue` in `Kh3TemplateProcessor`). If the totals row's `gridSpan=3` cell is not the first cell in your run of the script, adjust the `sums` list in the script so each placeholder lands under its heading (check by opening the template).

- [ ] **Step 6: Run the tests**

Run: `php artisan test --compact tests/Unit/Attestations/AttestationDocumentTest.php`
Expected: PASS (4 tests).

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint app/Services/Attestations lang tests/Unit/Attestations
git add scripts/build-kh3-template.py resources/forms/kh3-template.docx app/Services/Attestations lang tests/Unit/Attestations
git commit -m "feat(attestations): Word template from the official form and document builder"
```

---

### Task 6: `PdfConverter`, downloads, combined PDF

**Files:**
- Create: `app/Services/Attestations/PdfConverter.php`, `tests/Fixtures/fake-soffice.sh`
- Modify: `config/services.php`, `app/Http/Controllers/Admin/AttestationController.php` (add `download`, `combined`), `routes/web.php`, `resources/views/admin/attestations/index.blade.php` and `show.blade.php` (remove the `Route::has()` guards), `lang/ar/app.php`, `lang/en/app.php`, `.env.example`, `deploy/.env.production.example` (add `SOFFICE_PATH=` commented)
- Test: `tests/Unit/Attestations/PdfConverterTest.php`, `tests/Feature/Admin/AttestationExportTest.php`

**Interfaces:**
- Consumes: `AttestationDocument`, `AttestationService::markExported()/listed()/resolveMonth()`.
- Produces: `PdfConverter::available(): bool`, `PdfConverter::convert(string $docxPath): string` (PDF path next to the input; throws `RuntimeException`); config `services.soffice.path` (env `SOFFICE_PATH`, default `soffice`); routes `attestations.download` GET `attestations/{attestation}/download?format=docx|pdf`, `attestations.combined` GET `attestations/combined?term&month` (declared before `attestations/{attestation}`).

- [ ] **Step 1: Fake soffice and config**

`tests/Fixtures/fake-soffice.sh` (then `chmod +x`):

```bash
#!/bin/sh
# Stand-in for LibreOffice in tests: writes <outdir>/<input basename>.pdf with a PDF magic header.
# Arguments mirror the real call: --headless --norestore -env:... --convert-to pdf --outdir <dir> <file>
outdir=""
while [ $# -gt 1 ]; do
  if [ "$1" = "--outdir" ]; then outdir="$2"; shift; fi
  shift
done
input="$1"
[ "${FAKE_SOFFICE_FAIL:-0}" = "1" ] && { echo "conversion failed" >&2; exit 1; }
base=$(basename "$input" .docx)
printf '%%PDF-1.4 fake\n' > "$outdir/$base.pdf"
```

`config/services.php` — add:

```php
    'soffice' => [
        'path' => env('SOFFICE_PATH', 'soffice'),
    ],
```

`.env.example` and `deploy/.env.production.example` — add `# SOFFICE_PATH=soffice   # LibreOffice binary for PDF export (default: soffice on PATH)`.

- [ ] **Step 2: Write the failing tests**

`tests/Unit/Attestations/PdfConverterTest.php`:

```php
<?php

namespace Tests\Unit\Attestations;

use App\Services\Attestations\PdfConverter;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

class PdfConverterTest extends TestCase
{
    public function test_fake_binary_converts_and_cleans_its_profile_dir(): void
    {
        $fake = base_path('tests/Fixtures/fake-soffice.sh');
        chmod($fake, 0755);
        $dir = storage_path('app/private/generated/tmp');
        File::ensureDirectoryExists($dir);
        $docx = $dir.'/unit-'.uniqid().'.docx';
        file_put_contents($docx, 'not really a docx');

        $pdf = (new PdfConverter($fake))->convert($docx);

        $this->assertFileExists($pdf);
        $this->assertStringStartsWith('%PDF', file_get_contents($pdf));
        $this->assertSame([], glob($dir.'/lo-profile-*'));
        unlink($docx);
        unlink($pdf);
    }

    public function test_missing_binary_is_reported_as_unavailable(): void
    {
        $c = new PdfConverter('/nonexistent/soffice');
        $this->assertFalse($c->available());
        $this->expectException(\RuntimeException::class);
        $c->convert('/tmp/whatever.docx');
    }

    public function test_real_libreoffice_when_present(): void
    {
        $bin = (new ExecutableFinder)->find('soffice');
        if ($bin === null) {
            $this->markTestSkipped('soffice not installed');
        }
        $dir = storage_path('app/private/generated/tmp');
        File::ensureDirectoryExists($dir);
        $path = $dir.'/real-'.uniqid().'.docx';
        copy(resource_path('forms/kh3-template.docx'), $path);

        $pdf = (new PdfConverter($bin))->convert($path);

        $this->assertStringStartsWith('%PDF', file_get_contents($pdf));
        unlink($path);
        unlink($pdf);
    }
}
```

`tests/Feature/Admin/AttestationExportTest.php`:

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
use App\Services\Attestations\AttestationGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AttestationExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Term $term;

    private Attestation $a;

    protected function setUp(): void
    {
        parent::setUp();
        chmod(base_path('tests/Fixtures/fake-soffice.sh'), 0755);
        config(['services.soffice.path' => base_path('tests/Fixtures/fake-soffice.sh')]);
        $this->admin = User::factory()->admin()->create();
        $this->term = Term::factory()->open()->create(['type' => 'summer', 'academic_year' => '2025-2026', 'teaching_starts_on' => '2026-06-07', 'teaching_ends_on' => '2026-07-23']);
        $application = Application::factory()->approved()->for($this->term)->for(Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'أحمد سالم']))->create();
        Assignment::factory()->for($application)->for(Section::factory()->for($this->term)->withMeetings()->create())->create();
        $this->a = app(AttestationGenerator::class)->generate($application, 2026, 6, $this->admin);
    }

    private function tmpFiles(): array
    {
        return array_values(array_filter(glob(storage_path('app/private/generated/tmp/*')) ?: [], fn ($f) => is_file($f)));
    }

    public function test_word_download_marks_exported_audits_and_leaves_no_temp_file(): void
    {
        $r = $this->actingAs($this->admin)->get(route('admin.attestations.download', [$this->a, 'format' => 'docx']));

        $r->assertOk()->assertDownload("kh3-{$this->a->application_id}-2026-6.docx");
        $r->baseResponse->sendContent();
        $this->assertSame(Attestation::STATUS_EXPORTED, $this->a->fresh()->status);
        $this->assertNotNull($this->a->fresh()->exported_at);
        $this->assertDatabaseHas('audit_log', ['action' => 'export_attestation', 'subject_id' => $this->a->id, 'details' => 'docx', 'user_id' => $this->admin->id]);
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'export_attestation')->count());
    }

    public function test_pdf_download_uses_the_converter(): void
    {
        $r = $this->actingAs($this->admin)->get(route('admin.attestations.download', [$this->a, 'format' => 'pdf']));

        $r->assertOk()->assertDownload("kh3-{$this->a->application_id}-2026-6.pdf");
        $r->baseResponse->sendContent();
        $this->assertDatabaseHas('audit_log', ['action' => 'export_attestation', 'subject_id' => $this->a->id, 'details' => 'pdf']);
        $this->assertSame([], array_filter($this->tmpFiles(), fn ($f) => str_ends_with($f, '.docx')));
    }

    public function test_pdf_failure_reports_unavailable_keeps_status_and_cleans_up(): void
    {
        putenv('FAKE_SOFFICE_FAIL=1');
        try {
            $this->actingAs($this->admin)->from(route('admin.attestations.show', $this->a))
                ->get(route('admin.attestations.download', [$this->a, 'format' => 'pdf']))
                ->assertRedirect(route('admin.attestations.show', $this->a))
                ->assertSessionHasErrors(['export' => __('app.attestations.pdf_unavailable')]);
        } finally {
            putenv('FAKE_SOFFICE_FAIL');
        }

        $this->assertSame(Attestation::STATUS_GENERATED, $this->a->fresh()->status);
        $this->assertDatabaseMissing('audit_log', ['action' => 'export_attestation']);
        $this->assertSame([], $this->tmpFiles());
        // Word still works afterwards
        $this->actingAs($this->admin)->get(route('admin.attestations.download', [$this->a, 'format' => 'docx']))->assertOk();
    }

    public function test_pdf_with_missing_binary_reports_unavailable(): void
    {
        config(['services.soffice.path' => '/nonexistent/soffice']);

        $this->actingAs($this->admin)->get(route('admin.attestations.download', [$this->a, 'format' => 'pdf']))
            ->assertSessionHasErrors('export');
    }

    public function test_unknown_format_is_404_and_downloads_work_on_closed_term(): void
    {
        $this->actingAs($this->admin)->get(route('admin.attestations.download', [$this->a, 'format' => 'xls']))->assertNotFound();
        $this->term->update(['status' => Term::STATUS_CLOSED]);
        $this->actingAs($this->admin)->get(route('admin.attestations.download', [$this->a, 'format' => 'docx']))->assertOk();
    }

    public function test_combined_pdf_marks_every_included_attestation_and_audits_each(): void
    {
        $second = Application::factory()->approved()->for($this->term)->for(Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'بدر ناصر']))->create();
        Assignment::factory()->for($second)->for(Section::factory()->for($this->term)->withMeetings()->create())->create();
        $b = app(AttestationGenerator::class)->generate($second, 2026, 6, $this->admin);

        $r = $this->actingAs($this->admin)->get(route('admin.attestations.combined', ['term' => $this->term->id, 'month' => 1]));

        $r->assertOk()->assertDownload("kh3-{$this->term->id}-2026-6.pdf");
        $r->baseResponse->sendContent();
        $this->assertSame(Attestation::STATUS_EXPORTED, $this->a->fresh()->status);
        $this->assertSame(Attestation::STATUS_EXPORTED, $b->fresh()->status);
        $this->assertSame(2, \App\Models\AuditLog::where('action', 'export_attestation')->where('details', 'combined_pdf')->count());
    }

    public function test_combined_pdf_with_nothing_generated_is_refused(): void
    {
        $this->actingAs($this->admin)->from(route('admin.attestations.index', ['term' => $this->term->id, 'month' => 2]))
            ->get(route('admin.attestations.combined', ['term' => $this->term->id, 'month' => 2]))
            ->assertRedirect(route('admin.attestations.index', ['term' => $this->term->id, 'month' => 2]))
            ->assertSessionHasErrors('export');
    }

    public function test_instructor_forbidden_on_download_and_combined(): void
    {
        $user = User::factory()->instructor()->create();
        $this->actingAs($user)->get(route('admin.attestations.download', [$this->a, 'format' => 'docx']))->assertForbidden();
        $this->actingAs($user)->get(route('admin.attestations.combined', ['term' => $this->term->id, 'month' => 1]))->assertForbidden();
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles() as $f) {
            File::delete($f);
        }
        parent::tearDown();
    }
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Unit/Attestations/PdfConverterTest.php tests/Feature/Admin/AttestationExportTest.php`
Expected: FAIL — `PdfConverter` not found, routes undefined.

- [ ] **Step 4: Converter**

`app/Services/Attestations/PdfConverter.php`:

```php
<?php

namespace App\Services\Attestations;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/** LibreOffice headless docx → pdf (spec §6.2). One throw-away profile per call so concurrent runs never share a lock. */
final class PdfConverter
{
    private string $binary;

    public function __construct(?string $binary = null)
    {
        $this->binary = $binary ?? (string) config('services.soffice.path', 'soffice');
    }

    public function available(): bool
    {
        if (str_contains($this->binary, '/')) {
            return is_executable($this->binary);
        }

        return (new ExecutableFinder)->find($this->binary) !== null;
    }

    /** @return string path of the PDF, next to the input file */
    public function convert(string $docxPath): string
    {
        if (! $this->available()) {
            throw new RuntimeException("LibreOffice binary not available: {$this->binary}");
        }
        $dir = dirname($docxPath);
        $profile = $dir.'/lo-profile-'.Str::random(12);
        File::ensureDirectoryExists($profile);
        try {
            $process = new Process([$this->binary, '--headless', '--norestore', '-env:UserInstallation=file://'.$profile,
                '--convert-to', 'pdf', '--outdir', $dir, $docxPath]);
            $process->setTimeout(90);
            $process->run();
            $pdf = substr($docxPath, 0, -5).'.pdf';
            if (! $process->isSuccessful() || ! is_file($pdf)) {
                throw new RuntimeException('PDF conversion failed: '.trim($process->getErrorOutput().' '.$process->getOutput()));
            }

            return $pdf;
        } finally {
            File::deleteDirectory($profile);
        }
    }
}
```

- [ ] **Step 5: Controller methods, routes, views, lang**

Add to `AttestationController` (imports `App\Services\Attestations\AttestationDocument`, `App\Services\Attestations\PdfConverter`, `Symfony\Component\HttpFoundation\BinaryFileResponse`):

```php
    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public function download(Request $request, Attestation $attestation, AttestationDocument $doc, PdfConverter $pdf): BinaryFileResponse|RedirectResponse
    {
        $this->authorize('export', $attestation);
        $format = (string) $request->query('format', 'docx');
        abort_unless(in_array($format, ['docx', 'pdf'], true), 404);
        $name = "kh3-{$attestation->application_id}-{$attestation->year}-{$attestation->month}";
        $docx = $doc->docx($attestation);
        if ($format === 'docx') {
            $this->service->markExported($attestation, $request->user(), 'docx');

            return response()->download($docx, "$name.docx", ['Content-Type' => self::DOCX_MIME])->deleteFileAfterSend(true);
        }
        try {
            $file = $pdf->convert($docx);
        } catch (\RuntimeException $e) {
            report($e);
            @unlink($docx);

            return back()->withErrors(['export' => __('app.attestations.pdf_unavailable')]);
        }
        @unlink($docx);
        $this->service->markExported($attestation, $request->user(), 'pdf');

        return response()->download($file, "$name.pdf", ['Content-Type' => 'application/pdf'])->deleteFileAfterSend(true);
    }

    public function combined(Request $request, AttestationDocument $doc, PdfConverter $pdf): BinaryFileResponse|RedirectResponse
    {
        $this->authorize('exportAny', Attestation::class);
        $data = $request->validate(['term' => ['required', 'integer', 'exists:terms,id'], 'month' => ['required', 'integer', 'min:1', 'max:12']]);
        $term = Term::findOrFail($data['term']);
        $month = $this->service->resolveMonth($term, (int) $data['month']);
        abort_if($month === null, 404);
        $attestations = Attestation::whereIn('application_id', $this->service->listed($term)->pluck('id'))
            ->where(['year' => $month['year'], 'month' => $month['month']])->with('weeks', 'application.instructor', 'application.term')->get();
        if ($attestations->isEmpty()) {
            return back()->withErrors(['export' => __('app.attestations.none_for_month')]);
        }
        $docx = $doc->combinedDocx($attestations);
        try {
            $file = $pdf->convert($docx);
        } catch (\RuntimeException $e) {
            report($e);
            @unlink($docx);

            return back()->withErrors(['export' => __('app.attestations.pdf_unavailable')]);
        }
        @unlink($docx);
        foreach ($attestations as $a) {
            $this->service->markExported($a, $request->user(), 'combined_pdf');
        }

        return response()->download($file, "kh3-{$term->id}-{$month['year']}-{$month['month']}.pdf", ['Content-Type' => 'application/pdf'])->deleteFileAfterSend(true);
    }
```

Routes — `combined` must be declared **before** `attestations/{attestation}`:

```php
    Route::get('attestations/combined', [AttestationController::class, 'combined'])->name('attestations.combined');
    Route::get('attestations/{attestation}/download', [AttestationController::class, 'download'])->name('attestations.download');
```

Views: delete the three `@if (Route::has(...))` / matching `@endif` guards in `index.blade.php` and `show.blade.php` (keep their contents).

Lang (append, both files): ar `'pdf_unavailable' => 'تعذر إنشاء ملف PDF على الخادم؛ يمكن تنزيل ملف Word بدلا منه.',` / en `'pdf_unavailable' => 'The server could not produce the PDF; download the Word file instead.',`.

- [ ] **Step 6: Run the tests**

Run: `php artisan test --compact tests/Unit/Attestations/PdfConverterTest.php tests/Feature/Admin/AttestationExportTest.php tests/Feature/Admin/AttestationsTest.php`
Expected: PASS (the real-LibreOffice test is skipped on a Mac without `soffice`; with it installed it must pass). Whole suite: PASS.

- [ ] **Step 7: Commit**

```bash
chmod +x tests/Fixtures/fake-soffice.sh
vendor/bin/pint app config tests
git add app config routes resources lang tests .env.example deploy/.env.production.example
git commit -m "feat(attestations): PDF conversion, downloads and combined monthly PDF"
```

---

### Task 7: Dashboard alerts, application card link, docs and deploy notes

**Files:**
- Modify: `app/Http/Controllers/Admin/DashboardController.php`, `resources/views/admin/applications/show.blade.php` (link to the instructor's attestations in the sections card), `deploy/deploy.sh`, `deploy/DEPLOY.md`, `CLAUDE.md`, `PROGRESS.md`, `lang/ar/app.php`, `lang/en/app.php`
- Test: `tests/Feature/Admin/AttestationsTest.php` (add dashboard tests)

**Interfaces:**
- Consumes: `AttestationService::listed()`, `Term::months()`.
- Produces: dashboard alerts `app.attestations.alert_missing` / `app.attestations.alert_unexported` linking to `admin.attestations.index` with `term` and `month` (index).

- [ ] **Step 1: Write the failing tests** (append to `AttestationsTest`)

```php
    public function test_dashboard_alerts_missing_for_started_month_and_unexported_for_finished_month(): void
    {
        $this->travelTo('2026-07-10');
        Attestation::factory()->for($this->assigned)->create(['year' => 2026, 'month' => 6]);   // generated, June ended → unexported

        $r = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();

        $r->assertSee(__('app.attestations.alert_unexported', ['n' => 1, 'month' => 'الشهر الأول/ يونيو']));
        $r->assertSee(__('app.attestations.alert_missing', ['n' => 1, 'month' => 'الشهر الثاني/ يوليو']));
        $r->assertDontSee(__('app.attestations.alert_missing', ['n' => 1, 'month' => 'الشهر الأول/ يونيو']));
        $r->assertSee(route('admin.attestations.index', ['term' => $this->term->id, 'month' => 2]));
    }

    public function test_dashboard_has_no_attestation_alerts_before_the_term_starts(): void
    {
        $this->travelTo('2026-05-01');

        $r = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();

        $r->assertDontSee('مزاولة');
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Admin/AttestationsTest.php --filter dashboard`
Expected: FAIL — alerts absent.

- [ ] **Step 3: Dashboard alerts**

In `DashboardController::index()`, inject `AttestationService $attestations` (constructor) and add inside `if ($term) { … }` after the existing two loops (imports `App\Models\Attestation`, `Carbon\Carbon`):

```php
            $listedIds = $attestations->listed($term)->pluck('id');
            if ($listedIds->isNotEmpty()) {
                $today = Carbon::today();
                foreach ($term->months() as $m) {
                    $start = Carbon::create($m['year'], $m['month'], 1);
                    $url = route('admin.attestations.index', ['term' => $term->id, 'month' => $m['index']]);
                    $existing = Attestation::whereIn('application_id', $listedIds)->where(['year' => $m['year'], 'month' => $m['month']]);
                    if ($start->lte($today)) {
                        $missing = $listedIds->count() - (clone $existing)->count();
                        if ($missing > 0) {
                            $alerts->push(['text' => __('app.attestations.alert_missing', ['n' => $missing, 'month' => $m['label']]), 'url' => $url]);
                        }
                    }
                    if ($start->copy()->endOfMonth()->lt($today)) {
                        $pending = (clone $existing)->where('status', Attestation::STATUS_GENERATED)->count();
                        if ($pending > 0) {
                            $alerts->push(['text' => __('app.attestations.alert_unexported', ['n' => $pending, 'month' => $m['label']]), 'url' => $url]);
                        }
                    }
                }
            }
```

Lang (append, both files): ar `'alert_missing' => ':n مزاولة غير مولدة لشهر :month.', 'alert_unexported' => ':n مزاولة مولدة وغير مصدرة لشهر :month.',` / en `'alert_missing' => ':n attestation(s) not generated for :month.', 'alert_unexported' => ':n attestation(s) generated but not exported for :month.',`.

In `resources/views/admin/applications/show.blade.php`, inside the "Assigned sections" card heading row, add for approved applications with sections a link:

```blade
    @if ($application->status === \App\Models\Application::STATUS_APPROVED && $sections->isNotEmpty())
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.attestations.index', ['term' => $application->term_id]) }}">{{ __('app.attestations.title') }}</a>
    @endif
```

- [ ] **Step 4: Deploy and docs**

- `deploy/deploy.sh`: extend the `mkdir -p storage/app/private/applications storage/app/private/generated` line with ` storage/app/private/generated/tmp`.
- `deploy/DEPLOY.md`: in "Routine per-term setup" add the monthly step ("each month: /admin/attestations → توليد الناقص → review → PDF / combined PDF; exported forms are locked, unlock to edit"); in "After the first deploy" add: "Milestone 3 needs LibreOffice (`soffice`, present: 7.3) and an Arabic font (Amiri present); after deploying, download one PDF and check the Arabic renders and the table fits one page — if a font substitution looks wrong, `apt install fonts-sil-scheherazade fonts-kacst` and retry."
- `CLAUDE.md` status header: milestone 3 (monthly (خ-3) attestation: generator, admin screens at `/admin/attestations`, Word/PDF and combined PDF via LibreOffice) implemented on branch `milestone-3-attestation`; deploy pending; note the template at `resources/forms/kh3-template.docx` rebuilt by `scripts/build-kh3-template.py` from the official blank form.
- `PROGRESS.md`: status + log line for 2026-09-30 (milestone 3 implemented, Tasks 1–7), "Next": deploy, browser check of a PDF, milestone 4 (term close + on-file logic).

- [ ] **Step 5: Run the whole suite, lang parity and Pint**

Run: `php artisan test --compact` → PASS.
Run the parity check used in milestones 1–2:

```bash
php -r '$a=include "lang/ar/app.php"; $e=include "lang/en/app.php"; $f=function($x,$p="") use (&$f){$o=[];foreach($x as $k=>$v){$o=array_merge($o,is_array($v)?$f($v,"$p$k."):["$p$k"]);}return $o;}; $da=array_diff($f($a),$f($e)); $de=array_diff($f($e),$f($a)); echo count($f($a))," ar / ",count($f($e))," en; missing in en: ",implode(",",$da),"; missing in ar: ",implode(",",$de),"\n";'
grep -nP '[\x{064B}-\x{0652}]' lang/ar/app.php resources/views -r || echo "no tashkeel"
vendor/bin/pint --test
```

Expected: identical key counts, no differences, no tashkeel, Pint clean.

- [ ] **Step 6: Commit**

```bash
git add app resources lang deploy CLAUDE.md PROGRESS.md tests
git commit -m "feat(attestations): dashboard alerts, docs and deploy notes for milestone 3"
```

---

## Self-review notes (done while writing)

- Spec coverage: §3 → Task 1; §4 (incl. the worked example and every listed edge case) → Task 2; §5.1 → Task 3; §5.2 → Task 4; §5.3 → Task 7; §6.1–6.2 → Tasks 5–6; §6.3 → Task 7; §7 → Tasks 3, 4, 6 (policy, audits, closed-term rules, temp-file cleanup); §8 → the tests named per task; §9 order followed.
- Deviation from the spec, ruled here: the category text lives at `app.attestations.category` (one lang block for the milestone) instead of `app.kh3.category`.
- Type consistency: `AttestationService::listed()/resolveMonth()/generateMissing()/update()/regenerate()/unlock()/markExported()` signatures are identical in Tasks 3, 4, 6, 7; `AttestationDocument::docx()/combinedDocx()` in Tasks 5 and 6; `PdfConverter::available()/convert()` in Task 6; `Section::hoursForForm()` in Tasks 1, 4, 5.
- Review Focus pins: #1, #2 → Task 2 tests; #3 → Task 4 `test_save_rejects_comma_decimals…` and the `1.333 → 80` assertion; #4 → Task 6 failure/cleanup tests; #5 → Task 3 `test_generate_missing_skips_instructor_whose_assignments_were_removed…`.

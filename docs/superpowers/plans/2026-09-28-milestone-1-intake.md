# Milestone 1 — Intake Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Applicants register online, fill their profile, upload documents against the derived official checklist and submit; the admin reviews, accepts/rejects documents, approves, and prints the official Check List.

**Architecture:** One Laravel 12 monolith, server-rendered Blade, Arabic RTL. Thin controllers, Form Requests for validation, Services for workflow (`ChecklistResolver`, `ApplicationWorkflow`, `ChecklistDocument`), Policies for scoping, one `audit_log` table. Uploads live on the private `local` disk and are served only through policy-checked routes.

**Tech Stack:** PHP ≥ 8.2, Laravel 12, MySQL (prod) / SQLite in-memory (tests), PHPUnit 11, Bootstrap 5.3.3 RTL via CDN (no build step), `laravel-lang/common` (Arabic validation messages), `phpoffice/phpword` (Check List .docx), Cloudflare Turnstile.

**Spec:** `docs/superpowers/specs/2026-09-28-parttime-system-design.md` (sections 1–4, 6, 7 and milestone 1 of section 8). Milestones 2 and 3 (sections, assignments, attestations) are separate plans.

## Global Constraints

- Laravel 12, PHP `^8.2`, MySQL in production, SQLite `:memory:` in tests (phpunit.xml).
- Bootstrap 5.3.3 RTL from jsdelivr, Blade + minimal vanilla JS, **no build-step frontend**.
- **Arabic-first, RTL.** All UI strings come from `lang/ar/app.php` (English fallback in `lang/en/app.php`). No hard-coded UI strings in views or controllers. Formal, **undiacritized** Arabic (no tashkeel).
- Public Arabic name: `نظام المنتدبين — قسم تكنولوجيا الهندسة الكهربائية`. English: `Part-time Instructors System — Electrical Engineering Technology Department`.
- Roles: `admin`, `instructor`, stored on `users.role`. Enforced by policies + `role:` middleware, never by hiding links.
- Sensitive columns (`civil_id`, `iban`, `basic_salary`, `total_salary`) use the `encrypted` cast; `civil_id_hash` is an HMAC-SHA256 with the app key for uniqueness/lookup; masked on screen as first 3 + `*****` + last 3; full reveal only by admin via a POST that writes to `audit_log`.
- Uploads: PDF, JPG, PNG, DOCX only; max 10 MB; ZIP rejected with a message; stored on disk `local` under `applications/{id}/` with random names.
- Turnstile server-side verification on registration and login; email verification required before uploading.
- Rate limits: login 5/min per email+IP; registration 3/hour per IP.
- Deploy target: `root@alsharidah.shop`, `/srv/www/parttime.q8ee.com/app`, Apache vhost, behind Cloudflare. Health at `/up`.
- Commit after every task; commit messages end with `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.
- Never copy anything from `../part-time/` or the OneDrive folders into the repo.

## Review Focus

1. **A real civil ID that fails the checksum.** Expected: the applicant is told the number looks wrong but the admin can disable the checksum via `CIVIL_ID_CHECKSUM=false` without a deploy of code. Pinned in Task 6.
2. **Applicant switches employer sector or degree country after uploading.** Expected: newly required items appear as missing, newly unnecessary items stop blocking submission, and existing uploads are kept. Pinned in Task 8.
3. **Instructor guesses another application's document URL.** Expected: 403, and nothing is logged as a legitimate download. Pinned in Task 9.
4. **Admin rejects a document, applicant re-uploads, admin approves without re-opening the page.** Expected: approval is refused while any *latest* document version is not accepted; an old rejected version must not block once a newer one is accepted. Pinned in Task 11.
5. **Term is closed while an application is still in review.** Expected: no uploads, submissions or approvals are possible on that term; the applicant sees a clear "الفصل مغلق" message. Pinned in Task 8 and Task 11.

## File Structure

```
app/
  Http/Controllers/
    HomeController.php                 landing page
    Auth/RegisterController.php        register (Turnstile)
    Auth/LoginController.php           login/logout (Turnstile, throttle)
    Auth/VerificationController.php    email verification notice/verify/resend
    Auth/PasswordResetController.php   forgot/reset
    Instructor/ProfileController.php   instructor edits own profile
    Instructor/ApplicationController.php  start/view/submit own application
    Instructor/DocumentController.php  upload/download own documents
    Admin/DashboardController.php      "needs my attention"
    Admin/TermController.php           terms + holidays CRUD
    Admin/ApplicationController.php    list/show/approve/reject/reveal/checklist print
    Admin/DocumentController.php       accept/reject/download any document
  Http/Middleware/EnsureRole.php, SetLocale.php
  Http/Requests/RegisterRequest.php, LoginRequest.php, ProfileRequest.php,
                UploadDocumentRequest.php, StoreTermRequest.php, RejectDocumentRequest.php
  Models/User.php, Instructor.php, Term.php, TermHoliday.php, Application.php,
         ChecklistItem.php, Document.php, AuditLog.php
  Policies/ApplicationPolicy.php, DocumentPolicy.php
  Rules/Turnstile.php, KuwaitCivilId.php, Iban.php
  Services/TurnstileVerifier.php, ChecklistResolver.php, ApplicationWorkflow.php,
           DocumentStore.php, ChecklistDocument.php
  Mail/ApplicationSubmitted.php, DocumentsRejected.php, ApplicationApproved.php,
       ApplicationRejected.php, VerifyEmailMail.php, ResetPasswordMail.php
  Notifications/VerifyEmailNotification.php, ResetPasswordNotification.php
  Console/Commands/CreateAdmin.php
  Support/helpers.php                  format_date(), mask()
database/migrations/…                  one per table, listed in tasks
database/seeders/ChecklistItemSeeder.php
lang/ar/app.php, lang/en/app.php
resources/views/layouts/app.blade.php, emails/_shell.blade.php, auth/*, instructor/*, admin/*
tests/Feature/*Test.php, tests/Unit/*Test.php
deploy/deploy.sh, deploy/DEPLOY.md, deploy/.env.production.example
```

---

### Task 1: Scaffold Laravel 12 in `dev/` with Arabic RTL layout

**Files:**
- Create: Laravel 12 skeleton (via composer) merged into `dev/`
- Create: `app/Http/Middleware/SetLocale.php`, `resources/views/layouts/app.blade.php`, `resources/views/home.blade.php`, `lang/ar/app.php`, `lang/en/app.php`, `app/Support/helpers.php`
- Modify: `bootstrap/app.php`, `config/app.php`, `phpunit.xml`, `composer.json`, `.gitignore`
- Test: `tests/Feature/HomePageTest.php`

**Interfaces:**
- Produces: layout `layouts.app` with sections `title`, `content`, stack `scripts`; helper `format_date(Carbon|string|null): string`; translation namespace `app.*`.

- [ ] **Step 1: Create the skeleton next to the repo and merge it in**

```bash
cd "/Users/malshar/Library/Mobile Documents/com~apple~CloudDocs/projects/parttime.q8ee.com"
composer create-project laravel/laravel scaffold-tmp "12.*" --no-interaction --prefer-dist
rsync -a --exclude .git --exclude .gitignore scaffold-tmp/ dev/
cat scaffold-tmp/.gitignore dev/.gitignore | sort -u > dev/.gitignore.new && mv dev/.gitignore.new dev/.gitignore
rm -rf scaffold-tmp
cd dev && composer require laravel-lang/common:^6.8 && php artisan lang:add ar && php artisan lang:update
cp .env.example .env && php artisan key:generate
```

- [ ] **Step 2: Configure locale, timezone, and test env**

In `config/app.php` set `'timezone' => 'Asia/Kuwait'`, `'locale' => 'ar'`, `'fallback_locale' => 'en'`, `'faker_locale' => 'ar_SA'`.

In `phpunit.xml`, inside `<php>`, ensure these exist (add the missing ones):

```xml
<env name="APP_ENV" value="testing"/>
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
<env name="MAIL_MAILER" value="array"/>
<env name="QUEUE_CONNECTION" value="sync"/>
<env name="SESSION_DRIVER" value="array"/>
<env name="CACHE_STORE" value="array"/>
<env name="BCRYPT_ROUNDS" value="4"/>
<env name="TURNSTILE_SITE_KEY" value="1x00000000000000000000AA"/>
<env name="TURNSTILE_SECRET" value="1x0000000000000000000000000000000AA"/>
```

In `composer.json` add `"files": ["app/Support/helpers.php"]` under `autoload`, then run `composer dump-autoload`.

- [ ] **Step 3: Write the failing test**

`tests/Feature/HomePageTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class HomePageTest extends TestCase
{
    public function test_home_renders_arabic_rtl_with_site_name(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('dir="rtl"', false);
        $response->assertSee('نظام المنتدبين');
        $response->assertSee('قسم تكنولوجيا الهندسة الكهربائية');
    }

    public function test_lang_query_switches_to_english(): void
    {
        $response = $this->get('/?lang=en');

        $response->assertOk();
        $response->assertSee('dir="ltr"', false);
        $response->assertSee('Part-time Instructors System');
    }
}
```

- [ ] **Step 4: Run test to verify it fails**

Run: `php artisan test --filter HomePageTest`
Expected: FAIL (route `/` returns the default welcome page, no `نظام المنتدبين`).

- [ ] **Step 5: Add SetLocale middleware, helpers, lang files, layout, home view**

`app/Http/Middleware/SetLocale.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->query('lang'), ['ar', 'en'], true)) {
            $request->session()->put('locale', $request->query('lang'));
        }

        app()->setLocale($request->session()->get('locale', config('app.locale')));

        return $next($request);
    }
}
```

`app/Support/helpers.php`:

```php
<?php

use Illuminate\Support\Carbon;

if (! function_exists('format_date')) {
    function format_date(Carbon|string|null $date): string
    {
        if (! $date) {
            return '—';
        }
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);

        return $date->locale(app()->getLocale())->translatedFormat('d M Y');
    }
}

if (! function_exists('mask_middle')) {
    /** 287*****123 — first 3 + last 3; short strings are fully masked. */
    function mask_middle(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (mb_strlen($value) <= 6) {
            return str_repeat('*', mb_strlen($value));
        }

        return mb_substr($value, 0, 3).'*****'.mb_substr($value, -3);
    }
}
```

`lang/ar/app.php` (start; later tasks add keys to this file):

```php
<?php

return [
    'site_name' => 'نظام المنتدبين',
    'dept_name' => 'قسم تكنولوجيا الهندسة الكهربائية',
    'college_name' => 'كلية الدراسات التكنولوجية',
    'lang_toggle' => 'English',
    'home' => [
        'title' => 'بوابة المنتدبين',
        'lead' => 'التسجيل وتقديم مستندات الانتداب للتدريس في القسم.',
        'register' => 'تسجيل جديد',
        'login' => 'تسجيل الدخول',
    ],
    'common' => [
        'save' => 'حفظ',
        'cancel' => 'إلغاء',
        'back' => 'رجوع',
        'yes' => 'نعم',
        'no' => 'لا',
        'actions' => 'إجراءات',
        'page_expired' => 'انتهت صلاحية الصفحة، يرجى المحاولة مرة أخرى.',
        'saved' => 'تم الحفظ.',
    ],
];
```

`lang/en/app.php`:

```php
<?php

return [
    'site_name' => 'Part-time Instructors System',
    'dept_name' => 'Electrical Engineering Technology Department',
    'college_name' => 'College of Technological Studies',
    'lang_toggle' => 'العربية',
    'home' => [
        'title' => 'Part-time Instructors Portal',
        'lead' => 'Register and submit your secondment documents for teaching in the department.',
        'register' => 'Register',
        'login' => 'Log in',
    ],
    'common' => [
        'save' => 'Save', 'cancel' => 'Cancel', 'back' => 'Back', 'yes' => 'Yes', 'no' => 'No',
        'actions' => 'Actions', 'page_expired' => 'The page expired, please try again.', 'saved' => 'Saved.',
    ],
];
```

`resources/views/layouts/app.blade.php`:

```blade
<!DOCTYPE html>
@php($rtl = app()->getLocale() === 'ar')
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', __('app.site_name')) — {{ __('app.dept_name') }}</title>
    @if ($rtl)
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet"
              integrity="sha384-dpuaG1suU0eT09tx5plTaGMLBsfDLzUCCUXOY2j/LSvXYuG6Bqs43ALlhIqAJVRb" crossorigin="anonymous">
    @else
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
              integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    @endif
    <style>
        :root { --eet-primary: #1d4e89; --eet-accent: #f2a900; }
        body { font-family: {{ $rtl ? "'Segoe UI', Tahoma, 'Noto Kufi Arabic', sans-serif" : "'Segoe UI', Tahoma, sans-serif" }}; background: #f6f8fb; }
        .navbar-eet { background: var(--eet-primary); }
        .btn-eet { background: var(--eet-primary); color: #fff; }
        .btn-eet:hover { background: #163c6a; color: #fff; }
        footer { color: #6c757d; font-size: .875rem; }
    </style>
    @stack('head')
</head>
<body>
<nav class="navbar navbar-eet navbar-dark">
    <div class="container py-1">
        <a class="navbar-brand" href="{{ route('home') }}">
            <span class="fw-bold">{{ __('app.site_name') }}</span>
            <span class="d-none d-md-inline small opacity-75">— {{ __('app.dept_name') }}</span>
        </a>
        <div class="d-flex gap-2">
            @yield('nav')
            <a class="btn btn-outline-light btn-sm" href="{{ request()->fullUrlWithQuery(['lang' => $rtl ? 'en' : 'ar']) }}">{{ __('app.lang_toggle') }}</a>
        </div>
    </div>
</nav>
<main class="container py-4">
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif
    @yield('content')
</main>
<footer class="container pb-4 text-center"><hr><div>{{ __('app.dept_name') }} — {{ __('app.college_name') }}</div></footer>
@stack('scripts')
</body>
</html>
```

`resources/views/home.blade.php`:

```blade
@extends('layouts.app')
@section('content')
<div class="row justify-content-center"><div class="col-lg-8 text-center">
    <h1 class="h3 mb-3">{{ __('app.home.title') }}</h1>
    <p class="lead">{{ __('app.home.lead') }}</p>
    <a href="{{ route('register') }}" class="btn btn-eet btn-lg m-1">{{ __('app.home.register') }}</a>
    <a href="{{ route('login') }}" class="btn btn-outline-secondary btn-lg m-1">{{ __('app.home.login') }}</a>
</div></div>
@endsection
```

`routes/web.php` (replace file):

```php
<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');
// Temporary named routes so the home view renders; replaced in Task 4.
Route::view('/register', 'home')->name('register');
Route::view('/login', 'home')->name('login');
```

`bootstrap/app.php` — inside `withMiddleware`:

```php
$middleware->web(append: [\App\Http\Middleware\SetLocale::class]);
```

and inside `withExceptions`:

```php
$exceptions->render(function (\Illuminate\Session\TokenMismatchException $e, \Illuminate\Http\Request $request) {
    return redirect()->back()
        ->withInput($request->except(['_token', 'password', 'password_confirmation', 'civil_id', 'iban']))
        ->withErrors(['page_expired' => __('app.common.page_expired')]);
});
```

Delete `resources/views/welcome.blade.php`.

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter HomePageTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add -A && git commit -m "feat: scaffold Laravel 12 with Arabic RTL layout and locale switch

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 2: Users with roles, audit log, role middleware, admin creation command

**Files:**
- Create: `database/migrations/0001_01_01_000001_add_role_to_users_table.php` (name it with today's timestamp), `database/migrations/…_create_audit_log_table.php`, `app/Models/AuditLog.php`, `app/Http/Middleware/EnsureRole.php`, `app/Console/Commands/CreateAdmin.php`
- Modify: `app/Models/User.php`, `database/factories/UserFactory.php`, `bootstrap/app.php`
- Test: `tests/Feature/RolesTest.php`

**Interfaces:**
- Produces: `User::ROLE_ADMIN = 'admin'`, `User::ROLE_INSTRUCTOR = 'instructor'`, `User::isAdmin(): bool`, `User::isInstructor(): bool`; `AuditLog::record(?int $userId, string $action, ?Model $subject = null, ?string $ip = null): AuditLog`; middleware alias `role`; factory states `User::factory()->admin()` and `->instructor()`; artisan `app:create-admin {email} {name}` (prompts for password).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/RolesTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware(['web', 'auth', 'role:admin'])->get('/_admin-only', fn () => 'ok');
    }

    public function test_admin_passes_role_middleware(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/_admin-only')->assertOk();
    }

    public function test_instructor_gets_403_on_admin_route(): void
    {
        $this->actingAs(User::factory()->instructor()->create())->get('/_admin-only')->assertForbidden();
    }

    public function test_audit_log_records_action_with_subject(): void
    {
        $user = User::factory()->admin()->create();
        $log = AuditLog::record($user->id, 'test_action', $user, '1.2.3.4');

        $this->assertDatabaseHas('audit_log', [
            'id' => $log->id, 'user_id' => $user->id, 'action' => 'test_action',
            'subject_type' => $user->getMorphClass(), 'subject_id' => $user->id, 'ip' => '1.2.3.4',
        ]);
    }

    public function test_create_admin_command_creates_admin_user(): void
    {
        $this->artisan('app:create-admin', ['email' => 'admin@example.com', 'name' => 'Admin'])
            ->expectsQuestion('Password', 'secret-password-123')
            ->assertExitCode(0);

        $this->assertDatabaseHas('users', ['email' => 'admin@example.com', 'role' => 'admin']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter RolesTest`
Expected: FAIL (`role` middleware alias unknown, factory states missing, table `audit_log` missing).

- [ ] **Step 3: Migrations, model changes, middleware, command**

Migration `…_add_role_to_users_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('instructor')->index()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('role'));
    }
};
```

Migration `…_create_audit_log_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 60)->index();
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
```

`app/Models/AuditLog.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'audit_log';

    protected $fillable = ['user_id', 'action', 'subject_type', 'subject_id', 'ip'];

    public static function record(?int $userId, string $action, ?Model $subject = null, ?string $ip = null): self
    {
        return self::create([
            'user_id' => $userId,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'ip' => $ip ?? request()?->ip(),
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

`app/Models/User.php` — replace with:

```php
<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_INSTRUCTOR = 'instructor';

    protected $fillable = ['name', 'email', 'password', 'role'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed'];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isInstructor(): bool
    {
        return $this->role === self::ROLE_INSTRUCTOR;
    }

    public function instructor(): HasOne
    {
        return $this->hasOne(Instructor::class);
    }
}
```

(`Instructor` is created in Task 6; the relation method is harmless until then.)

`database/factories/UserFactory.php` — add states after `unverified()`:

```php
public function admin(): static
{
    return $this->state(fn () => ['role' => User::ROLE_ADMIN]);
}

public function instructor(): static
{
    return $this->state(fn () => ['role' => User::ROLE_INSTRUCTOR]);
}
```

`app/Http/Middleware/EnsureRole.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Usage: ->middleware('role:admin') or 'role:admin,instructor'. */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        abort_unless($request->user() && in_array($request->user()->role, $roles, true), 403);

        return $next($request);
    }
}
```

`bootstrap/app.php` inside `withMiddleware` add:

```php
$middleware->alias(['role' => \App\Http\Middleware\EnsureRole::class]);
$middleware->redirectGuestsTo(fn () => route('login'));
$middleware->redirectUsersTo(fn ($request) => $request->user()->isAdmin() ? route('admin.dashboard') : route('instructor.home'));
```

(`admin.dashboard` and `instructor.home` routes are defined in Tasks 11 and 8; until then `redirectUsersTo` is only evaluated when a logged-in user hits a guest route.)

`app/Console/Commands/CreateAdmin.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin {email} {name}';

    protected $description = 'Create (or promote) an admin user';

    public function handle(): int
    {
        $password = $this->secret('Password');
        if (strlen((string) $password) < 12) {
            $this->error('Password must be at least 12 characters.');

            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => strtolower($this->argument('email'))],
            ['name' => $this->argument('name'), 'password' => $password, 'role' => User::ROLE_ADMIN, 'email_verified_at' => now()],
        );

        $this->info("Admin ready: {$user->email}");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter RolesTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: users with roles, audit log, role middleware, create-admin command

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 3: Cloudflare Turnstile verifier and validation rule

**Files:**
- Create: `app/Services/TurnstileVerifier.php`, `app/Rules/Turnstile.php`, `resources/views/components/turnstile.blade.php`
- Modify: `config/services.php`, `.env.example`, `tests/TestCase.php`
- Test: `tests/Unit/TurnstileRuleTest.php`

**Interfaces:**
- Produces: `TurnstileVerifier::verify(?string $token, ?string $ip): bool`; rule `new Turnstile()` applied to request field `cf-turnstile-response`; Blade component `<x-turnstile />` rendering the widget; `Tests\TestCase::fakeTurnstile(bool $success = true): void`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/TurnstileRuleTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Rules\Turnstile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class TurnstileRuleTest extends TestCase
{
    public function test_passes_when_cloudflare_says_success(): void
    {
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

        $v = Validator::make(['cf-turnstile-response' => 'tok'], ['cf-turnstile-response' => ['required', new Turnstile]]);

        $this->assertTrue($v->passes());
        Http::assertSent(fn ($r) => $r['secret'] === config('services.turnstile.secret') && $r['response'] === 'tok');
    }

    public function test_fails_when_cloudflare_says_failure(): void
    {
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);

        $v = Validator::make(['cf-turnstile-response' => 'bad'], ['cf-turnstile-response' => ['required', new Turnstile]]);

        $this->assertFalse($v->passes());
        $this->assertSame(__('app.auth.turnstile_failed'), $v->errors()->first('cf-turnstile-response'));
    }

    public function test_fails_when_cloudflare_is_unreachable(): void
    {
        Http::fake(['challenges.cloudflare.com/*' => Http::response('', 500)]);

        $v = Validator::make(['cf-turnstile-response' => 'tok'], ['cf-turnstile-response' => ['required', new Turnstile]]);

        $this->assertFalse($v->passes());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter TurnstileRuleTest`
Expected: FAIL (class `App\Rules\Turnstile` not found).

- [ ] **Step 3: Implement config, verifier, rule, component, test helper**

`config/services.php` — add:

```php
'turnstile' => [
    'site_key' => env('TURNSTILE_SITE_KEY'),
    'secret' => env('TURNSTILE_SECRET'),
],
```

`.env.example` — add `TURNSTILE_SITE_KEY=` and `TURNSTILE_SECRET=`.

`app/Services/TurnstileVerifier.php`:

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

class TurnstileVerifier
{
    private const ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function verify(?string $token, ?string $ip): bool
    {
        if (! $token) {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(5)->post(self::ENDPOINT, [
                'secret' => config('services.turnstile.secret'),
                'response' => $token,
                'remoteip' => $ip,
            ]);

            return $response->ok() && $response->json('success') === true;
        } catch (Throwable) {
            return false;
        }
    }
}
```

`app/Rules/Turnstile.php`:

```php
<?php

namespace App\Rules;

use App\Services\TurnstileVerifier;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Turnstile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! app(TurnstileVerifier::class)->verify(is_string($value) ? $value : null, request()?->ip())) {
            $fail(__('app.auth.turnstile_failed'));
        }
    }
}
```

`resources/views/components/turnstile.blade.php`:

```blade
<div class="cf-turnstile my-3" data-sitekey="{{ config('services.turnstile.site_key') }}" data-language="{{ app()->getLocale() }}"></div>
@once
    @push('scripts')
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endpush
@endonce
```

`lang/ar/app.php` — add key group (merge into the array):

```php
'auth' => [
    'turnstile_failed' => 'تعذر التحقق من أنك لست روبوتا، يرجى إعادة المحاولة.',
],
```

`lang/en/app.php`:

```php
'auth' => ['turnstile_failed' => 'Human verification failed, please try again.'],
```

`tests/TestCase.php`:

```php
<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function fakeTurnstile(bool $success = true): void
    {
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => $success])]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter TurnstileRuleTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: Cloudflare Turnstile verifier, validation rule and widget component

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 4: Registration, login, email verification, password reset (Arabic mail)

**Files:**
- Create: `app/Http/Controllers/Auth/RegisterController.php`, `Auth/LoginController.php`, `Auth/VerificationController.php`, `Auth/PasswordResetController.php`, `app/Http/Requests/RegisterRequest.php`, `app/Http/Requests/LoginRequest.php`, `app/Notifications/VerifyEmailNotification.php`, `app/Notifications/ResetPasswordNotification.php`, `app/Mail/VerifyEmailMail.php`, `app/Mail/ResetPasswordMail.php`, `resources/views/emails/_shell.blade.php`, `resources/views/emails/verify-email.blade.php`, `resources/views/emails/reset-password.blade.php`, `resources/views/auth/register.blade.php`, `auth/login.blade.php`, `auth/verify.blade.php`, `auth/forgot.blade.php`, `auth/reset.blade.php`
- Modify: `routes/web.php`, `app/Models/User.php`, `app/Providers/AppServiceProvider.php`, `lang/ar/app.php`, `lang/en/app.php`
- Test: `tests/Feature/AuthTest.php`

**Interfaces:**
- Produces: routes `register`, `register.store`, `login`, `login.attempt`, `logout`, `verification.notice`, `verification.verify`, `verification.send`, `password.request`, `password.email`, `password.reset`, `password.update`; rate limiters `login` and `register`; a registered user has `role = instructor` and is redirected to `verification.notice` until verified.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/AuthTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Mail\VerifyEmailMail;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'محمد أحمد علي',
            'email' => 'm@example.com',
            'password' => 'StrongPassw0rd!!',
            'password_confirmation' => 'StrongPassw0rd!!',
            'cf-turnstile-response' => 'tok',
        ], $overrides);
    }

    public function test_register_creates_instructor_and_sends_arabic_verification_mail(): void
    {
        Mail::fake();
        $this->fakeTurnstile();

        $this->post(route('register.store'), $this->registerPayload())
            ->assertRedirect(route('verification.notice'));

        $user = User::where('email', 'm@example.com')->firstOrFail();
        $this->assertSame(User::ROLE_INSTRUCTOR, $user->role);
        $this->assertNull($user->email_verified_at);
        Mail::assertSent(VerifyEmailMail::class, fn ($m) => $m->hasTo('m@example.com'));
    }

    public function test_register_rejected_when_turnstile_fails(): void
    {
        $this->fakeTurnstile(false);

        $this->from(route('register'))->post(route('register.store'), $this->registerPayload())
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors('cf-turnstile-response');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_register_is_rate_limited_to_three_per_hour_per_ip(): void
    {
        $this->fakeTurnstile(false);
        foreach (range(1, 3) as $i) {
            $this->post(route('register.store'), $this->registerPayload(['email' => "u$i@example.com"]));
        }

        $this->post(route('register.store'), $this->registerPayload(['email' => 'u4@example.com']))
            ->assertStatus(429);
    }

    public function test_verification_link_marks_email_verified(): void
    {
        Event::fake();
        $user = User::factory()->instructor()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]);

        $this->actingAs($user)->get($url)->assertRedirect();

        $this->assertNotNull($user->fresh()->email_verified_at);
        Event::assertDispatched(Verified::class);
    }

    public function test_login_requires_turnstile_and_redirects_by_role(): void
    {
        $this->fakeTurnstile();
        $admin = User::factory()->admin()->create(['password' => 'StrongPassw0rd!!']);

        $this->post(route('login.attempt'), [
            'email' => $admin->email, 'password' => 'StrongPassw0rd!!', 'cf-turnstile-response' => 'tok',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_login_is_rate_limited_to_five_per_minute(): void
    {
        $this->fakeTurnstile();
        $user = User::factory()->instructor()->create();
        foreach (range(1, 5) as $i) {
            $this->post(route('login.attempt'), ['email' => $user->email, 'password' => 'wrong', 'cf-turnstile-response' => 'tok']);
        }

        $this->post(route('login.attempt'), ['email' => $user->email, 'password' => 'wrong', 'cf-turnstile-response' => 'tok'])
            ->assertStatus(429);
    }

    public function test_unverified_user_cannot_reach_verified_routes(): void
    {
        $user = User::factory()->instructor()->unverified()->create();

        $this->actingAs($user)->get(route('instructor.home'))->assertRedirect(route('verification.notice'));
    }
}
```

(`instructor.home` is defined in Task 8. Until then, add to `routes/web.php` a placeholder inside the verified instructor group as shown in Step 3.)

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter AuthTest`
Expected: FAIL (routes not defined).

- [ ] **Step 3: Implement**

`app/Providers/AppServiceProvider.php` — in `boot()`:

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

RateLimiter::for('login', fn (Request $r) => Limit::perMinute(5)->by(strtolower((string) $r->input('email')).'|'.$r->ip()));
RateLimiter::for('register', fn (Request $r) => Limit::perHour(3)->by($r->ip()));
```

`app/Models/User.php` — add:

```php
public function sendEmailVerificationNotification(): void
{
    $this->notify(new \App\Notifications\VerifyEmailNotification);
}

public function sendPasswordResetNotification($token): void
{
    $this->notify(new \App\Notifications\ResetPasswordNotification($token));
}
```

`app/Notifications/VerifyEmailNotification.php`:

```php
<?php

namespace App\Notifications;

use App\Mail\VerifyEmailMail;
use Illuminate\Auth\Notifications\VerifyEmail;

class VerifyEmailNotification extends VerifyEmail
{
    public function toMail($notifiable): VerifyEmailMail
    {
        return (new VerifyEmailMail($this->verificationUrl($notifiable)))->to($notifiable->email);
    }
}
```

`app/Notifications/ResetPasswordNotification.php`:

```php
<?php

namespace App\Notifications;

use App\Mail\ResetPasswordMail;
use Illuminate\Auth\Notifications\ResetPassword;

class ResetPasswordNotification extends ResetPassword
{
    public function toMail($notifiable): ResetPasswordMail
    {
        $url = route('password.reset', ['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()]);

        return (new ResetPasswordMail($url))->to($notifiable->email);
    }
}
```

`app/Mail/VerifyEmailMail.php`:

```php
<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class VerifyEmailMail extends Mailable
{
    public function __construct(public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('app.mail.verify_subject', [], 'ar'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.verify-email');
    }
}
```

`app/Mail/ResetPasswordMail.php` — identical shape with `__('app.mail.reset_subject', [], 'ar')` and view `emails.reset-password`.

`resources/views/emails/_shell.blade.php`:

```blade
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"></head>
<body style="font-family: Tahoma, 'Segoe UI', sans-serif; background:#f6f8fb; margin:0; padding:24px;">
<div style="max-width:600px; margin:0 auto; background:#fff; border:1px solid #e3e8ef; border-radius:8px; padding:24px; direction:rtl; text-align:right;">
    <h2 style="color:#1d4e89; margin-top:0;">{{ __('app.site_name', [], 'ar') }}</h2>
    @yield('body')
    <p style="color:#6c757d; font-size:.9em;">{{ __('app.mail.automated', [], 'ar') }}</p>
    <hr style="border:none; border-top:1px solid #e3e8ef; margin:20px 0;">
    <div style="color:#6c757d; font-size:.85em;">
        <div>{{ __('app.dept_name', [], 'ar') }} — {{ __('app.college_name', [], 'ar') }}</div>
        <div style="direction:ltr; text-align:left; margin-top:4px;">{{ __('app.dept_name', [], 'en') }} — {{ __('app.college_name', [], 'en') }}</div>
    </div>
</div>
</body>
</html>
```

`resources/views/emails/verify-email.blade.php`:

```blade
@extends('emails._shell')
@section('body')
    <p>{{ __('app.mail.verify_body', [], 'ar') }}</p>
    <p><a href="{{ $url }}" style="background:#1d4e89;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;">{{ __('app.mail.verify_button', [], 'ar') }}</a></p>
    <p style="font-size:.85em;color:#6c757d;">{{ $url }}</p>
@endsection
```

`resources/views/emails/reset-password.blade.php` — same with `reset_body` / `reset_button`.

`app/Http/Requests/RegisterRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Rules\Turnstile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()->uncompromised()],
            'cf-turnstile-response' => ['required', new Turnstile],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => strtolower(trim((string) $this->email))]);
    }
}
```

`app/Http/Requests/LoginRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Rules\Turnstile;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'cf-turnstile-response' => ['required', new Turnstile],
        ];
    }
}
```

`app/Http/Controllers/Auth/RegisterController.php`:

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RegisterController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
            'role' => User::ROLE_INSTRUCTOR,
        ]);

        event(new Registered($user));
        Auth::login($user);

        return redirect()->route('verification.notice');
    }
}
```

`app/Http/Controllers/Auth/LoginController.php`:

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        if (! Auth::attempt($request->only('email', 'password'), $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $request->session()->regenerate();
        $user = $request->user();

        return redirect()->intended($user->isAdmin() ? route('admin.dashboard') : route('instructor.home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
```

`app/Http/Controllers/Auth/VerificationController.php`:

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VerificationController extends Controller
{
    public function notice(Request $request): View|RedirectResponse
    {
        return $request->user()->hasVerifiedEmail() ? redirect()->route('instructor.home') : view('auth.verify');
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        $request->fulfill();

        return redirect()->route('instructor.home')->with('status', __('app.auth.verified'));
    }

    public function resend(Request $request): RedirectResponse
    {
        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', __('app.auth.verification_sent'));
    }
}
```

`app/Http/Controllers/Auth/PasswordResetController.php`:

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

class PasswordResetController extends Controller
{
    public function request(): View
    {
        return view('auth.forgot');
    }

    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink($request->only('email'));

        // Same message whether or not the email exists (no account enumeration).
        return back()->with('status', __('app.auth.reset_link_sent'));
    }

    public function reset(Request $request, string $token): View
    {
        return view('auth.reset', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()->uncompromised()],
        ]);

        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function ($user, $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', __('app.auth.password_reset_done'))
            : back()->withErrors(['email' => __($status)]);
    }
}
```

`routes/web.php` — replace the whole file:

```php
<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\VerificationController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisterController::class, 'create'])->name('register');
    Route::post('register', [RegisterController::class, 'store'])->middleware('throttle:register')->name('register.store');
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:login')->name('login.attempt');
    Route::get('forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:6,1')->name('password.email');
    Route::get('reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:6,1')->name('password.update');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('email/verify', [VerificationController::class, 'notice'])->name('verification.notice');
    Route::get('email/verify/{id}/{hash}', [VerificationController::class, 'verify'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('email/verification-notification', [VerificationController::class, 'resend'])->middleware('throttle:6,1')->name('verification.send');
});

// Instructor area (verified only). Task 8 fills this group.
Route::middleware(['auth', 'verified', 'role:instructor'])->prefix('my')->name('instructor.')->group(function () {
    Route::view('/', 'home')->name('home'); // placeholder until Task 8
});

// Admin area. Task 11 fills this group.
Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::view('/', 'home')->name('dashboard'); // placeholder until Task 11
});
```

Views (all extend `layouts.app`; every string via `__('app.auth.*')`):

`resources/views/auth/register.blade.php`:

```blade
@extends('layouts.app')
@section('title', __('app.auth.register_title'))
@section('content')
<div class="row justify-content-center"><div class="col-md-6">
    <h1 class="h4 mb-3">{{ __('app.auth.register_title') }}</h1>
    <form method="post" action="{{ route('register.store') }}">
        @csrf
        <div class="mb-3"><label class="form-label">{{ __('app.auth.name') }}</label>
            <input name="name" value="{{ old('name') }}" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">{{ __('app.auth.email') }}</label>
            <input name="email" type="email" value="{{ old('email') }}" class="form-control" required dir="ltr"></div>
        <div class="mb-3"><label class="form-label">{{ __('app.auth.password') }}</label>
            <input name="password" type="password" class="form-control" required dir="ltr">
            <div class="form-text">{{ __('app.auth.password_hint') }}</div></div>
        <div class="mb-3"><label class="form-label">{{ __('app.auth.password_confirmation') }}</label>
            <input name="password_confirmation" type="password" class="form-control" required dir="ltr"></div>
        <x-turnstile />
        <button class="btn btn-eet">{{ __('app.auth.register_button') }}</button>
        <a class="btn btn-link" href="{{ route('login') }}">{{ __('app.auth.have_account') }}</a>
    </form>
</div></div>
@endsection
```

`resources/views/auth/login.blade.php`: same shape with fields `email`, `password`, checkbox `remember`, `<x-turnstile />`, submit to `login.attempt`, links to `register` and `password.request`.

`resources/views/auth/verify.blade.php`: text `__('app.auth.verify_notice')` and a POST form to `verification.send` with button `__('app.auth.resend')`, plus a logout form.

`resources/views/auth/forgot.blade.php`: `email` field posting to `password.email`.

`resources/views/auth/reset.blade.php`: hidden `token`, `email` (prefilled), `password`, `password_confirmation`, posting to `password.update`.

`lang/ar/app.php` — add to `auth` and add `mail`:

```php
'auth' => [
    'turnstile_failed' => 'تعذر التحقق من أنك لست روبوتا، يرجى إعادة المحاولة.',
    'register_title' => 'تسجيل حساب جديد',
    'login_title' => 'تسجيل الدخول',
    'name' => 'الاسم', 'email' => 'البريد الإلكتروني', 'password' => 'كلمة المرور',
    'password_confirmation' => 'تأكيد كلمة المرور',
    'password_hint' => 'عشرة أحرف على الأقل، تتضمن حروفا وأرقاما.',
    'remember' => 'تذكرني', 'register_button' => 'تسجيل', 'login_button' => 'دخول', 'logout' => 'خروج',
    'have_account' => 'لدي حساب بالفعل', 'no_account' => 'ليس لدي حساب', 'forgot' => 'نسيت كلمة المرور؟',
    'verify_notice' => 'أرسلنا رابط تفعيل إلى بريدك الإلكتروني. يرجى فتح الرابط لإكمال التسجيل.',
    'resend' => 'إعادة إرسال رابط التفعيل', 'verification_sent' => 'تم إرسال رابط التفعيل.',
    'verified' => 'تم تفعيل البريد الإلكتروني.',
    'reset_title' => 'استعادة كلمة المرور', 'send_reset_link' => 'إرسال رابط الاستعادة',
    'reset_link_sent' => 'إذا كان البريد مسجلا لدينا فسيصلك رابط الاستعادة.',
    'new_password' => 'كلمة المرور الجديدة', 'reset_button' => 'تغيير كلمة المرور',
    'password_reset_done' => 'تم تغيير كلمة المرور، يمكنك تسجيل الدخول.',
],
'mail' => [
    'automated' => 'هذه رسالة آلية، يرجى عدم الرد عليها.',
    'verify_subject' => 'تفعيل حسابك في نظام المنتدبين',
    'verify_body' => 'شكرا لتسجيلك. يرجى الضغط على الزر أدناه لتفعيل بريدك الإلكتروني.',
    'verify_button' => 'تفعيل البريد الإلكتروني',
    'reset_subject' => 'استعادة كلمة المرور',
    'reset_body' => 'وصلنا طلب لاستعادة كلمة المرور. اضغط الزر أدناه لتعيين كلمة مرور جديدة. إذا لم تطلب ذلك فتجاهل هذه الرسالة.',
    'reset_button' => 'تعيين كلمة مرور جديدة',
],
```

Add English equivalents to `lang/en/app.php`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter AuthTest`
Expected: PASS (7 tests). Note the `uncompromised()` rule calls the HIBP API; in `AuthTest` the passwords used are unique enough, but if the suite runs offline add `Http::fake(['api.pwnedpasswords.com/*' => Http::response('')])` inside `fakeTurnstile()`.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: registration, login, email verification and password reset with Turnstile and Arabic mail

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 5: Terms and holidays (model + admin CRUD)

**Files:**
- Create: `database/migrations/…_create_terms_table.php`, `…_create_term_holidays_table.php`, `app/Models/Term.php`, `app/Models/TermHoliday.php`, `database/factories/TermFactory.php`, `app/Http/Requests/StoreTermRequest.php`, `app/Http/Controllers/Admin/TermController.php`, `resources/views/admin/layout.blade.php`, `resources/views/admin/terms/index.blade.php`, `resources/views/admin/terms/form.blade.php`
- Modify: `routes/web.php`, `lang/ar/app.php`, `lang/en/app.php`
- Test: `tests/Feature/Admin/TermTest.php`

**Interfaces:**
- Produces: `Term` with `academic_year`, `type` (`first|second|summer`), `teaching_starts_on`, `teaching_ends_on` (Carbon dates), `status` (`open|closed|archived`), `holidays()` HasMany `TermHoliday{date,name}`, scope `Term::open()`, `Term::current(): ?Term` (the single open term, latest by start date), `Term::isOpen(): bool`, `Term::label(): string` ("الفصل الأول 2026-2027"); factory state `Term::factory()->open()`; routes `admin.terms.index|create|store|edit|update|close`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Admin/TermTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TermTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_admin_creates_term_with_holidays(): void
    {
        $this->actingAs($this->admin())->post(route('admin.terms.store'), [
            'academic_year' => '2026-2027', 'type' => 'first',
            'teaching_starts_on' => '2026-09-13', 'teaching_ends_on' => '2026-12-24',
            'holidays' => "2026-09-24|اليوم الوطني\n2026-11-15|عطلة تجريبية",
        ])->assertRedirect(route('admin.terms.index'));

        $term = Term::firstOrFail();
        $this->assertSame('open', $term->status);
        $this->assertCount(2, $term->holidays);
        $this->assertSame('اليوم الوطني', $term->holidays->firstWhere('date', '2026-09-24')->name);
    }

    public function test_only_one_open_term_at_a_time(): void
    {
        Term::factory()->open()->create(['academic_year' => '2025-2026', 'type' => 'summer']);

        $this->actingAs($this->admin())->from(route('admin.terms.create'))->post(route('admin.terms.store'), [
            'academic_year' => '2026-2027', 'type' => 'first',
            'teaching_starts_on' => '2026-09-13', 'teaching_ends_on' => '2026-12-24',
        ])->assertRedirect(route('admin.terms.create'))->assertSessionHasErrors('academic_year');
    }

    public function test_duplicate_year_and_type_rejected(): void
    {
        Term::factory()->create(['academic_year' => '2026-2027', 'type' => 'first', 'status' => 'closed']);

        $this->actingAs($this->admin())->post(route('admin.terms.store'), [
            'academic_year' => '2026-2027', 'type' => 'first',
            'teaching_starts_on' => '2026-09-13', 'teaching_ends_on' => '2026-12-24',
        ])->assertSessionHasErrors('type');
    }

    public function test_close_term_and_current_helper(): void
    {
        $term = Term::factory()->open()->create();
        $this->assertTrue($term->is(Term::current()));

        $this->actingAs($this->admin())->post(route('admin.terms.close', $term))->assertRedirect();

        $this->assertSame('closed', $term->fresh()->status);
        $this->assertNull(Term::current());
    }

    public function test_instructor_cannot_manage_terms(): void
    {
        $this->actingAs(User::factory()->instructor()->create())->get(route('admin.terms.index'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter TermTest`
Expected: FAIL (routes/model missing).

- [ ] **Step 3: Implement**

Migration `…_create_terms_table.php`:

```php
Schema::create('terms', function (Blueprint $table) {
    $table->id();
    $table->string('academic_year', 9);          // 2026-2027
    $table->string('type', 10);                  // first|second|summer
    $table->date('teaching_starts_on');
    $table->date('teaching_ends_on');
    $table->string('status', 10)->default('open'); // open|closed|archived
    $table->timestamps();
    $table->unique(['academic_year', 'type']);
});
```

Migration `…_create_term_holidays_table.php`:

```php
Schema::create('term_holidays', function (Blueprint $table) {
    $table->id();
    $table->foreignId('term_id')->constrained()->cascadeOnDelete();
    $table->date('date');
    $table->string('name', 120);
    $table->timestamps();
    $table->unique(['term_id', 'date']);
});
```

`app/Models/Term.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Term extends Model
{
    use HasFactory;

    public const TYPES = ['first', 'second', 'summer'];
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = ['academic_year', 'type', 'teaching_starts_on', 'teaching_ends_on', 'status'];

    protected function casts(): array
    {
        return ['teaching_starts_on' => 'date', 'teaching_ends_on' => 'date'];
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(TermHoliday::class)->orderBy('date');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_OPEN);
    }

    public static function current(): ?self
    {
        return self::open()->orderByDesc('teaching_starts_on')->first();
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function label(): string
    {
        return __('app.terms.types.'.$this->type).' '.$this->academic_year;
    }
}
```

`app/Models/TermHoliday.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TermHoliday extends Model
{
    protected $fillable = ['date', 'name'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }
}
```

`database/factories/TermFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

class TermFactory extends Factory
{
    protected $model = Term::class;

    public function definition(): array
    {
        return [
            'academic_year' => '2026-2027', 'type' => 'first',
            'teaching_starts_on' => '2026-09-13', 'teaching_ends_on' => '2026-12-24',
            'status' => Term::STATUS_CLOSED,
        ];
    }

    public function open(): static
    {
        return $this->state(fn () => ['status' => Term::STATUS_OPEN]);
    }
}
```

`app/Http/Requests/StoreTermRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\Term;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTermRequest extends FormRequest
{
    public function rules(): array
    {
        $ignore = $this->route('term')?->id;

        return [
            'academic_year' => ['required', 'regex:/^\d{4}-\d{4}$/'],
            'type' => ['required', Rule::in(Term::TYPES),
                Rule::unique('terms')->where('academic_year', $this->academic_year)->ignore($ignore)],
            'teaching_starts_on' => ['required', 'date'],
            'teaching_ends_on' => ['required', 'date', 'after:teaching_starts_on'],
            'holidays' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $v): void
    {
        $v->after(function (Validator $v) {
            $ignore = $this->route('term')?->id;
            if (Term::open()->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->exists()) {
                $v->errors()->add('academic_year', __('app.terms.one_open_only'));
            }
            foreach ($this->parsedHolidays() as $i => $h) {
                if ($h === null) {
                    $v->errors()->add('holidays', __('app.terms.holiday_line_invalid', ['line' => $i + 1]));
                }
            }
        });
    }

    /** Each line: YYYY-MM-DD|name. Returns [['date'=>..,'name'=>..]|null, ...] */
    public function parsedHolidays(): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim((string) $this->holidays)) ?: [];

        return array_map(function (string $line) {
            $line = trim($line);
            if ($line === '') {
                return ['skip' => true];
            }
            [$date, $name] = array_pad(explode('|', $line, 2), 2, '');
            $date = trim($date);
            $name = trim($name);
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $name === '' || ! strtotime($date)) {
                return null;
            }

            return ['date' => $date, 'name' => $name];
        }, array_values(array_filter($lines, fn ($l) => trim($l) !== '')));
    }
}
```

`app/Http/Controllers/Admin/TermController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTermRequest;
use App\Models\AuditLog;
use App\Models\Term;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TermController extends Controller
{
    public function index(): View
    {
        return view('admin.terms.index', ['terms' => Term::withCount('applications')->orderByDesc('teaching_starts_on')->get()]);
    }

    public function create(): View
    {
        return view('admin.terms.form', ['term' => new Term(['status' => Term::STATUS_OPEN])]);
    }

    public function store(StoreTermRequest $request): RedirectResponse
    {
        $term = DB::transaction(function () use ($request) {
            $term = Term::create($request->safe()->except('holidays') + ['status' => Term::STATUS_OPEN]);
            $this->syncHolidays($term, $request);

            return $term;
        });
        AuditLog::record($request->user()->id, 'create_term', $term);

        return redirect()->route('admin.terms.index')->with('status', __('app.common.saved'));
    }

    public function edit(Term $term): View
    {
        return view('admin.terms.form', ['term' => $term]);
    }

    public function update(StoreTermRequest $request, Term $term): RedirectResponse
    {
        DB::transaction(function () use ($request, $term) {
            $term->update($request->safe()->except('holidays'));
            $this->syncHolidays($term, $request);
        });
        AuditLog::record($request->user()->id, 'update_term', $term);

        return redirect()->route('admin.terms.index')->with('status', __('app.common.saved'));
    }

    public function close(Term $term): RedirectResponse
    {
        $term->update(['status' => Term::STATUS_CLOSED]);
        AuditLog::record(auth()->id(), 'close_term', $term);

        return back()->with('status', __('app.terms.closed'));
    }

    private function syncHolidays(Term $term, StoreTermRequest $request): void
    {
        $term->holidays()->delete();
        foreach ($request->parsedHolidays() as $h) {
            if ($h && empty($h['skip'])) {
                $term->holidays()->create($h);
            }
        }
    }
}
```

`routes/web.php` — replace the admin placeholder group with:

```php
Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::view('/', 'home')->name('dashboard'); // replaced in Task 11
    Route::get('terms', [TermController::class, 'index'])->name('terms.index');
    Route::get('terms/create', [TermController::class, 'create'])->name('terms.create');
    Route::post('terms', [TermController::class, 'store'])->name('terms.store');
    Route::get('terms/{term}/edit', [TermController::class, 'edit'])->name('terms.edit');
    Route::put('terms/{term}', [TermController::class, 'update'])->name('terms.update');
    Route::post('terms/{term}/close', [TermController::class, 'close'])->name('terms.close');
});
```

(add `use App\Http\Controllers\Admin\TermController;`).

`resources/views/admin/layout.blade.php` — extends `layouts.app`, fills `@section('nav')` with links to `admin.dashboard`, `admin.terms.index`, `admin.applications.index` (Task 11; until then omit), and a logout form; yields `content`.

`resources/views/admin/terms/index.blade.php` — table: label, dates (`format_date`), status badge, applications count, edit link, close button (POST form, only when open). "إضافة فصل" button to `admin.terms.create`.

`resources/views/admin/terms/form.blade.php` — one form used for create and update (`$term->exists` chooses route/method): `academic_year` text, `type` select from `Term::TYPES` labelled by `__('app.terms.types.*')`, two date inputs, `holidays` textarea prefilled with `$term->holidays->map(fn ($h) => $h->date->format('Y-m-d').'|'.$h->name)->implode("\n")`, help text `__('app.terms.holidays_help')`.

`lang/ar/app.php` — add:

```php
'terms' => [
    'title' => 'الفصول الدراسية', 'add' => 'إضافة فصل', 'edit' => 'تعديل الفصل',
    'academic_year' => 'العام الدراسي', 'type' => 'الفصل',
    'types' => ['first' => 'الفصل الأول', 'second' => 'الفصل الثاني', 'summer' => 'الفصل الصيفي'],
    'teaching_starts_on' => 'بداية الدراسة', 'teaching_ends_on' => 'نهاية الدراسة',
    'holidays' => 'العطل الرسمية', 'holidays_help' => 'سطر لكل عطلة بالصيغة: 2026-09-24|اسم العطلة',
    'holiday_line_invalid' => 'السطر :line في العطل غير صحيح.',
    'status' => 'الحالة', 'statuses' => ['open' => 'مفتوح', 'closed' => 'مغلق', 'archived' => 'مؤرشف'],
    'one_open_only' => 'يوجد فصل مفتوح بالفعل، أغلقه أولا.',
    'close' => 'إغلاق الفصل', 'closed' => 'تم إغلاق الفصل.', 'applications' => 'الطلبات',
    'none_open' => 'لا يوجد فصل دراسي مفتوح للتقديم حاليا.',
],
```

(English equivalents in `lang/en/app.php`.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter TermTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: terms with holidays and admin CRUD

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 6: Instructor profile (encrypted fields, civil ID and IBAN rules)

**Files:**
- Create: `database/migrations/…_create_instructors_table.php`, `app/Models/Instructor.php`, `database/factories/InstructorFactory.php`, `app/Rules/KuwaitCivilId.php`, `app/Rules/Iban.php`, `app/Http/Requests/ProfileRequest.php`, `app/Http/Controllers/Instructor/ProfileController.php`, `resources/views/instructor/layout.blade.php`, `resources/views/instructor/profile.blade.php`
- Modify: `routes/web.php`, `config/app.php`, `.env.example`, `lang/ar/app.php`, `lang/en/app.php`
- Test: `tests/Unit/CivilIdRuleTest.php`, `tests/Unit/IbanRuleTest.php`, `tests/Feature/Instructor/ProfileTest.php`

**Interfaces:**
- Produces: `Instructor` model with fields listed in spec §3, `Instructor::hashCivilId(string): string`, `Instructor::findByCivilId(string): ?Instructor`, `maskedCivilId()`, `maskedIban()`, `isForeignDegree(): bool`, `isPrivateSector(): bool`, `isBachelorOnly(): bool`, `isComplete(): bool`; `Instructor::factory()->for(User)`; routes `instructor.profile.edit|update`; `config('app.civil_id_checksum')`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/CivilIdRuleTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Rules\KuwaitCivilId;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class CivilIdRuleTest extends TestCase
{
    /** Builds a syntactically valid ID: [2|3]YYMMDD + 4 serial + check digit. */
    public static function withCheckDigit(string $first11): string
    {
        $w = [2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 3];
        $sum = 0;
        foreach (str_split($first11) as $i => $d) {
            $sum += (int) $d * $w[$i];
        }
        $check = 11 - ($sum % 11);

        return $first11.$check;
    }

    private function passes(string $value): bool
    {
        return Validator::make(['civil_id' => $value], ['civil_id' => [new KuwaitCivilId]])->passes();
    }

    public function test_accepts_valid_checksum(): void
    {
        $this->assertTrue($this->passes(self::withCheckDigit('29001011234')));
    }

    public function test_rejects_wrong_length_or_letters(): void
    {
        $this->assertFalse($this->passes('12345'));
        $this->assertFalse($this->passes('2900101123A5'));
    }

    public function test_rejects_first_digit_not_2_or_3(): void
    {
        $this->assertFalse($this->passes(self::withCheckDigit('19001011234')));
    }

    public function test_rejects_bad_checksum(): void
    {
        $valid = self::withCheckDigit('29001011234');
        $bad = substr($valid, 0, 11).((int) substr($valid, -1) === 9 ? '0' : '9');
        $this->assertFalse($this->passes($bad));
    }

    public function test_checksum_can_be_disabled_by_config(): void
    {
        config(['app.civil_id_checksum' => false]);
        $valid = self::withCheckDigit('29001011234');
        $bad = substr($valid, 0, 11).((int) substr($valid, -1) === 9 ? '0' : '9');
        $this->assertTrue($this->passes($bad));
    }
}
```

`tests/Unit/IbanRuleTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Rules\Iban;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class IbanRuleTest extends TestCase
{
    private function passes(string $value): bool
    {
        return Validator::make(['iban' => $value], ['iban' => [new Iban]])->passes();
    }

    public function test_accepts_valid_kuwaiti_iban_with_spaces_and_lowercase(): void
    {
        // Official example IBAN from the Central Bank of Kuwait format registry.
        $this->assertTrue($this->passes('kw81 cbku 0000 0000 0000 1234 5601 01'));
    }

    public function test_rejects_bad_check_digits(): void
    {
        $this->assertFalse($this->passes('KW82CBKU0000000000001234560101'));
    }

    public function test_rejects_non_kuwaiti_or_wrong_length(): void
    {
        $this->assertFalse($this->passes('GB82WEST12345698765432'));
        $this->assertFalse($this->passes('KW81CBKU00000000000012345601'));
    }
}
```

`tests/Feature/Instructor/ProfileTest.php`:

```php
<?php

namespace Tests\Feature\Instructor;

use App\Models\Instructor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Unit\CivilIdRuleTest;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public static function payload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'محمد أحمد علي الفهد',
            'civil_id' => CivilIdRuleTest::withCheckDigit('29001011234'),
            'civil_id_expires_on' => '2028-01-01',
            'nationality' => 'كويتي',
            'mobile' => '99001122',
            'employer' => 'وزارة الكهرباء والماء',
            'employer_sector' => 'government',
            'job_title' => 'مهندس كهربائي',
            'highest_degree' => 'master',
            'degree_title' => 'ماجستير هندسة كهربائية',
            'degree_country' => 'KW',
            'degree_obtained_on' => '2018-06-01',
            'bank_name' => 'بنك الكويت الوطني',
            'bank_branch' => 'الرميثية',
            'iban' => 'KW81CBKU0000000000001234560101',
            'basic_salary' => '1200',
            'total_salary' => '1650',
        ], $overrides);
    }

    public function test_instructor_creates_profile_with_encrypted_and_hashed_fields(): void
    {
        $user = User::factory()->instructor()->create();

        $this->actingAs($user)->put(route('instructor.profile.update'), self::payload())
            ->assertRedirect(route('instructor.home'));

        $instructor = $user->fresh()->instructor;
        $this->assertSame(self::payload()['civil_id'], $instructor->civil_id);
        $this->assertSame('KW81CBKU0000000000001234560101', $instructor->iban);
        $raw = DB::table('instructors')->where('id', $instructor->id)->first();
        $this->assertNotSame(self::payload()['civil_id'], $raw->civil_id);
        $this->assertNotSame('KW81CBKU0000000000001234560101', $raw->iban);
        $this->assertSame(Instructor::hashCivilId(self::payload()['civil_id']), $raw->civil_id_hash);
        $this->assertSame('290*****'.substr(self::payload()['civil_id'], -3), $instructor->maskedCivilId());
    }

    public function test_civil_id_must_be_unique_across_instructors(): void
    {
        Instructor::factory()->for(User::factory()->instructor())->create(['civil_id' => self::payload()['civil_id']]);
        $user = User::factory()->instructor()->create();

        $this->actingAs($user)->from(route('instructor.profile.edit'))
            ->put(route('instructor.profile.update'), self::payload())
            ->assertSessionHasErrors('civil_id');
    }

    public function test_experience_years_required_for_bachelor(): void
    {
        $user = User::factory()->instructor()->create();

        $this->actingAs($user)->put(route('instructor.profile.update'), self::payload(['highest_degree' => 'bachelor']))
            ->assertSessionHasErrors('experience_years');

        $this->actingAs($user)->put(route('instructor.profile.update'), self::payload(['highest_degree' => 'bachelor', 'experience_years' => 12]))
            ->assertSessionHasNoErrors();
    }

    public function test_unverified_user_cannot_edit_profile(): void
    {
        $user = User::factory()->instructor()->unverified()->create();

        $this->actingAs($user)->get(route('instructor.profile.edit'))->assertRedirect(route('verification.notice'));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter "CivilIdRuleTest|IbanRuleTest|ProfileTest"`
Expected: FAIL (classes/routes missing).

- [ ] **Step 3: Implement**

`config/app.php` — add `'civil_id_checksum' => env('CIVIL_ID_CHECKSUM', true),`; `.env.example` — add `CIVIL_ID_CHECKSUM=true`.

`app/Rules/KuwaitCivilId.php`:

```php
<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class KuwaitCivilId implements ValidationRule
{
    private const WEIGHTS = [2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 3];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = is_string($value) ? preg_replace('/\s+/', '', $value) : '';

        if (! preg_match('/^[23]\d{11}$/', $value)) {
            $fail(__('app.profile.civil_id_format'));

            return;
        }

        if (config('app.civil_id_checksum') && ! self::checksumOk($value)) {
            $fail(__('app.profile.civil_id_checksum'));
        }
    }

    public static function checksumOk(string $id): bool
    {
        $sum = 0;
        foreach (self::WEIGHTS as $i => $w) {
            $sum += (int) $id[$i] * $w;
        }
        $check = 11 - ($sum % 11);

        return $check < 10 && $check === (int) $id[11];
    }
}
```

`app/Rules/Iban.php`:

```php
<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Kuwaiti IBAN: KW + 2 check digits + 4-letter bank code + 22 alphanumerics (30 chars), mod-97 valid. */
class Iban implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $iban = self::normalize($value);

        if (! preg_match('/^KW\d{2}[A-Z]{4}[A-Z0-9]{22}$/', $iban) || ! self::mod97Ok($iban)) {
            $fail(__('app.profile.iban_invalid'));
        }
    }

    public static function normalize(mixed $value): string
    {
        return strtoupper(preg_replace('/\s+/', '', (string) $value));
    }

    private static function mod97Ok(string $iban): bool
    {
        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $numeric = '';
        foreach (str_split($rearranged) as $ch) {
            $numeric .= ctype_alpha($ch) ? (string) (ord($ch) - 55) : $ch;
        }
        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) (($remainder.$chunk) % 97);
        }

        return $remainder === 1;
    }
}
```

Migration `…_create_instructors_table.php`:

```php
Schema::create('instructors', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
    $table->string('full_name', 150);
    $table->text('civil_id');                        // encrypted
    $table->string('civil_id_hash', 64)->unique();   // HMAC for uniqueness/lookup
    $table->date('civil_id_expires_on');
    $table->string('nationality', 60);
    $table->string('mobile', 20);
    $table->string('work_phone', 20)->nullable();
    $table->string('home_phone', 20)->nullable();
    $table->string('employer', 150);
    $table->string('employer_sector', 12);           // government|private
    $table->string('job_title', 120);
    $table->string('highest_degree', 10);            // bachelor|master|phd
    $table->string('degree_title', 150);
    $table->char('degree_country', 2);               // ISO alpha-2; KW = local
    $table->date('degree_obtained_on');
    $table->unsignedTinyInteger('experience_years')->nullable();
    $table->string('bank_name', 120);
    $table->string('bank_branch', 120)->nullable();
    $table->text('iban');                            // encrypted
    $table->text('basic_salary');                    // encrypted
    $table->text('total_salary');                    // encrypted
    $table->timestamps();
});
```

`app/Models/Instructor.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Instructor extends Model
{
    use HasFactory;

    public const SECTORS = ['government', 'private'];
    public const DEGREES = ['bachelor', 'master', 'phd'];

    protected $fillable = [
        'full_name', 'civil_id', 'civil_id_expires_on', 'nationality', 'mobile', 'work_phone', 'home_phone',
        'employer', 'employer_sector', 'job_title', 'highest_degree', 'degree_title', 'degree_country',
        'degree_obtained_on', 'experience_years', 'bank_name', 'bank_branch', 'iban', 'basic_salary', 'total_salary',
    ];

    protected $hidden = ['civil_id', 'civil_id_hash', 'iban', 'basic_salary', 'total_salary'];

    protected function casts(): array
    {
        return [
            'civil_id' => 'encrypted', 'iban' => 'encrypted', 'basic_salary' => 'encrypted', 'total_salary' => 'encrypted',
            'civil_id_expires_on' => 'date', 'degree_obtained_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Instructor $i) {
            if ($i->isDirty('civil_id')) {
                $i->civil_id_hash = self::hashCivilId($i->civil_id);
            }
        });
    }

    public static function hashCivilId(string $civilId): string
    {
        return hash_hmac('sha256', $civilId, config('app.key'));
    }

    public static function findByCivilId(string $civilId): ?self
    {
        return self::where('civil_id_hash', self::hashCivilId($civilId))->first();
    }

    public function maskedCivilId(): string
    {
        return mask_middle($this->civil_id);
    }

    public function maskedIban(): string
    {
        return mask_middle($this->iban);
    }

    public function isForeignDegree(): bool
    {
        return strtoupper($this->degree_country) !== 'KW';
    }

    public function isPrivateSector(): bool
    {
        return $this->employer_sector === 'private';
    }

    public function isBachelorOnly(): bool
    {
        return $this->highest_degree === 'bachelor';
    }

    public function civilIdExpired(): bool
    {
        return $this->civil_id_expires_on->isPast();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }
}
```

`database/factories/InstructorFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Instructor;
use Illuminate\Database\Eloquent\Factories\Factory;

class InstructorFactory extends Factory
{
    protected $model = Instructor::class;

    public function definition(): array
    {
        $serial = str_pad((string) $this->faker->unique()->numberBetween(0, 9999), 4, '0', STR_PAD_LEFT);
        $first11 = '2900101'.$serial;
        $w = [2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 3];
        $sum = 0;
        foreach (str_split($first11) as $i => $d) {
            $sum += (int) $d * $w[$i];
        }
        $check = 11 - ($sum % 11);
        if ($check >= 10) {                       // skip serials that yield an invalid check digit
            $first11 = '2900101'.str_pad((string) (((int) $serial + 1) % 10000), 4, '0', STR_PAD_LEFT);
            $sum = 0;
            foreach (str_split($first11) as $i => $d) {
                $sum += (int) $d * $w[$i];
            }
            $check = 11 - ($sum % 11);
        }

        return [
            'full_name' => $this->faker->name(),
            'civil_id' => $first11.$check,
            'civil_id_expires_on' => now()->addYears(2)->toDateString(),
            'nationality' => 'كويتي', 'mobile' => '99'.$this->faker->numerify('######'),
            'employer' => 'وزارة الكهرباء والماء', 'employer_sector' => 'government',
            'job_title' => 'مهندس', 'highest_degree' => 'master', 'degree_title' => 'ماجستير هندسة كهربائية',
            'degree_country' => 'KW', 'degree_obtained_on' => '2018-06-01',
            'bank_name' => 'بنك الكويت الوطني', 'bank_branch' => 'الرميثية',
            'iban' => 'KW81CBKU0000000000001234560101', 'basic_salary' => '1200', 'total_salary' => '1650',
        ];
    }

    public function foreignDegree(): static
    {
        return $this->state(fn () => ['degree_country' => 'GB']);
    }

    public function privateSector(): static
    {
        return $this->state(fn () => ['employer_sector' => 'private', 'employer' => 'شركة خاصة']);
    }

    public function bachelor(int $years = 12): static
    {
        return $this->state(fn () => ['highest_degree' => 'bachelor', 'experience_years' => $years]);
    }
}
```

`app/Http/Requests/ProfileRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\Instructor;
use App\Rules\Iban;
use App\Rules\KuwaitCivilId;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProfileRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:150'],
            'civil_id' => ['required', new KuwaitCivilId],
            'civil_id_expires_on' => ['required', 'date', 'after:today'],
            'nationality' => ['required', 'string', 'max:60'],
            'mobile' => ['required', 'regex:/^[569]\d{7}$/'],
            'work_phone' => ['nullable', 'regex:/^\d{8}$/'],
            'home_phone' => ['nullable', 'regex:/^\d{8}$/'],
            'employer' => ['required', 'string', 'max:150'],
            'employer_sector' => ['required', Rule::in(Instructor::SECTORS)],
            'job_title' => ['required', 'string', 'max:120'],
            'highest_degree' => ['required', Rule::in(Instructor::DEGREES)],
            'degree_title' => ['required', 'string', 'max:150'],
            'degree_country' => ['required', 'string', 'size:2', 'alpha'],
            'degree_obtained_on' => ['required', 'date', 'before_or_equal:today'],
            'experience_years' => ['nullable', 'integer', 'min:0', 'max:60', Rule::requiredIf(fn () => $this->highest_degree === 'bachelor')],
            'bank_name' => ['required', 'string', 'max:120'],
            'bank_branch' => ['nullable', 'string', 'max:120'],
            'iban' => ['required', new Iban],
            'basic_salary' => ['required', 'numeric', 'min:0', 'max:99999'],
            'total_salary' => ['required', 'numeric', 'min:0', 'max:99999', 'gte:basic_salary'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'civil_id' => preg_replace('/\s+/', '', (string) $this->civil_id),
            'iban' => Iban::normalize($this->iban),
            'degree_country' => strtoupper((string) $this->degree_country),
        ]);
    }

    public function withValidator(Validator $v): void
    {
        $v->after(function (Validator $v) {
            $existing = Instructor::findByCivilId((string) $this->civil_id);
            if ($existing && $existing->user_id !== $this->user()->id) {
                $v->errors()->add('civil_id', __('app.profile.civil_id_taken'));
            }
        });
    }
}
```

`app/Http/Controllers/Instructor/ProfileController.php`:

```php
<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProfileRequest;
use App\Models\Instructor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('instructor.profile', ['instructor' => $request->user()->instructor ?? new Instructor]);
    }

    public function update(ProfileRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();
        if ($data['highest_degree'] !== 'bachelor') {
            $data['experience_years'] = null;
        }

        $user->instructor()->updateOrCreate(['user_id' => $user->id], $data);

        return redirect()->route('instructor.home')->with('status', __('app.common.saved'));
    }
}
```

`routes/web.php` — inside the instructor group add:

```php
Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
```

`resources/views/instructor/layout.blade.php` — extends `layouts.app`; `@section('nav')` with links to `instructor.home`, `instructor.profile.edit`, and a logout POST form.

`resources/views/instructor/profile.blade.php` — one form (PUT `instructor.profile.update`) with all fields above grouped in three cards: البيانات الشخصية, جهة العمل والمؤهل, البيانات البنكية. Sensitive inputs (`civil_id`, `iban`, salaries) are prefilled from the model (the owner sees their own values). `experience_years` input sits in a `<div id="experience-wrap">` shown only when `highest_degree == bachelor` via 6 lines of vanilla JS in `@push('scripts')`. `degree_country` is a select of ISO codes with `KW` first and a short list (KW, SA, AE, BH, QA, OM, EG, JO, GB, US, CA, AU, MY, IN, PK, TR, DE, FR, OTHER→`ZZ`), labels from `__('app.countries.*')`.

`lang/ar/app.php` — add:

```php
'profile' => [
    'title' => 'البيانات الشخصية', 'personal' => 'البيانات الشخصية', 'work' => 'جهة العمل والمؤهل', 'bank' => 'البيانات البنكية',
    'full_name' => 'الاسم الثلاثي', 'civil_id' => 'الرقم المدني', 'civil_id_expires_on' => 'تاريخ انتهاء البطاقة المدنية',
    'nationality' => 'الجنسية', 'mobile' => 'النقال', 'work_phone' => 'تلفون العمل', 'home_phone' => 'تلفون المنزل',
    'employer' => 'جهة العمل', 'employer_sector' => 'قطاع جهة العمل',
    'sectors' => ['government' => 'حكومي', 'private' => 'خاص'],
    'job_title' => 'المسمى الوظيفي', 'highest_degree' => 'أعلى مؤهل',
    'degrees' => ['bachelor' => 'بكالوريوس', 'master' => 'ماجستير', 'phd' => 'دكتوراه'],
    'degree_title' => 'المؤهل العلمي', 'degree_country' => 'بلد إصدار المؤهل', 'degree_obtained_on' => 'تاريخ الحصول على المؤهل',
    'experience_years' => 'سنوات الخبرة', 'experience_hint' => 'مطلوب لحملة البكالوريوس (لا تقل عن 10 سنوات).',
    'bank_name' => 'اسم البنك', 'bank_branch' => 'الفرع', 'iban' => 'رقم الآيبان IBAN',
    'basic_salary' => 'الراتب الأساسي (د.ك)', 'total_salary' => 'الراتب الإجمالي (د.ك)',
    'civil_id_format' => 'الرقم المدني يجب أن يكون 12 رقما ويبدأ بـ 2 أو 3.',
    'civil_id_checksum' => 'الرقم المدني غير صحيح، يرجى التأكد منه.',
    'civil_id_taken' => 'هذا الرقم المدني مسجل لحساب آخر.',
    'iban_invalid' => 'رقم الآيبان غير صحيح. الصيغة: KW + 28 خانة.',
    'incomplete' => 'يرجى استكمال البيانات الشخصية أولا.',
],
'countries' => ['KW' => 'الكويت', 'SA' => 'السعودية', 'AE' => 'الإمارات', 'BH' => 'البحرين', 'QA' => 'قطر', 'OM' => 'عمان',
    'EG' => 'مصر', 'JO' => 'الأردن', 'GB' => 'المملكة المتحدة', 'US' => 'الولايات المتحدة', 'CA' => 'كندا', 'AU' => 'أستراليا',
    'MY' => 'ماليزيا', 'IN' => 'الهند', 'PK' => 'باكستان', 'TR' => 'تركيا', 'DE' => 'ألمانيا', 'FR' => 'فرنسا', 'ZZ' => 'دولة أخرى'],
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter "CivilIdRuleTest|IbanRuleTest|ProfileTest"`
Expected: PASS (12 tests). If `test_accepts_valid_kuwaiti_iban_with_spaces_and_lowercase` fails, the sample IBAN's check digits are wrong: compute them with the mod-97 routine (`98 - mod97("CBKU0000000000001234560101" + "KW00")`) and fix the test literal, not the rule.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: instructor profile with encrypted civil ID, IBAN and salary; civil ID and IBAN rules

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 7: Checklist items seeder and `ChecklistResolver`

**Files:**
- Create: `database/migrations/…_create_checklist_items_table.php`, `app/Models/ChecklistItem.php`, `database/seeders/ChecklistItemSeeder.php`, `app/Services/ChecklistResolver.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Unit/ChecklistResolverTest.php`

**Interfaces:**
- Produces: `ChecklistItem` (`code`, `label_ar`, `sort_order`, `provided_by`, `condition`, `renews_each_term`), constants `ChecklistItem::CODES` (12 codes below); `ChecklistResolver::for(Instructor $i): ChecklistPlan` where `ChecklistPlan` has `required: Collection<ChecklistItem>`, `department: Collection<ChecklistItem>`, `notApplicable: Collection<ChecklistItem>`, and `isRequired(string $code): bool`. Seeder `ChecklistItemSeeder` is idempotent (`updateOrCreate` by `code`) and runs in `DatabaseSeeder`. Tests that need items call `$this->seed(ChecklistItemSeeder::class)`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/ChecklistResolverTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\Instructor;
use App\Models\User;
use App\Services\ChecklistResolver;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChecklistResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChecklistItemSeeder::class);
    }

    private function codes($items): array
    {
        return $items->pluck('code')->values()->all();
    }

    public function test_seeds_twelve_items_idempotently(): void
    {
        $this->seed(ChecklistItemSeeder::class);
        $this->assertDatabaseCount('checklist_items', 12);
    }

    public function test_local_master_government_requires_only_unconditional_applicant_items(): void
    {
        $i = Instructor::factory()->for(User::factory()->instructor())->create();
        $plan = app(ChecklistResolver::class)->for($i);

        $this->assertSame(['civil_id', 'degree', 'salary_cert', 'iban', 'employer_approval', 'undertaking'], $this->codes($plan->required));
        $this->assertSame(['schedule', 'assignment_letter', 'attestation'], $this->codes($plan->department));
        $this->assertSame(['equivalency', 'social_insurance', 'experience'], $this->codes($plan->notApplicable));
    }

    public function test_foreign_degree_adds_equivalency(): void
    {
        $i = Instructor::factory()->foreignDegree()->for(User::factory()->instructor())->create();
        $this->assertTrue(app(ChecklistResolver::class)->for($i)->isRequired('equivalency'));
    }

    public function test_private_sector_adds_social_insurance(): void
    {
        $i = Instructor::factory()->privateSector()->for(User::factory()->instructor())->create();
        $this->assertTrue(app(ChecklistResolver::class)->for($i)->isRequired('social_insurance'));
    }

    public function test_bachelor_adds_experience_certificate(): void
    {
        $i = Instructor::factory()->bachelor()->for(User::factory()->instructor())->create();
        $plan = app(ChecklistResolver::class)->for($i);
        $this->assertTrue($plan->isRequired('experience'));
        $this->assertFalse($plan->isRequired('equivalency'));
    }

    public function test_all_three_conditions_together(): void
    {
        $i = Instructor::factory()->foreignDegree()->privateSector()->bachelor()->for(User::factory()->instructor())->create();
        $this->assertCount(9, app(ChecklistResolver::class)->for($i)->required);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter ChecklistResolverTest`
Expected: FAIL (seeder/class missing).

- [ ] **Step 3: Implement**

Migration `…_create_checklist_items_table.php`:

```php
Schema::create('checklist_items', function (Blueprint $table) {
    $table->id();
    $table->string('code', 40)->unique();
    $table->string('label_ar', 200);
    $table->string('note_ar', 200)->nullable();
    $table->unsignedTinyInteger('sort_order');
    $table->string('provided_by', 12);   // applicant|department
    $table->string('condition', 20);     // always|foreign_degree|private_sector|bachelor_only
    $table->boolean('renews_each_term')->default(false);
    $table->timestamps();
});
```

`app/Models/ChecklistItem.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChecklistItem extends Model
{
    public const CODES = ['schedule', 'assignment_letter', 'attestation', 'civil_id', 'degree', 'equivalency',
        'social_insurance', 'experience', 'salary_cert', 'iban', 'employer_approval', 'undertaking'];

    protected $fillable = ['code', 'label_ar', 'note_ar', 'sort_order', 'provided_by', 'condition', 'renews_each_term'];

    protected function casts(): array
    {
        return ['renews_each_term' => 'boolean'];
    }

    public function isDepartment(): bool
    {
        return $this->provided_by === 'department';
    }

    public function appliesTo(Instructor $i): bool
    {
        return match ($this->condition) {
            'always' => true,
            'foreign_degree' => $i->isForeignDegree(),
            'private_sector' => $i->isPrivateSector(),
            'bachelor_only' => $i->isBachelorOnly(),
            default => false,
        };
    }
}
```

`database/seeders/ChecklistItemSeeder.php` (order and wording follow `docs/forms/checklist.md`):

```php
<?php

namespace Database\Seeders;

use App\Models\ChecklistItem;
use Illuminate\Database\Seeder;

class ChecklistItemSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['schedule', 'الجدول الدراسي', null, 'department', 'always', false],
            ['assignment_letter', 'كشف التكليف للمنتدب', null, 'department', 'always', false],
            ['attestation', 'كشف المزاولة للمنتدب', null, 'department', 'always', false],
            ['civil_id', 'صورة البطاقة المدنية سارية المفعول', null, 'applicant', 'always', false],
            ['degree', 'صورة من المؤهل العلمي', null, 'applicant', 'always', false],
            ['equivalency', 'صورة من معادلة المؤهل العلمي', 'للمؤهلات الصادرة من خارج دولة الكويت', 'applicant', 'foreign_degree', false],
            ['social_insurance', 'شهادة من المؤسسة العامة للتأمينات الاجتماعية', 'للعاملين في القطاع الخاص فقط', 'applicant', 'private_sector', false],
            ['experience', 'صورة من شهادة الخبرة', 'لحملة شهادة البكالوريوس، لا تقل عن 10 سنوات', 'applicant', 'bachelor_only', false],
            ['salary_cert', 'شهادة راتب حديثة', null, 'applicant', 'always', true],
            ['iban', 'كشف الآيبان IBAN معتمد من البنك', null, 'applicant', 'always', false],
            ['employer_approval', 'موافقة جهة العمل', 'موجهة لمدير عام الهيئة ومدون بها الفصل الدراسي والعام الدراسي', 'applicant', 'always', true],
            ['undertaking', 'نموذج إقرار وتعهد', 'موقع من قبل المنتدب', 'applicant', 'always', true],
        ];

        foreach ($items as $i => [$code, $label, $note, $by, $cond, $renews]) {
            ChecklistItem::updateOrCreate(['code' => $code], [
                'label_ar' => $label, 'note_ar' => $note, 'sort_order' => $i + 1,
                'provided_by' => $by, 'condition' => $cond, 'renews_each_term' => $renews,
            ]);
        }
    }
}
```

`database/seeders/DatabaseSeeder.php` — `run()` calls `$this->call(ChecklistItemSeeder::class);` only.

`app/Services/ChecklistResolver.php`:

```php
<?php

namespace App\Services;

use App\Models\ChecklistItem;
use App\Models\Instructor;
use Illuminate\Support\Collection;

final class ChecklistPlan
{
    public function __construct(
        public readonly Collection $required,
        public readonly Collection $department,
        public readonly Collection $notApplicable,
    ) {}

    public function isRequired(string $code): bool
    {
        return $this->required->contains('code', $code);
    }
}

class ChecklistResolver
{
    public function for(Instructor $instructor): ChecklistPlan
    {
        $items = ChecklistItem::orderBy('sort_order')->get();

        return new ChecklistPlan(
            required: $items->filter(fn ($i) => ! $i->isDepartment() && $i->appliesTo($instructor))->values(),
            department: $items->filter(fn ($i) => $i->isDepartment())->values(),
            notApplicable: $items->filter(fn ($i) => ! $i->isDepartment() && ! $i->appliesTo($instructor))->values(),
        );
    }
}
```

(Two classes in one file is deliberate: `ChecklistPlan` is a value object only the resolver produces. If Pint complains, move `ChecklistPlan` to `app/Services/ChecklistPlan.php` unchanged.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter ChecklistResolverTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: seed the 12 official checklist items and derive required items per instructor

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 8: Applications (model, policy, instructor home, start application, checklist view)

**Files:**
- Create: `database/migrations/…_create_applications_table.php`, `app/Models/Application.php`, `database/factories/ApplicationFactory.php`, `app/Policies/ApplicationPolicy.php`, `app/Services/ApplicationWorkflow.php`, `app/Http/Controllers/Instructor/ApplicationController.php`, `resources/views/instructor/home.blade.php`, `resources/views/instructor/application.blade.php`
- Modify: `routes/web.php`, `app/Providers/AppServiceProvider.php`, `lang/ar/app.php`, `lang/en/app.php`
- Test: `tests/Feature/Instructor/ApplicationTest.php`

**Interfaces:**
- Produces: `Application` with `term_id`, `instructor_id`, `status` (constants `STATUS_DRAFT|SUBMITTED|UNDER_REVIEW|INCOMPLETE|APPROVED|REJECTED|WITHDRAWN`), `submitted_at`, `reviewed_at`, `decided_at`, `assignment_decision_number`, `assignment_decision_date`, `weekly_hours`, `admin_note`, `rejection_reason`; relations `term()`, `instructor()`, `documents()`, `latestDocuments()` (one per checklist item, highest version); `isEditable(): bool` (draft or incomplete, and term open); `ApplicationWorkflow::start(Instructor, Term): Application` (idempotent per term, throws `TermClosedException` when the term is not open); `ApplicationWorkflow::checklist(Application): array<code => ['item'=>ChecklistItem, 'document'=>?Document, 'state'=>'missing|pending|accepted|rejected']>`; policy `view/update` for owner, `viewAny/review` for admin; routes `instructor.home`, `instructor.applications.start`, `instructor.applications.show`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Instructor/ApplicationTest.php`:

```php
<?php

namespace Tests\Feature\Instructor;

use App\Models\Application;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChecklistItemSeeder::class);
        $this->user = User::factory()->instructor()->create();
        Instructor::factory()->for($this->user)->create();
    }

    public function test_home_without_profile_redirects_to_profile(): void
    {
        $bare = User::factory()->instructor()->create();
        $this->actingAs($bare)->get(route('instructor.home'))->assertRedirect(route('instructor.profile.edit'));
    }

    public function test_home_shows_no_open_term_message(): void
    {
        $this->actingAs($this->user)->get(route('instructor.home'))->assertOk()->assertSee(__('app.terms.none_open'));
    }

    public function test_start_creates_one_draft_per_open_term(): void
    {
        $term = Term::factory()->open()->create();

        $this->actingAs($this->user)->post(route('instructor.applications.start'))->assertRedirect();
        $this->actingAs($this->user)->post(route('instructor.applications.start'))->assertRedirect();

        $this->assertDatabaseCount('applications', 1);
        $app = Application::firstOrFail();
        $this->assertSame(Application::STATUS_DRAFT, $app->status);
        $this->assertTrue($app->term->is($term));
    }

    public function test_start_refused_when_no_open_term(): void
    {
        Term::factory()->create(['status' => 'closed']);
        $this->actingAs($this->user)->post(route('instructor.applications.start'))->assertRedirect(route('instructor.home'));
        $this->assertDatabaseCount('applications', 0);
    }

    public function test_show_lists_required_department_and_not_applicable_items(): void
    {
        $term = Term::factory()->open()->create();
        $app = Application::factory()->for($term)->for($this->user->instructor)->create();

        $r = $this->actingAs($this->user)->get(route('instructor.applications.show', $app))->assertOk();
        $r->assertSee('صورة البطاقة المدنية سارية المفعول');
        $r->assertSee(__('app.applications.department_items'));
        $r->assertSee('كشف التكليف للمنتدب');
        $r->assertSee(__('app.applications.not_applicable_items'));
        $r->assertSee('صورة من معادلة المؤهل العلمي');
    }

    public function test_other_instructor_cannot_view_application(): void
    {
        $term = Term::factory()->open()->create();
        $app = Application::factory()->for($term)->for($this->user->instructor)->create();
        $other = User::factory()->instructor()->create();
        Instructor::factory()->for($other)->create();

        $this->actingAs($other)->get(route('instructor.applications.show', $app))->assertForbidden();
    }

    public function test_changing_profile_changes_required_items_and_keeps_uploads(): void
    {
        $term = Term::factory()->open()->create();
        $app = Application::factory()->for($term)->for($this->user->instructor)->create();
        $this->user->instructor->update(['degree_country' => 'GB']);

        $checklist = app(\App\Services\ApplicationWorkflow::class)->checklist($app->fresh());
        $this->assertSame('missing', $checklist['equivalency']['state']);

        $this->user->instructor->update(['degree_country' => 'KW']);
        $this->assertArrayNotHasKey('equivalency', app(\App\Services\ApplicationWorkflow::class)->checklist($app->fresh()));
    }

    public function test_closed_term_application_is_not_editable(): void
    {
        $term = Term::factory()->create(['status' => 'closed']);
        $app = Application::factory()->for($term)->for($this->user->instructor)->create();

        $this->assertFalse($app->isEditable());
        $this->actingAs($this->user)->get(route('instructor.applications.show', $app))->assertOk()->assertSee(__('app.applications.term_closed'));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter "Instructor\\\\ApplicationTest"`
Expected: FAIL.

- [ ] **Step 3: Implement**

Migration `…_create_applications_table.php`:

```php
Schema::create('applications', function (Blueprint $table) {
    $table->id();
    $table->foreignId('term_id')->constrained()->restrictOnDelete();
    $table->foreignId('instructor_id')->constrained()->cascadeOnDelete();
    $table->string('status', 15)->default('draft')->index();
    $table->timestamp('submitted_at')->nullable();
    $table->timestamp('reviewed_at')->nullable();
    $table->timestamp('decided_at')->nullable();
    $table->string('assignment_decision_number', 40)->nullable();
    $table->date('assignment_decision_date')->nullable();
    $table->unsignedSmallInteger('weekly_hours')->default(0);
    $table->text('admin_note')->nullable();
    $table->text('rejection_reason')->nullable();
    $table->timestamps();
    $table->unique(['term_id', 'instructor_id']);
});
```

`app/Models/Application.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Application extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_INCOMPLETE = 'incomplete';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_WITHDRAWN = 'withdrawn';

    public const EDITABLE_STATUSES = [self::STATUS_DRAFT, self::STATUS_INCOMPLETE];
    public const FINAL_STATUSES = [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_WITHDRAWN];

    protected $fillable = ['term_id', 'instructor_id', 'status', 'submitted_at', 'reviewed_at', 'decided_at',
        'assignment_decision_number', 'assignment_decision_date', 'weekly_hours', 'admin_note', 'rejection_reason'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'decided_at' => 'datetime',
            'assignment_decision_date' => 'date'];
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /** Latest version per checklist item, keyed by item code. */
    public function latestDocuments(): \Illuminate\Support\Collection
    {
        return $this->documents()->with('checklistItem')->orderByDesc('version')->get()
            ->unique('checklist_item_id')->keyBy(fn (Document $d) => $d->checklistItem->code);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE_STATUSES, true) && $this->term->isOpen();
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL_STATUSES, true);
    }
}
```

`database/factories/ApplicationFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApplicationFactory extends Factory
{
    protected $model = Application::class;

    public function definition(): array
    {
        return [
            'term_id' => Term::factory()->open(),
            'instructor_id' => Instructor::factory()->for(User::factory()->instructor()),
            'status' => Application::STATUS_DRAFT,
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn () => ['status' => Application::STATUS_SUBMITTED, 'submitted_at' => now()]);
    }
}
```

`app/Policies/ApplicationPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\Application;
use App\Models\User;

class ApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Application $application): bool
    {
        return $user->isAdmin() || $this->owns($user, $application);
    }

    public function update(User $user, Application $application): bool
    {
        return $this->owns($user, $application) && $application->isEditable();
    }

    public function review(User $user, Application $application): bool
    {
        return $user->isAdmin();
    }

    private function owns(User $user, Application $application): bool
    {
        return $user->instructor && $user->instructor->id === $application->instructor_id;
    }
}
```

`app/Services/ApplicationWorkflow.php` (Task 10 and 11 add methods to this class):

```php
<?php

namespace App\Services;

use App\Exceptions\TermClosedException;
use App\Models\Application;
use App\Models\Instructor;
use App\Models\Term;

class ApplicationWorkflow
{
    public function __construct(private ChecklistResolver $resolver) {}

    public function start(Instructor $instructor, Term $term): Application
    {
        if (! $term->isOpen()) {
            throw new TermClosedException;
        }

        return Application::firstOrCreate(
            ['term_id' => $term->id, 'instructor_id' => $instructor->id],
            ['status' => Application::STATUS_DRAFT],
        );
    }

    /**
     * @return array<string, array{item: \App\Models\ChecklistItem, document: ?\App\Models\Document, state: string}>
     */
    public function checklist(Application $application): array
    {
        $plan = $this->resolver->for($application->instructor);
        $docs = $application->latestDocuments();
        $out = [];
        foreach ($plan->required as $item) {
            $doc = $docs->get($item->code);
            $out[$item->code] = ['item' => $item, 'document' => $doc, 'state' => $doc?->status ?? 'missing'];
        }

        return $out;
    }

    public function plan(Application $application): ChecklistPlan
    {
        return $this->resolver->for($application->instructor);
    }

    public function allRequiredAccepted(Application $application): bool
    {
        foreach ($this->checklist($application) as $row) {
            if ($row['state'] !== 'accepted') {
                return false;
            }
        }

        return true;
    }

    public function allRequiredUploaded(Application $application): bool
    {
        foreach ($this->checklist($application) as $row) {
            if ($row['state'] === 'missing' || $row['state'] === 'rejected') {
                return false;
            }
        }

        return true;
    }
}
```

`app/Exceptions/TermClosedException.php`:

```php
<?php

namespace App\Exceptions;

class TermClosedException extends \RuntimeException {}
```

`app/Providers/AppServiceProvider.php` — in `boot()` register policies:

```php
use Illuminate\Support\Facades\Gate;
Gate::policy(\App\Models\Application::class, \App\Policies\ApplicationPolicy::class);
Gate::policy(\App\Models\Document::class, \App\Policies\DocumentPolicy::class); // Task 9
```

(Add the `Document` line in Task 9.)

`app/Http/Controllers/Instructor/ApplicationController.php`:

```php
<?php

namespace App\Http\Controllers\Instructor;

use App\Exceptions\TermClosedException;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Term;
use App\Services\ApplicationWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApplicationController extends Controller
{
    public function __construct(private ApplicationWorkflow $workflow) {}

    public function home(Request $request): View|RedirectResponse
    {
        $instructor = $request->user()->instructor;
        if (! $instructor) {
            return redirect()->route('instructor.profile.edit')->with('status', __('app.profile.incomplete'));
        }
        $term = Term::current();
        $current = $term ? $instructor->applications()->where('term_id', $term->id)->first() : null;
        $past = $instructor->applications()->with('term')->when($current, fn ($q) => $q->whereKeyNot($current->id))
            ->orderByDesc('created_at')->get();

        return view('instructor.home', compact('instructor', 'term', 'current', 'past'));
    }

    public function start(Request $request): RedirectResponse
    {
        $term = Term::current();
        if (! $term) {
            return redirect()->route('instructor.home')->withErrors(['term' => __('app.terms.none_open')]);
        }
        try {
            $application = $this->workflow->start($request->user()->instructor, $term);
        } catch (TermClosedException) {
            return redirect()->route('instructor.home')->withErrors(['term' => __('app.applications.term_closed')]);
        }

        return redirect()->route('instructor.applications.show', $application);
    }

    public function show(Application $application): View
    {
        $this->authorize('view', $application);

        return view('instructor.application', [
            'application' => $application,
            'checklist' => $this->workflow->checklist($application),
            'plan' => $this->workflow->plan($application),
            'canSubmit' => $application->isEditable() && $this->workflow->allRequiredUploaded($application),
        ]);
    }
}
```

`routes/web.php` — instructor group becomes:

```php
Route::middleware(['auth', 'verified', 'role:instructor'])->prefix('my')->name('instructor.')->group(function () {
    Route::get('/', [InstructorApplicationController::class, 'home'])->name('home');
    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('applications', [InstructorApplicationController::class, 'start'])->name('applications.start');
    Route::get('applications/{application}', [InstructorApplicationController::class, 'show'])->name('applications.show');
});
```

`resources/views/instructor/home.blade.php` — extends `instructor.layout`. Shows: profile card (name, masked civil ID via `$instructor->maskedCivilId()`, edit link); if `$term` is null → alert `__('app.terms.none_open')`; else if `$current` → card with term label, status badge `__('app.applications.statuses.'.$current->status)`, link to show; else → POST form to `instructor.applications.start` with button `__('app.applications.start')`. Then a table of `$past` applications (term label, status).

`resources/views/instructor/application.blade.php` — extends `instructor.layout`. Header: term label + status badge. If `! $application->isEditable()` and `! $application->term->isOpen()` → alert `__('app.applications.term_closed')`. If status `incomplete` → alert `__('app.applications.fix_rejected')`. If status `rejected` → alert with `$application->rejection_reason`. Three sections:
1. `__('app.applications.required_items')`: table rows per `$checklist` entry: label (+ `note_ar` small), state badge (`__('app.documents.states.'.$state)`), file name/version + download link when a document exists, rejection reason when rejected, and an upload form (Task 9 fills this slot with `@include('instructor._upload', ['application' => $application, 'item' => $row['item']])`; until Task 9 leave the cell empty).
2. `__('app.applications.department_items')`: list of `$plan->department` labels with muted text `__('app.applications.by_department')`.
3. `__('app.applications.not_applicable_items')`: list of `$plan->notApplicable` labels.
Footer: submit button (Task 10) — render `@if ($canSubmit)` form to `instructor.applications.submit` (defined in Task 10; until then guard with `@if (Route::has('instructor.applications.submit'))`).

`lang/ar/app.php` — add:

```php
'applications' => [
    'title' => 'طلب الانتداب', 'start' => 'تقديم طلب للفصل الحالي', 'current' => 'الطلب الحالي', 'past' => 'الطلبات السابقة',
    'statuses' => ['draft' => 'مسودة', 'submitted' => 'مقدم', 'under_review' => 'قيد المراجعة', 'incomplete' => 'ناقص',
        'approved' => 'معتمد', 'rejected' => 'مرفوض', 'withdrawn' => 'مسحوب'],
    'required_items' => 'المستندات المطلوبة منك', 'department_items' => 'مستندات يصدرها القسم',
    'not_applicable_items' => 'مستندات لا تنطبق عليك', 'by_department' => 'يصدرها القسم، لا حاجة لرفعها',
    'term_closed' => 'الفصل الدراسي مغلق ولا يمكن تعديل الطلب.',
    'fix_rejected' => 'بعض المستندات مرفوضة، يرجى رفع نسخة جديدة منها ثم إعادة التقديم.',
    'rejected_reason' => 'سبب الرفض', 'submit' => 'تقديم الطلب', 'submitted' => 'تم تقديم الطلب، سيتم إشعارك بعد المراجعة.',
    'submit_blocked' => 'لا يمكن التقديم قبل رفع جميع المستندات المطلوبة.',
    'withdraw' => 'سحب الطلب',
],
'documents' => [
    'states' => ['missing' => 'غير مرفوع', 'pending' => 'بانتظار المراجعة', 'accepted' => 'مقبول', 'rejected' => 'مرفوض'],
    'upload' => 'رفع الملف', 'replace' => 'رفع نسخة جديدة', 'download' => 'تحميل', 'version' => 'النسخة',
    'file_rules' => 'PDF أو JPG أو PNG أو DOCX، بحد أقصى 10 ميغابايت.',
    'zip_rejected' => 'ملفات ZIP غير مقبولة، يرجى رفع كل مستند على حدة.',
    'uploaded' => 'تم رفع الملف.',
    'accept' => 'قبول', 'reject' => 'رفض', 'reason' => 'سبب الرفض', 'reviewed' => 'تم تحديث حالة المستند.',
],
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter "Instructor\\\\ApplicationTest"`
Expected: PASS (8 tests).

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: applications per term with policy, instructor home and checklist view

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 9: Documents (upload, versioning, private storage, policy-checked download, audit)

**Files:**
- Create: `database/migrations/…_create_documents_table.php`, `app/Models/Document.php`, `database/factories/DocumentFactory.php`, `app/Policies/DocumentPolicy.php`, `app/Http/Requests/UploadDocumentRequest.php`, `app/Services/DocumentStore.php`, `app/Http/Controllers/Instructor/DocumentController.php`, `resources/views/instructor/_upload.blade.php`
- Modify: `routes/web.php`, `app/Providers/AppServiceProvider.php`, `resources/views/instructor/application.blade.php`
- Test: `tests/Feature/Instructor/DocumentTest.php`

**Interfaces:**
- Produces: `Document` (`application_id`, `checklist_item_id`, `path`, `original_name`, `mime`, `size`, `status`, `rejection_reason`, `reviewed_by`, `reviewed_at`, `version`), relations `application()`, `checklistItem()`, `reviewer()`; `DocumentStore::store(Application, ChecklistItem, UploadedFile): Document` (next version, status `pending`, file at `applications/{application_id}/{random}.{ext}` on disk `local`); `DocumentStore::download(Document): StreamedResponse`; policy `view` (owner or admin), `create` (owner and application editable and item required); routes `instructor.documents.store` (POST `my/applications/{application}/documents/{item:code}`), `instructor.documents.download` (GET `my/documents/{document}`).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Instructor/DocumentTest.php`:

```php
<?php

namespace Tests\Feature\Instructor;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ChecklistItemSeeder::class);
        $this->user = User::factory()->instructor()->create();
        $instructor = Instructor::factory()->for($this->user)->create();
        $this->app = Application::factory()->for(Term::factory()->open())->for($instructor)->create();
    }

    private function upload(string $code, UploadedFile $file, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->post(route('instructor.documents.store', [$this->app, $code]), ['file' => $file]);
    }

    public function test_upload_pdf_stores_privately_with_random_name_and_version_1(): void
    {
        $this->upload('civil_id', UploadedFile::fake()->create('البطاقة.pdf', 200, 'application/pdf'))
            ->assertRedirect(route('instructor.applications.show', $this->app));

        $doc = Document::firstOrFail();
        $this->assertSame(1, $doc->version);
        $this->assertSame('pending', $doc->status);
        $this->assertSame('البطاقة.pdf', $doc->original_name);
        $this->assertStringStartsWith("applications/{$this->app->id}/", $doc->path);
        $this->assertStringNotContainsString('البطاقة', $doc->path);
        Storage::disk('local')->assertExists($doc->path);
    }

    public function test_reupload_creates_version_2_and_keeps_version_1(): void
    {
        $this->upload('civil_id', UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'));
        $this->upload('civil_id', UploadedFile::fake()->image('b.jpg'));

        $this->assertSame([1, 2], Document::orderBy('version')->pluck('version')->all());
        $this->assertSame(2, $this->app->latestDocuments()->get('civil_id')->version);
    }

    public function test_zip_and_oversize_and_exe_rejected(): void
    {
        $this->upload('civil_id', UploadedFile::fake()->create('docs.zip', 10, 'application/zip'))->assertSessionHasErrors('file');
        $this->upload('civil_id', UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf'))->assertSessionHasErrors('file');
        $this->upload('civil_id', UploadedFile::fake()->create('x.exe', 10, 'application/octet-stream'))->assertSessionHasErrors('file');
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_cannot_upload_for_item_not_required(): void
    {
        $this->upload('equivalency', UploadedFile::fake()->create('e.pdf', 10, 'application/pdf'))->assertForbidden();
        $this->upload('schedule', UploadedFile::fake()->create('s.pdf', 10, 'application/pdf'))->assertForbidden();
    }

    public function test_cannot_upload_after_submission_or_on_closed_term(): void
    {
        $this->app->update(['status' => Application::STATUS_SUBMITTED]);
        $this->upload('civil_id', UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'))->assertForbidden();

        $this->app->update(['status' => Application::STATUS_DRAFT]);
        $this->app->term->update(['status' => 'closed']);
        $this->upload('civil_id', UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'))->assertForbidden();
    }

    public function test_owner_downloads_and_stranger_gets_403_without_audit_entry(): void
    {
        $this->upload('civil_id', UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'));
        $doc = Document::firstOrFail();

        $this->actingAs($this->user)->get(route('instructor.documents.download', $doc))->assertOk();

        $stranger = User::factory()->instructor()->create();
        Instructor::factory()->for($stranger)->create();
        $this->actingAs($stranger)->get(route('instructor.documents.download', $doc))->assertForbidden();
        $this->assertSame(0, AuditLog::where('action', 'download_document')->count());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter "Instructor\\\\DocumentTest"`
Expected: FAIL.

- [ ] **Step 3: Implement**

Migration `…_create_documents_table.php`:

```php
Schema::create('documents', function (Blueprint $table) {
    $table->id();
    $table->foreignId('application_id')->constrained()->cascadeOnDelete();
    $table->foreignId('checklist_item_id')->constrained()->restrictOnDelete();
    $table->string('path', 255);
    $table->string('original_name', 255);
    $table->string('mime', 100);
    $table->unsignedInteger('size');
    $table->string('status', 10)->default('pending'); // pending|accepted|rejected
    $table->text('rejection_reason')->nullable();
    $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('reviewed_at')->nullable();
    $table->unsignedSmallInteger('version')->default(1);
    $table->timestamps();
    $table->unique(['application_id', 'checklist_item_id', 'version']);
});
```

`app/Models/Document.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Document extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = ['application_id', 'checklist_item_id', 'path', 'original_name', 'mime', 'size',
        'status', 'rejection_reason', 'reviewed_by', 'reviewed_at', 'version'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function checklistItem(): BelongsTo
    {
        return $this->belongsTo(ChecklistItem::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }
}
```

`database/factories/DocumentFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\ChecklistItem;
use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'checklist_item_id' => fn () => ChecklistItem::where('code', 'civil_id')->value('id'),
            'path' => 'applications/0/'.$this->faker->uuid().'.pdf',
            'original_name' => 'file.pdf', 'mime' => 'application/pdf', 'size' => 1000,
            'status' => Document::STATUS_PENDING, 'version' => 1,
        ];
    }

    public function forItem(string $code): static
    {
        return $this->state(fn () => ['checklist_item_id' => ChecklistItem::where('code', $code)->value('id')]);
    }

    public function accepted(): static
    {
        return $this->state(fn () => ['status' => Document::STATUS_ACCEPTED, 'reviewed_at' => now()]);
    }

    public function rejected(string $reason = 'غير واضح'): static
    {
        return $this->state(fn () => ['status' => Document::STATUS_REJECTED, 'rejection_reason' => $reason, 'reviewed_at' => now()]);
    }
}
```

`app/Policies/DocumentPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\Application;
use App\Models\ChecklistItem;
use App\Models\Document;
use App\Models\User;
use App\Services\ChecklistResolver;

class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        return $user->isAdmin() || $this->owns($user, $document->application);
    }

    /** Called as: $user->can('create', [Document::class, $application, $item]) */
    public function create(User $user, Application $application, ChecklistItem $item): bool
    {
        return $this->owns($user, $application)
            && $application->isEditable()
            && app(ChecklistResolver::class)->for($application->instructor)->isRequired($item->code);
    }

    public function review(User $user, Document $document): bool
    {
        return $user->isAdmin();
    }

    private function owns(User $user, Application $application): bool
    {
        return $user->instructor && $user->instructor->id === $application->instructor_id;
    }
}
```

Register in `AppServiceProvider::boot()`: `Gate::policy(Document::class, DocumentPolicy::class);`.

`app/Http/Requests/UploadDocumentRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UploadDocumentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,docx',
                'mimetypes:application/pdf,image/jpeg,image/png,application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        ];
    }

    public function withValidator(Validator $v): void
    {
        $v->after(function (Validator $v) {
            $f = $this->file('file');
            if ($f && (str_ends_with(strtolower($f->getClientOriginalName()), '.zip') || $f->getMimeType() === 'application/zip')) {
                $v->errors()->add('file', __('app.documents.zip_rejected'));
            }
        });
    }

    public function messages(): array
    {
        return ['file.mimes' => __('app.documents.file_rules'), 'file.mimetypes' => __('app.documents.file_rules'), 'file.max' => __('app.documents.file_rules')];
    }
}
```

`app/Services/DocumentStore.php`:

```php
<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ChecklistItem;
use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentStore
{
    public const DISK = 'local';

    public function store(Application $application, ChecklistItem $item, UploadedFile $file): Document
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $path = $file->storeAs("applications/{$application->id}", Str::random(40).'.'.$ext, self::DISK);
        $version = (int) $application->documents()->where('checklist_item_id', $item->id)->max('version') + 1;

        return $application->documents()->create([
            'checklist_item_id' => $item->id,
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime' => $file->getMimeType() ?? $file->getClientMimeType(),
            'size' => $file->getSize(),
            'status' => Document::STATUS_PENDING,
            'version' => $version,
        ]);
    }

    public function download(Document $document, bool $inline = false): StreamedResponse
    {
        $disk = Storage::disk(self::DISK);

        return $inline
            ? $disk->response($document->path, $document->original_name, ['Content-Type' => $document->mime])
            : $disk->download($document->path, $document->original_name);
    }
}
```

`app/Http/Controllers/Instructor/DocumentController.php`:

```php
<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadDocumentRequest;
use App\Models\Application;
use App\Models\ChecklistItem;
use App\Models\Document;
use App\Services\ApplicationWorkflow;
use App\Services\DocumentStore;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function __construct(private DocumentStore $store, private ApplicationWorkflow $workflow) {}

    public function store(UploadDocumentRequest $request, Application $application, ChecklistItem $item): RedirectResponse
    {
        $this->authorize('create', [Document::class, $application, $item]);

        $this->store->store($application, $item, $request->file('file'));
        $this->workflow->afterUpload($application); // Task 10 defines it; until then add a no-op method.

        return redirect()->route('instructor.applications.show', $application)->with('status', __('app.documents.uploaded'));
    }

    public function download(Document $document): StreamedResponse
    {
        $this->authorize('view', $document);

        return $this->store->download($document);
    }
}
```

Add to `ApplicationWorkflow` now (Task 10 replaces the body):

```php
public function afterUpload(Application $application): void {}
```

`routes/web.php` — inside the instructor group:

```php
Route::post('applications/{application}/documents/{item:code}', [InstructorDocumentController::class, 'store'])->name('documents.store');
Route::get('documents/{document}', [InstructorDocumentController::class, 'download'])->name('documents.download');
```

`resources/views/instructor/_upload.blade.php`:

```blade
@if ($application->isEditable())
<form method="post" action="{{ route('instructor.documents.store', [$application, $item->code]) }}" enctype="multipart/form-data" class="d-flex gap-2 align-items-center">
    @csrf
    <input type="file" name="file" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,.docx" required>
    <button class="btn btn-sm btn-eet text-nowrap">{{ $document ? __('app.documents.replace') : __('app.documents.upload') }}</button>
</form>
<div class="form-text">{{ __('app.documents.file_rules') }}</div>
@endif
```

In `resources/views/instructor/application.blade.php`, in the required-items table row, include: `@include('instructor._upload', ['application' => $application, 'item' => $row['item'], 'document' => $row['document']])` and, when `$row['document']`, a link `route('instructor.documents.download', $row['document'])` showing `original_name` and `__('app.documents.version')` `$row['document']->version`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter "Instructor\\\\DocumentTest"`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: document upload with versioning, private storage and policy-checked download

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 10: Submit and withdraw, with email to admin

**Files:**
- Create: `app/Mail/ApplicationSubmitted.php`, `resources/views/emails/application-submitted.blade.php`
- Modify: `app/Services/ApplicationWorkflow.php`, `app/Http/Controllers/Instructor/ApplicationController.php`, `routes/web.php`, `resources/views/instructor/application.blade.php`, `config/mail.php`/`.env.example` (`ADMIN_NOTIFY_EMAIL`), `lang/ar/app.php`, `lang/en/app.php`
- Test: `tests/Feature/Instructor/SubmitTest.php`

**Interfaces:**
- Produces: `ApplicationWorkflow::submit(Application): void` (throws `\DomainException` when not all required items uploaded or application not editable; sets `status = submitted`, `submitted_at`, mails `ApplicationSubmitted` to `config('mail.admin_notify')`); `ApplicationWorkflow::afterUpload(Application)` (when status `incomplete` and no latest document is `rejected` → status back to `submitted`, `submitted_at = now()`); `ApplicationWorkflow::withdraw(Application)`; routes `instructor.applications.submit`, `instructor.applications.withdraw`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Instructor/SubmitTest.php`:

```php
<?php

namespace Tests\Feature\Instructor;

use App\Mail\ApplicationSubmitted;
use App\Models\Application;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SubmitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['mail.admin_notify' => 'admin@example.com']);
        $this->seed(ChecklistItemSeeder::class);
        $this->user = User::factory()->instructor()->create();
        $instructor = Instructor::factory()->for($this->user)->create(); // local, government, master → 6 required
        $this->app = Application::factory()->for(Term::factory()->open())->for($instructor)->create();
    }

    private function uploadAll(array $except = []): void
    {
        foreach (['civil_id', 'degree', 'salary_cert', 'iban', 'employer_approval', 'undertaking'] as $code) {
            if (! in_array($code, $except, true)) {
                Document::factory()->for($this->app)->forItem($code)->create();
            }
        }
    }

    public function test_submit_blocked_until_all_required_uploaded(): void
    {
        $this->uploadAll(except: ['iban']);

        $this->actingAs($this->user)->post(route('instructor.applications.submit', $this->app))
            ->assertSessionHasErrors('submit');
        $this->assertSame(Application::STATUS_DRAFT, $this->app->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_submit_sets_status_and_mails_admin(): void
    {
        $this->uploadAll();

        $this->actingAs($this->user)->post(route('instructor.applications.submit', $this->app))
            ->assertRedirect(route('instructor.applications.show', $this->app));

        $fresh = $this->app->fresh();
        $this->assertSame(Application::STATUS_SUBMITTED, $fresh->status);
        $this->assertNotNull($fresh->submitted_at);
        Mail::assertSent(ApplicationSubmitted::class, fn ($m) => $m->hasTo('admin@example.com'));
    }

    public function test_reupload_of_rejected_item_returns_incomplete_to_submitted(): void
    {
        $this->uploadAll(except: ['iban']);
        Document::factory()->for($this->app)->forItem('iban')->rejected()->create();
        $this->app->update(['status' => Application::STATUS_INCOMPLETE]);

        Document::factory()->for($this->app)->forItem('iban')->create(['version' => 2]);
        app(\App\Services\ApplicationWorkflow::class)->afterUpload($this->app->fresh());

        $this->assertSame(Application::STATUS_SUBMITTED, $this->app->fresh()->status);
    }

    public function test_withdraw_from_draft_or_submitted_only(): void
    {
        $this->actingAs($this->user)->post(route('instructor.applications.withdraw', $this->app))->assertRedirect();
        $this->assertSame(Application::STATUS_WITHDRAWN, $this->app->fresh()->status);

        $this->app->update(['status' => Application::STATUS_APPROVED]);
        $this->actingAs($this->user)->post(route('instructor.applications.withdraw', $this->app))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter SubmitTest`
Expected: FAIL.

- [ ] **Step 3: Implement**

`config/mail.php` — add `'admin_notify' => env('ADMIN_NOTIFY_EMAIL'),`; `.env.example` — add `ADMIN_NOTIFY_EMAIL=`.

`app/Mail/ApplicationSubmitted.php`:

```php
<?php

namespace App\Mail;

use App\Models\Application;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ApplicationSubmitted extends Mailable
{
    public function __construct(public Application $application) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('app.mail.submitted_subject', ['name' => $this->application->instructor->full_name], 'ar'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.application-submitted', with: [
            'name' => $this->application->instructor->full_name,
            'term' => $this->application->term->label(),
            'url' => route('admin.applications.show', $this->application),
        ]);
    }
}
```

`resources/views/emails/application-submitted.blade.php`:

```blade
@extends('emails._shell')
@section('body')
    <p>{{ __('app.mail.submitted_body', ['name' => $name, 'term' => $term], 'ar') }}</p>
    <p><a href="{{ $url }}">{{ __('app.mail.open_application', [], 'ar') }}</a></p>
@endsection
```

(`admin.applications.show` is defined in Task 11. Until then, define a placeholder `Route::get('applications/{application}', fn () => '')->name('applications.show');` in the admin group.)

`app/Services/ApplicationWorkflow.php` — add/replace methods:

```php
public function submit(Application $application): void
{
    if (! $application->isEditable()) {
        throw new \DomainException(__('app.applications.term_closed'));
    }
    if (! $this->allRequiredUploaded($application)) {
        throw new \DomainException(__('app.applications.submit_blocked'));
    }
    $application->update(['status' => Application::STATUS_SUBMITTED, 'submitted_at' => now()]);
    $this->notifyAdmin($application);
}

public function afterUpload(Application $application): void
{
    if ($application->status === Application::STATUS_INCOMPLETE && $this->allRequiredUploaded($application)) {
        $application->update(['status' => Application::STATUS_SUBMITTED, 'submitted_at' => now()]);
        $this->notifyAdmin($application);
    }
}

public function withdraw(Application $application): void
{
    if ($application->isFinal()) {
        throw new \DomainException('final');
    }
    $application->update(['status' => Application::STATUS_WITHDRAWN, 'decided_at' => now()]);
}

private function notifyAdmin(Application $application): void
{
    if ($to = config('mail.admin_notify')) {
        \Illuminate\Support\Facades\Mail::to($to)->send(new \App\Mail\ApplicationSubmitted($application));
    }
}
```

`ApplicationController` — add:

```php
public function submit(Application $application): RedirectResponse
{
    $this->authorize('update', $application);
    try {
        $this->workflow->submit($application);
    } catch (\DomainException $e) {
        return back()->withErrors(['submit' => $e->getMessage()]);
    }

    return redirect()->route('instructor.applications.show', $application)->with('status', __('app.applications.submitted'));
}

public function withdraw(Application $application): RedirectResponse
{
    $this->authorize('view', $application);
    abort_if($application->isFinal(), 403);
    $this->workflow->withdraw($application);

    return redirect()->route('instructor.home')->with('status', __('app.applications.withdrawn'));
}
```

Routes (instructor group):

```php
Route::post('applications/{application}/submit', [InstructorApplicationController::class, 'submit'])->name('applications.submit');
Route::post('applications/{application}/withdraw', [InstructorApplicationController::class, 'withdraw'])->name('applications.withdraw');
```

View: in `instructor/application.blade.php` footer, when `$canSubmit` show the submit form (button `__('app.applications.submit')`); when `! $application->isFinal()` show a withdraw form with `onsubmit="return confirm('{{ __('app.applications.withdraw_confirm') }}')"`.

`lang/ar/app.php` — add to `applications`: `'withdrawn' => 'تم سحب الطلب.'`, `'withdraw_confirm' => 'هل تريد سحب الطلب؟'`; add to `mail`: `'submitted_subject' => 'طلب انتداب جديد: :name'`, `'submitted_body' => 'قدم :name طلب انتداب للفصل :term وهو بانتظار المراجعة.'`, `'open_application' => 'فتح الطلب'`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter SubmitTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: submit, resubmit-after-fix and withdraw applications; notify admin by email

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 11: Admin review — attention list, application page, document accept/reject, approve/reject, reveal

**Files:**
- Create: `app/Http/Controllers/Admin/DashboardController.php`, `app/Http/Controllers/Admin/ApplicationController.php`, `app/Http/Controllers/Admin/DocumentController.php`, `app/Http/Requests/RejectDocumentRequest.php`, `app/Mail/DocumentsRejected.php`, `app/Mail/ApplicationApproved.php`, `app/Mail/ApplicationRejected.php`, `resources/views/emails/documents-rejected.blade.php`, `emails/application-approved.blade.php`, `emails/application-rejected.blade.php`, `resources/views/admin/dashboard.blade.php`, `admin/applications/index.blade.php`, `admin/applications/show.blade.php`
- Modify: `app/Services/ApplicationWorkflow.php`, `routes/web.php`, `resources/views/admin/layout.blade.php`, `lang/ar/app.php`, `lang/en/app.php`
- Test: `tests/Feature/Admin/ReviewTest.php`

**Interfaces:**
- Produces: `ApplicationWorkflow::reviewDocument(Document, User $admin, string $status, ?string $reason): void` (`accepted|rejected`; marks application `under_review` on first review, `incomplete` on any rejection and mails `DocumentsRejected` to the instructor listing rejected items); `approve(Application, User $admin, ?string $decisionNumber, ?string $decisionDate)` (throws `\DomainException` unless `allRequiredAccepted` and term open; sets `approved`, `decided_at`; mails `ApplicationApproved`); `reject(Application, User $admin, string $reason)` (mails `ApplicationRejected`); `revealSensitive(Application, User $admin)` (audit `reveal_sensitive`, session flag); routes `admin.dashboard`, `admin.applications.index|show|approve|reject|reveal`, `admin.documents.review` (POST `admin/documents/{document}/review`), `admin.documents.download`, `admin.documents.view`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Admin/ReviewTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Mail\ApplicationApproved;
use App\Mail\ApplicationRejected;
use App\Mail\DocumentsRejected;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $instructorUser;
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        $this->seed(ChecklistItemSeeder::class);
        $this->admin = User::factory()->admin()->create();
        $this->instructorUser = User::factory()->instructor()->create();
        $instructor = Instructor::factory()->for($this->instructorUser)->create();
        $this->app = Application::factory()->submitted()->for(Term::factory()->open())->for($instructor)->create();
        foreach (['civil_id', 'degree', 'salary_cert', 'iban', 'employer_approval', 'undertaking'] as $code) {
            Document::factory()->for($this->app)->forItem($code)->create();
        }
    }

    private function acceptAll(): void
    {
        foreach ($this->app->latestDocuments() as $doc) {
            $this->actingAs($this->admin)->post(route('admin.documents.review', $doc), ['status' => 'accepted']);
        }
    }

    public function test_dashboard_lists_submitted_applications(): void
    {
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()
            ->assertSee($this->app->instructor->full_name)->assertSee(__('app.applications.statuses.submitted'));
    }

    public function test_show_masks_sensitive_until_reveal_which_is_audited(): void
    {
        $civil = $this->app->instructor->civil_id;
        $r = $this->actingAs($this->admin)->get(route('admin.applications.show', $this->app))->assertOk();
        $r->assertSee($this->app->instructor->maskedCivilId())->assertDontSee($civil);
        $this->assertSame(Application::STATUS_UNDER_REVIEW, $this->app->fresh()->status);

        $this->actingAs($this->admin)->post(route('admin.applications.reveal', $this->app))->assertRedirect();
        $this->actingAs($this->admin)->get(route('admin.applications.show', $this->app))->assertSee($civil);
        $this->assertDatabaseHas('audit_log', ['user_id' => $this->admin->id, 'action' => 'reveal_sensitive', 'subject_id' => $this->app->id]);
    }

    public function test_rejecting_a_document_marks_incomplete_and_mails_instructor(): void
    {
        $doc = $this->app->latestDocuments()->get('iban');

        $this->actingAs($this->admin)->post(route('admin.documents.review', $doc), ['status' => 'rejected', 'reason' => 'غير واضح'])
            ->assertRedirect();

        $this->assertSame('rejected', $doc->fresh()->status);
        $this->assertSame('غير واضح', $doc->fresh()->rejection_reason);
        $this->assertSame(Application::STATUS_INCOMPLETE, $this->app->fresh()->status);
        Mail::assertSent(DocumentsRejected::class, fn ($m) => $m->hasTo($this->instructorUser->email));
    }

    public function test_reject_requires_reason(): void
    {
        $doc = $this->app->latestDocuments()->get('iban');
        $this->actingAs($this->admin)->post(route('admin.documents.review', $doc), ['status' => 'rejected'])->assertSessionHasErrors('reason');
    }

    public function test_approve_blocked_until_latest_versions_all_accepted(): void
    {
        $this->acceptAll();
        $iban = $this->app->latestDocuments()->get('iban');
        $this->actingAs($this->admin)->post(route('admin.documents.review', $iban), ['status' => 'rejected', 'reason' => 'x']);

        $this->actingAs($this->admin)->post(route('admin.applications.approve', $this->app))->assertSessionHasErrors('approve');

        // Applicant re-uploads (v2, pending) → still blocked; admin accepts v2 → approve works even though v1 stays rejected.
        $v2 = Document::factory()->for($this->app)->forItem('iban')->create(['version' => 2]);
        $this->actingAs($this->admin)->post(route('admin.applications.approve', $this->app))->assertSessionHasErrors('approve');
        $this->actingAs($this->admin)->post(route('admin.documents.review', $v2), ['status' => 'accepted']);

        $this->actingAs($this->admin)->post(route('admin.applications.approve', $this->app), [
            'assignment_decision_number' => '123/2026', 'assignment_decision_date' => '2026-09-20',
        ])->assertRedirect();

        $fresh = $this->app->fresh();
        $this->assertSame(Application::STATUS_APPROVED, $fresh->status);
        $this->assertSame('123/2026', $fresh->assignment_decision_number);
        Mail::assertSent(ApplicationApproved::class, fn ($m) => $m->hasTo($this->instructorUser->email));
    }

    public function test_approve_blocked_on_closed_term(): void
    {
        $this->acceptAll();
        $this->app->term->update(['status' => 'closed']);

        $this->actingAs($this->admin)->post(route('admin.applications.approve', $this->app))->assertSessionHasErrors('approve');
        $this->assertNotSame(Application::STATUS_APPROVED, $this->app->fresh()->status);
    }

    public function test_reject_application_with_reason_mails_instructor(): void
    {
        $this->actingAs($this->admin)->post(route('admin.applications.reject', $this->app), ['reason' => 'لا يستوفي الشروط'])->assertRedirect();

        $this->assertSame(Application::STATUS_REJECTED, $this->app->fresh()->status);
        Mail::assertSent(ApplicationRejected::class);
    }

    public function test_admin_document_download_is_audited_and_instructor_cannot_use_admin_routes(): void
    {
        $doc = $this->app->latestDocuments()->get('civil_id');
        Storage::disk('local')->put($doc->path, 'pdf');

        $this->actingAs($this->admin)->get(route('admin.documents.download', $doc))->assertOk();
        $this->assertDatabaseHas('audit_log', ['action' => 'download_document', 'subject_id' => $doc->id]);

        $this->actingAs($this->instructorUser)->get(route('admin.documents.download', $doc))->assertForbidden();
        $this->actingAs($this->instructorUser)->post(route('admin.documents.review', $doc), ['status' => 'accepted'])->assertForbidden();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter ReviewTest`
Expected: FAIL.

- [ ] **Step 3: Implement**

`ApplicationWorkflow` — add:

```php
public function markUnderReview(Application $application): void
{
    if ($application->status === Application::STATUS_SUBMITTED) {
        $application->update(['status' => Application::STATUS_UNDER_REVIEW, 'reviewed_at' => now()]);
    }
}

public function reviewDocument(Document $document, User $admin, string $status, ?string $reason): void
{
    $document->update([
        'status' => $status,
        'rejection_reason' => $status === Document::STATUS_REJECTED ? $reason : null,
        'reviewed_by' => $admin->id,
        'reviewed_at' => now(),
    ]);
    AuditLog::record($admin->id, 'review_document_'.$status, $document);

    $application = $document->application->fresh();
    if ($status === Document::STATUS_REJECTED && ! $application->isFinal()) {
        $application->update(['status' => Application::STATUS_INCOMPLETE]);
        $rejected = $this->checklist($application);
        $rejected = array_filter($rejected, fn ($row) => $row['state'] === 'rejected');
        Mail::to($application->instructor->user->email)->send(new DocumentsRejected($application, array_values($rejected)));
    }
}

public function approve(Application $application, User $admin, ?string $decisionNumber, ?string $decisionDate): void
{
    if (! $application->term->isOpen()) {
        throw new \DomainException(__('app.applications.term_closed'));
    }
    if (! $this->allRequiredAccepted($application)) {
        throw new \DomainException(__('app.review.approve_blocked'));
    }
    $application->update([
        'status' => Application::STATUS_APPROVED, 'decided_at' => now(),
        'assignment_decision_number' => $decisionNumber, 'assignment_decision_date' => $decisionDate,
    ]);
    AuditLog::record($admin->id, 'approve_application', $application);
    Mail::to($application->instructor->user->email)->send(new ApplicationApproved($application));
}

public function reject(Application $application, User $admin, string $reason): void
{
    $application->update(['status' => Application::STATUS_REJECTED, 'decided_at' => now(), 'rejection_reason' => $reason]);
    AuditLog::record($admin->id, 'reject_application', $application);
    Mail::to($application->instructor->user->email)->send(new ApplicationRejected($application));
}
```

(Add the `use` lines: `App\Models\AuditLog`, `App\Models\Document`, `App\Models\User`, `App\Mail\DocumentsRejected`, `App\Mail\ApplicationApproved`, `App\Mail\ApplicationRejected`, `Illuminate\Support\Facades\Mail`.)

Mailables: `DocumentsRejected(Application $application, array $rows)` → view `emails.documents-rejected` listing `$rows[*]['item']->label_ar` and `['document']->rejection_reason`, plus link `route('instructor.applications.show', $application)`. `ApplicationApproved(Application)` → view `emails.application-approved` with term label. `ApplicationRejected(Application)` → view `emails.application-rejected` with `rejection_reason`. Subjects from `app.mail.docs_rejected_subject`, `approved_subject`, `rejected_subject` (Arabic).

`app/Http/Requests/RejectDocumentRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RejectDocumentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([Document::STATUS_ACCEPTED, Document::STATUS_REJECTED])],
            'reason' => ['nullable', 'string', 'max:500', Rule::requiredIf(fn () => $this->status === Document::STATUS_REJECTED)],
        ];
    }
}
```

`app/Http/Controllers/Admin/DashboardController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Term;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $attention = Application::with(['instructor', 'term'])
            ->whereIn('status', [Application::STATUS_SUBMITTED, Application::STATUS_UNDER_REVIEW])
            ->orderBy('submitted_at')->get();

        $counts = Application::whereHas('term', fn ($q) => $q->open())
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return view('admin.dashboard', ['attention' => $attention, 'counts' => $counts, 'term' => Term::current()]);
    }
}
```

`app/Http/Controllers/Admin/ApplicationController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Term;
use App\Services\ApplicationWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApplicationController extends Controller
{
    public function __construct(private ApplicationWorkflow $workflow) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Application::class);
        $term = $request->filled('term') ? Term::findOrFail($request->term) : Term::current();
        $apps = Application::with(['instructor', 'term'])
            ->when($term, fn ($q) => $q->where('term_id', $term->id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('submitted_at')->paginate(50)->withQueryString();

        return view('admin.applications.index', ['applications' => $apps, 'term' => $term, 'terms' => Term::orderByDesc('teaching_starts_on')->get()]);
    }

    public function show(Request $request, Application $application): View
    {
        $this->authorize('review', $application);
        $this->workflow->markUnderReview($application);
        $application->refresh();

        return view('admin.applications.show', [
            'application' => $application,
            'instructor' => $application->instructor,
            'checklist' => $this->workflow->checklist($application),
            'plan' => $this->workflow->plan($application),
            'history' => $application->documents()->with('checklistItem')->orderBy('checklist_item_id')->orderByDesc('version')->get(),
            'revealed' => in_array($application->id, session('revealed_applications', []), true),
            'canApprove' => $this->workflow->allRequiredAccepted($application) && $application->term->isOpen() && ! $application->isFinal(),
        ]);
    }

    public function reveal(Request $request, Application $application): RedirectResponse
    {
        $this->authorize('review', $application);
        AuditLog::record($request->user()->id, 'reveal_sensitive', $application);
        $ids = session('revealed_applications', []);
        $ids[] = $application->id;
        session(['revealed_applications' => array_values(array_unique($ids))]);

        return back();
    }

    public function approve(Request $request, Application $application): RedirectResponse
    {
        $this->authorize('review', $application);
        $data = $request->validate([
            'assignment_decision_number' => ['nullable', 'string', 'max:40'],
            'assignment_decision_date' => ['nullable', 'date'],
        ]);
        try {
            $this->workflow->approve($application, $request->user(), $data['assignment_decision_number'] ?? null, $data['assignment_decision_date'] ?? null);
        } catch (\DomainException $e) {
            return back()->withErrors(['approve' => $e->getMessage()]);
        }

        return back()->with('status', __('app.review.approved'));
    }

    public function reject(Request $request, Application $application): RedirectResponse
    {
        $this->authorize('review', $application);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->workflow->reject($application, $request->user(), $data['reason']);

        return back()->with('status', __('app.review.rejected'));
    }
}
```

`app/Http/Controllers/Admin/DocumentController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RejectDocumentRequest;
use App\Models\AuditLog;
use App\Models\Document;
use App\Services\ApplicationWorkflow;
use App\Services\DocumentStore;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function __construct(private ApplicationWorkflow $workflow, private DocumentStore $store) {}

    public function review(RejectDocumentRequest $request, Document $document): RedirectResponse
    {
        $this->authorize('review', $document);
        $this->workflow->reviewDocument($document, $request->user(), $request->status, $request->reason);

        return back()->with('status', __('app.documents.reviewed'));
    }

    public function download(Document $document): StreamedResponse
    {
        $this->authorize('review', $document);
        AuditLog::record(auth()->id(), 'download_document', $document);

        return $this->store->download($document);
    }

    public function view(Document $document): StreamedResponse
    {
        $this->authorize('review', $document);
        AuditLog::record(auth()->id(), 'view_document', $document);

        return $this->store->download($document, inline: true);
    }
}
```

`routes/web.php` — admin group becomes:

```php
Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('terms', [TermController::class, 'index'])->name('terms.index');
    Route::get('terms/create', [TermController::class, 'create'])->name('terms.create');
    Route::post('terms', [TermController::class, 'store'])->name('terms.store');
    Route::get('terms/{term}/edit', [TermController::class, 'edit'])->name('terms.edit');
    Route::put('terms/{term}', [TermController::class, 'update'])->name('terms.update');
    Route::post('terms/{term}/close', [TermController::class, 'close'])->name('terms.close');
    Route::get('applications', [AdminApplicationController::class, 'index'])->name('applications.index');
    Route::get('applications/{application}', [AdminApplicationController::class, 'show'])->name('applications.show');
    Route::post('applications/{application}/reveal', [AdminApplicationController::class, 'reveal'])->name('applications.reveal');
    Route::post('applications/{application}/approve', [AdminApplicationController::class, 'approve'])->name('applications.approve');
    Route::post('applications/{application}/reject', [AdminApplicationController::class, 'reject'])->name('applications.reject');
    Route::post('documents/{document}/review', [AdminDocumentController::class, 'review'])->name('documents.review');
    Route::get('documents/{document}', [AdminDocumentController::class, 'download'])->name('documents.download');
    Route::get('documents/{document}/view', [AdminDocumentController::class, 'view'])->name('documents.view');
});
```

Views:
- `admin/dashboard.blade.php`: current term label (or `terms.none_open`), count badges per status from `$counts`, and the "يحتاج انتباهي" table (`$attention`): name, term, status, submitted date (`format_date`), link to show.
- `admin/applications/index.blade.php`: term select + status select filter form (GET), table like the dashboard with pagination links.
- `admin/applications/show.blade.php`: (1) profile card: all profile fields; civil ID, IBAN and salaries show `mask_middle(...)` unless `$revealed`, with a POST reveal button `__('app.review.reveal')`; (2) checklist table from `$checklist` with state badge, latest document link (`admin.documents.view` opens inline, `admin.documents.download`), and, when `! $application->isFinal()`, an inline review form per document: two buttons — accept (POST `status=accepted`) and reject (reveals a `reason` input + POST `status=rejected`); (3) department items and not-applicable lists; (4) document history table (`$history`) with version, uploaded date, status, reason; (5) decision card: when `$canApprove`, form with `assignment_decision_number`, `assignment_decision_date` and approve button; reject form with `reason` textarea, `onsubmit` confirm; final status shown otherwise. (6) Print Check List button (Task 12).

`admin/layout.blade.php` — nav links: dashboard, applications index, terms.

`lang/ar/app.php` — add:

```php
'review' => [
    'attention' => 'يحتاج انتباهي', 'applications' => 'الطلبات', 'no_attention' => 'لا توجد طلبات بانتظار المراجعة.',
    'applicant' => 'المتقدم', 'term' => 'الفصل', 'submitted_at' => 'تاريخ التقديم', 'open' => 'فتح',
    'filter' => 'تصفية', 'all_statuses' => 'كل الحالات',
    'profile' => 'بيانات المتقدم', 'reveal' => 'إظهار البيانات الحساسة', 'revealed' => 'البيانات الحساسة ظاهرة (مسجل في السجل)',
    'checklist' => 'قائمة المستندات', 'history' => 'سجل الملفات', 'decision' => 'القرار',
    'decision_number' => 'رقم قرار التكليف', 'decision_date' => 'تاريخ قرار التكليف',
    'approve' => 'اعتماد الطلب', 'approve_blocked' => 'لا يمكن الاعتماد قبل قبول جميع المستندات المطلوبة.',
    'approved' => 'تم اعتماد الطلب.', 'reject' => 'رفض الطلب', 'reject_confirm' => 'هل تريد رفض الطلب؟', 'rejected' => 'تم رفض الطلب.',
    'reason' => 'السبب', 'view' => 'عرض', 'print_checklist' => 'طباعة قائمة التدقيق',
],
```

and to `mail`: `'docs_rejected_subject' => 'مستندات تحتاج إلى تصحيح'`, `'docs_rejected_body' => 'تمت مراجعة طلبك للفصل :term وهناك مستندات مرفوضة يرجى رفع نسخة جديدة منها:'`, `'approved_subject' => 'تم اعتماد طلب الانتداب'`, `'approved_body' => 'تم اعتماد طلب انتدابك للفصل :term. سيتواصل معك القسم بخصوص الجدول.'`, `'rejected_subject' => 'نتيجة طلب الانتداب'`, `'rejected_body' => 'نأسف، لم يتم قبول طلب انتدابك للفصل :term. السبب: :reason'`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter ReviewTest`
Expected: PASS (8 tests). Then run the full suite: `php artisan test` — all green.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: admin review flow with document accept/reject, approval gating, reveal audit and emails

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 12: Printable official Check List (.docx via PhpWord)

**Files:**
- Create: `app/Services/ChecklistDocument.php`, `public/img/paaet-logo.png` (export the PAAET logo from the JPEG scan or use the department's existing logo asset from help.q8ee.com `public/`; if none is available, omit the image and keep the text header)
- Modify: `composer.json` (require `phpoffice/phpword:^1.3`), `app/Http/Controllers/Admin/ApplicationController.php`, `routes/web.php`, `resources/views/admin/applications/show.blade.php`
- Test: `tests/Feature/Admin/ChecklistDocumentTest.php`

**Interfaces:**
- Produces: `ChecklistDocument::build(Application, User $checker): string` returning the absolute path of a generated `.docx` in `storage/app/private/generated/`; route `admin.applications.checklist` (GET) streaming the file as `checklist-{application id}.docx`; the document contains, RTL, the title `Check List`, `ما يخص المستعان به للتدريس بكليات الهيئة`, the 11 header fields, and the 12 items with `☑` for accepted/on-file/department items, `☐` for missing, and `—` for not-applicable items with the note `لا ينطبق`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Admin/ChecklistDocumentTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use App\Services\ChecklistDocument;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChecklistDocumentTest extends TestCase
{
    use RefreshDatabase;

    private function docxText(string $path): string
    {
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        return html_entity_decode(strip_tags(preg_replace('/<\/w:p>/', "\n", $xml)));
    }

    public function test_builds_docx_with_header_fields_and_item_states(): void
    {
        $this->seed(ChecklistItemSeeder::class);
        $admin = User::factory()->admin()->create(['name' => 'د. مشعل الشريده']);
        $instructor = Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'محمد أحمد علي الفهد']);
        $term = Term::factory()->open()->create(['academic_year' => '2026-2027', 'type' => 'first']);
        $app = Application::factory()->for($term)->for($instructor)->create();
        Document::factory()->for($app)->forItem('civil_id')->accepted()->create();

        $path = app(ChecklistDocument::class)->build($app, $admin);
        $text = $this->docxText($path);

        $this->assertStringContainsString('Check List', $text);
        $this->assertStringContainsString('محمد أحمد علي الفهد', $text);
        $this->assertStringContainsString($instructor->civil_id, $text);
        $this->assertStringContainsString('الفصل الأول', $text);
        $this->assertStringContainsString('2026-2027', $text);
        $this->assertStringContainsString('☑ صورة البطاقة المدنية سارية المفعول', $text);
        $this->assertStringContainsString('☐ صورة من المؤهل العلمي', $text);
        $this->assertStringContainsString('— صورة من معادلة المؤهل العلمي', $text);
        $this->assertStringContainsString('☑ الجدول الدراسي', $text);
        $this->assertStringContainsString('د. مشعل الشريده', $text);
    }

    public function test_route_streams_docx_for_admin_only(): void
    {
        $this->seed(ChecklistItemSeeder::class);
        $app = Application::factory()->create();

        $this->actingAs(User::factory()->admin()->create())->get(route('admin.applications.checklist', $app))
            ->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $this->actingAs($app->instructor->user)->get(route('admin.applications.checklist', $app))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer require phpoffice/phpword:^1.3 && php artisan test --filter ChecklistDocumentTest`
Expected: FAIL (`ChecklistDocument` missing).

- [ ] **Step 3: Implement**

`app/Services/ChecklistDocument.php`:

```php
<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ChecklistItem;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;

class ChecklistDocument
{
    public function __construct(private ApplicationWorkflow $workflow) {}

    public function build(Application $application, User $checker): string
    {
        $i = $application->instructor;
        $plan = $this->workflow->plan($application);
        $checklist = $this->workflow->checklist($application);

        $word = new PhpWord;
        $word->setDefaultFontName('Arial');
        $word->setDefaultFontSize(11);
        $word->getSettings()->setThemeFontLang(new \PhpOffice\PhpWord\Style\Language(null, null, 'ar-KW'));
        $section = $word->addSection(['marginTop' => Converter::cmToTwip(2), 'marginBottom' => Converter::cmToTwip(2)]);
        $rtl = ['bidi' => true, 'alignment' => Jc::START];
        $bold = ['bold' => true];

        $logo = public_path('img/paaet-logo.png');
        if (is_file($logo)) {
            $section->addImage($logo, ['width' => 60, 'alignment' => Jc::END]);
        }
        $section->addText('التاريخ : '.now()->format('Y/m/d'), [], $rtl);
        $section->addText('Check List', ['bold' => true, 'size' => 16], ['alignment' => Jc::CENTER]);
        $section->addText('ما يخص المستعان به للتدريس بكليات الهيئة', ['bold' => true, 'underline' => 'single', 'size' => 13], ['bidi' => true, 'alignment' => Jc::CENTER]);
        $section->addTextBreak();

        $rows = [
            ['الاسم الثلاثي للمستعان به (المنتدب)', $i->full_name],
            ['الرقم المدني', $i->civil_id, 'تاريخ انتهاء البطاقة المدنية', $i->civil_id_expires_on->format('Y/m/d')],
            ['المؤهل العلمي', $i->degree_title, 'تاريخ الحصول على المؤهل', $i->degree_obtained_on->format('Y/m/d')],
            ['جهة العمل', $i->employer, 'المسمى الوظيفي', $i->job_title],
            ['الكلية المنتدب إليها', __('app.college_name', [], 'ar'), 'القسم العلمي', __('app.dept_name', [], 'ar')],
            ['الفصل الدراسي', __('app.terms.types.'.$application->term->type, [], 'ar'), 'العام الدراسي', $application->term->academic_year],
        ];
        foreach ($rows as $r) {
            $line = $r[0].' : '.$r[1].(isset($r[2]) ? '        '.$r[2].' : '.$r[3] : '');
            $section->addText($line, [], $rtl);
        }
        $section->addTextBreak();
        $section->addText('❖ قائمة المستندات المطلوبة :', $bold + ['underline' => 'single'], $rtl);

        foreach (ChecklistItem::orderBy('sort_order')->get() as $item) {
            if ($item->isDepartment()) {
                $mark = '☑';
            } elseif (isset($checklist[$item->code])) {
                $mark = $checklist[$item->code]['state'] === 'accepted' ? '☑' : '☐';
            } else {
                $mark = '—';
            }
            $label = $item->label_ar.($item->note_ar ? ' ( '.$item->note_ar.' )' : '');
            if ($mark === '—') {
                $label .= ' — لا ينطبق';
            }
            $section->addText($mark.' '.$label.' .', [], $rtl + ['indentation' => ['start' => Converter::cmToTwip(0.5)]]);
        }

        $section->addTextBreak();
        $section->addText('❖ تم التدقيق بواسطة :', $bold, $rtl);
        $section->addText('الاسم : '.$checker->name, [], $rtl);

        Storage::disk('local')->makeDirectory('generated');
        $path = Storage::disk('local')->path("generated/checklist-{$application->id}.docx");
        IOFactory::createWriter($word, 'Word2007')->save($path);

        return $path;
    }
}
```

Controller method in `Admin/ApplicationController`:

```php
public function checklist(Request $request, Application $application, \App\Services\ChecklistDocument $doc): \Symfony\Component\HttpFoundation\BinaryFileResponse
{
    $this->authorize('review', $application);
    AuditLog::record($request->user()->id, 'print_checklist', $application);

    return response()->download($doc->build($application, $request->user()), "checklist-{$application->id}.docx", [
        'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ]);
}
```

Route (admin group): `Route::get('applications/{application}/checklist', [AdminApplicationController::class, 'checklist'])->name('applications.checklist');`

Button in `admin/applications/show.blade.php` decision card: link to `admin.applications.checklist` labelled `__('app.review.print_checklist')`.

Add `/storage/app/private/generated` to `.gitignore` (already covered by `/storage/app/*` patterns in Laravel's default `.gitignore`; verify with `git status` that nothing under `storage/app` is tracked).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter ChecklistDocumentTest`
Expected: PASS (2 tests). Open the generated file once manually (LibreOffice or Word) and compare with `docs/forms/checklist.md`: RTL text, checkbox glyphs visible, Arabic not reversed. If glyphs `☑☐` render as boxes, switch `setDefaultFontName` to `'Arial Unicode MS'` or `'Segoe UI Symbol'` for the mark run only.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: generate the official Check List as a Word document from application data

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 13: Deployment to parttime.q8ee.com

**Files:**
- Create: `deploy/deploy.sh`, `deploy/DEPLOY.md`, `deploy/.env.production.example`, `deploy/apache-vhost.conf`
- Modify: `PROGRESS.md`, `CLAUDE.md` (status), `.gitignore` (add `deploy/.env.production`)

**Interfaces:**
- Produces: `./deploy/deploy.sh [--dry]` that runs the test suite, rsyncs the app to `root@alsharidah.shop:/srv/www/parttime.q8ee.com/app`, runs `composer install --no-dev`, `migrate --force`, `db:seed --class=ChecklistItemSeeder --force`, caches, permissions, and checks `https://parttime.q8ee.com/up`.

- [ ] **Step 1: Write `deploy/deploy.sh`**

Copy `help.q8ee.com/deploy/deploy.sh` and change: header comment, `REMOTE_BASE="/srv/www/parttime.q8ee.com"`, health URL `https://parttime.q8ee.com/up`, and in the ssh block add `php artisan db:seed --class=ChecklistItemSeeder --force &&` after `migrate --force`, and replace `mkdir -p storage/app/mpdf` with `mkdir -p storage/app/private/applications storage/app/private/generated`. Keep `--exclude 'storage/app'` so uploads on the server are never touched by rsync. `chmod +x deploy/deploy.sh`.

- [ ] **Step 2: Write `deploy/.env.production.example`**

```
APP_NAME="نظام المنتدبين"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://parttime.q8ee.com
APP_LOCALE=ar
APP_FALLBACK_LOCALE=en
APP_TIMEZONE=Asia/Kuwait
LOG_CHANNEL=daily
LOG_LEVEL=warning
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=parttime
DB_USERNAME=parttime
DB_PASSWORD=
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=file
QUEUE_CONNECTION=sync
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=noreply@q8ee.com
MAIL_FROM_NAME="نظام المنتدبين"
ADMIN_NOTIFY_EMAIL=
TURNSTILE_SITE_KEY=
TURNSTILE_SECRET=
CIVIL_ID_CHECKSUM=true
TRUSTED_PROXIES=*
```

Add to `bootstrap/app.php` `withMiddleware`: `$middleware->trustProxies(at: '*');` (the app sits behind Cloudflare and Apache; without this, `secure` cookies and signed URLs break).

- [ ] **Step 3: Write `deploy/apache-vhost.conf` and `deploy/DEPLOY.md`**

`apache-vhost.conf`: `<VirtualHost *:443>` for `parttime.q8ee.com`, `DocumentRoot /srv/www/parttime.q8ee.com/app/public`, `AllowOverride All`, PHP-FPM handler as used by help.q8ee.com, Cloudflare origin certificate paths, and a `<VirtualHost *:80>` redirect to https.

`DEPLOY.md`: first-time steps in order — create MySQL DB/user; clone/rsync; copy `.env.production.example` to server `.env` and fill; `php artisan key:generate --force`; `php artisan migrate --force`; `php artisan db:seed --class=ChecklistItemSeeder --force`; `php artisan app:create-admin malshar@... "د. مشعل الشريده"`; enable vhost; Cloudflare: create Turnstile widget for `parttime.q8ee.com` (managed mode) and put keys in `.env`; Cloudflare SSL mode Full (strict); then routine deploys via `./deploy/deploy.sh`. Backups: nightly `mysqldump` + rsync of `storage/app/private` to the existing backup location used by help.q8ee.com.

- [ ] **Step 4: Dry run and update docs**

Run: `./deploy/deploy.sh --dry`
Expected: rsync file list printed, no ssh commands executed, exit 0. (The real deploy is run by Dr. Mishal after server prerequisites in DEPLOY.md are done.)

Update `PROGRESS.md`: status → "Milestone 1 (intake) implemented; deploy pending server setup", log line with date. Update `CLAUDE.md` status header to reflect that the app exists and point to the spec and this plan.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "chore: deploy script, production env template, Apache vhost and deployment notes

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Self-review notes

- **Spec coverage (milestone 1):** §2 decisions 1, 5–10 → Tasks 1–4, 6, 7; §3 terms/users/instructors/applications/checklist_items/documents/audit_log → Tasks 2, 5–9; §4 steps 1, 3–10 → Tasks 5, 8–12; §4 step 2 (Excel import) and steps 11–15 are milestones 2–3 and deliberately absent; §5 Check List generation → Task 12 (PhpWord build instead of a template file, same output); §6 → Tasks 3, 4, 6, 9, 11, 13; §7 feature tests → each task's tests. The "on file" logic for returning instructors (§4 step 15) is milestone 3 as the spec states.
- **Placeholders:** none; every step has code or exact instructions.
- **Type consistency:** `ApplicationWorkflow` methods are introduced incrementally (Task 8: `start/checklist/plan/allRequired*`; Task 9: `afterUpload` stub; Task 10: `submit/afterUpload/withdraw`; Task 11: `markUnderReview/reviewDocument/approve/reject`). `latestDocuments()` is keyed by checklist code everywhere. Route names match between controllers, views and tests.
- **Review Focus pins:** 1 → Task 6 (`test_checksum_can_be_disabled_by_config`); 2 → Task 8 (`test_changing_profile_changes_required_items_and_keeps_uploads`); 3 → Task 9 (`test_owner_downloads_and_stranger_gets_403_without_audit_entry`); 4 → Task 11 (`test_approve_blocked_until_latest_versions_all_accepted`); 5 → Task 8 (`test_closed_term_application_is_not_editable`), Task 9 (`test_cannot_upload_after_submission_or_on_closed_term`), Task 11 (`test_approve_blocked_on_closed_term`).

<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionExpiryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Laravel's CSRF middleware never throws while `app()->runningUnitTests()` is
     * true (i.e. always, under `php artisan test`), regardless of `withMiddleware`.
     * Flip the SAPI detection for this test class only, before the application
     * boots, so a real token mismatch can be exercised; restore it afterwards so
     * the rest of the suite keeps its normal CSRF-bypassed test behaviour.
     */
    protected function setUp(): void
    {
        putenv('APP_RUNNING_IN_CONSOLE=false');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('APP_RUNNING_IN_CONSOLE');
        parent::tearDown();
    }

    public function test_expired_session_post_redirects_guest_to_login_with_message(): void
    {
        $this->withMiddleware(ValidateCsrfToken::class);
        $r = $this->post(route('logout'), ['_token' => 'stale']);

        $r->assertRedirect(route('login'))->assertSessionHasErrors(['email' => __('app.auth.session_expired')]);
    }

    public function test_token_mismatch_while_logged_in_still_returns_to_the_form(): void
    {
        $this->withMiddleware(ValidateCsrfToken::class);
        $user = User::factory()->instructor()->create();
        $r = $this->actingAs($user)->from(route('instructor.home'))->post(route('logout'), ['_token' => 'stale']);

        $r->assertRedirect(route('instructor.home'))->assertSessionHasErrors('page_expired');
    }

    public function test_expired_token_on_the_register_form_returns_to_it_with_the_typed_input(): void
    {
        $this->withMiddleware(ValidateCsrfToken::class);
        $r = $this->from(route('register'))->post(route('register.store'), [
            '_token' => 'stale', 'name' => 'سارة أحمد', 'email' => 'sara@example.com',
            'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1',
        ]);

        $r->assertRedirect(route('register'))->assertSessionHasErrors(['page_expired' => __('app.common.page_expired')]);
        $old = session('_old_input', []);
        $this->assertSame('سارة أحمد', $old['name'] ?? null);
        $this->assertSame('sara@example.com', $old['email'] ?? null);
        $this->assertArrayNotHasKey('password', $old);
        $this->assertArrayNotHasKey('password_confirmation', $old);
        $this->assertArrayNotHasKey('_token', $old);
        $this->assertGuest();
    }

    public function test_expired_token_on_the_forgot_password_form_returns_to_it(): void
    {
        $this->withMiddleware(ValidateCsrfToken::class);
        $r = $this->from(route('password.request'))->post(route('password.email'), ['_token' => 'stale', 'email' => 'sara@example.com']);

        $r->assertRedirect(route('password.request'))->assertSessionHasErrors('page_expired');
        $this->assertSame('sara@example.com', session('_old_input.email'));
    }

    public function test_expired_token_on_the_login_form_still_goes_to_login(): void
    {
        $this->withMiddleware(ValidateCsrfToken::class);
        $r = $this->from(route('home'))->post(route('login.attempt'), ['_token' => 'stale', 'email' => 'sara@example.com', 'password' => 'x']);

        $r->assertRedirect(route('login'))->assertSessionHasErrors(['email' => __('app.auth.session_expired')]);
    }
}

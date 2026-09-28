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
        Mail::assertSent(VerifyEmailMail::class, 1);
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

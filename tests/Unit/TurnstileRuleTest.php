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

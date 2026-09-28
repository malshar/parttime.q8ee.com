<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function fakeTurnstile(bool $success = true): void
    {
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => $success]),
            'api.pwnedpasswords.com/*' => Http::response('', 200),
        ]);
    }
}

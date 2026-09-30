<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [\App\Http\Middleware\SetLocale::class]);
        $middleware->alias(['role' => \App\Http\Middleware\EnsureRole::class]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn ($request) => $request->user()->isAdmin() ? route('admin.dashboard') : route('instructor.home'));
        // Behind Cloudflare. '*' trusts any proxy (a direct hit on the origin could then
        // forge X-Forwarded-For); production sets TRUSTED_PROXIES to Cloudflare's ranges
        // (deploy/cloudflare-trusted-proxies.sh) so only the edge can set the client IP.
        $proxies = (string) env('TRUSTED_PROXIES', '*');
        $middleware->trustProxies(at: $proxies === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $proxies)))));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['civil_id', 'iban', 'basic_salary', 'total_salary']);

        $exceptions->render(function (\Illuminate\Session\TokenMismatchException $e, \Illuminate\Http\Request $request) {
            return redirect()->back()
                ->withInput($request->except(['_token', 'password', 'password_confirmation', 'civil_id', 'iban', 'basic_salary', 'total_salary']))
                ->withErrors(['page_expired' => __('app.common.page_expired')]);
        });
    })->create();

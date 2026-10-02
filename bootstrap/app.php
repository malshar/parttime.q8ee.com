<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [SetLocale::class]);
        $middleware->alias(['role' => EnsureRole::class]);
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

        // TokenMismatchException is converted to a generic 419 HttpException by the
        // framework's exception handler before renderable callbacks run, so this must
        // be matched by status code rather than by the original exception class. Only
        // handle it when it actually wraps a TokenMismatchException: some app code
        // (e.g. the section import "confirm" step) deliberately aborts with 419 for
        // an unrelated "expired session data" case and expects a plain 419 response.
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419 || ! $e->getPrevious() instanceof TokenMismatchException) {
                return null;
            }

            if (! $request->user()) {
                return redirect()->route('login')->withErrors(['email' => __('app.auth.session_expired')]);
            }

            return redirect()->back()
                ->withInput($request->except(['_token', 'password', 'password_confirmation', 'civil_id', 'iban', 'basic_salary', 'total_salary']))
                ->withErrors(['page_expired' => __('app.common.page_expired')]);
        });
    })->create();

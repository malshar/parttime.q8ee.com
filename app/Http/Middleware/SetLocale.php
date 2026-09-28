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

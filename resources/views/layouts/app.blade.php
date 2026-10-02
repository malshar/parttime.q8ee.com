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
            @auth
                <span class="navbar-text text-white small ms-auto">
                    {{ auth()->user()->name }}
                    <span class="badge bg-light text-dark">{{ __('app.auth.roles.'.auth()->user()->role) }}</span>
                </span>
            @endauth
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

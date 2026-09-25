<!doctype html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- The panel's fetch calls read this. Session auth with a CSRF token
         means the browser needs no second credential and there is no
         long-lived API token to leak. --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'codeinchrome')</title>
    <meta name="description" content="Laravel hosting where the AI does the work and the code stays yours.">
    {{-- Blocking on purpose: sets the theme before the first paint. --}}
    <script src="/theme.js?v={{ filemtime(public_path('theme.js')) }}"></script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="h-full bg-neutral-950 text-neutral-200 antialiased">
<div class="min-h-full flex flex-col">
    <header class="border-b border-neutral-800">
        <div class="mx-auto max-w-5xl px-6 h-14 flex items-center justify-between">
            <a href="{{ route('home') }}" class="font-semibold tracking-tight text-neutral-100">
                code<span class="text-teal-400">in</span>chrome
            </a>
            <nav class="flex items-center gap-4 text-sm">
                <button type="button" data-theme-toggle class="text-neutral-400 hover:text-neutral-200" title="Switch between system, light and dark">Theme: <span data-theme-label>System</span></button>
                @auth
                    <a href="{{ route('account') }}" class="text-neutral-500 hover:text-neutral-300 hidden sm:inline">{{ auth()->user()->email }}</a>
                    <a href="{{ route('dashboard') }}" class="text-neutral-300 hover:text-white">Sites</a>
                    <a href="{{ route('billing') }}" class="text-neutral-300 hover:text-white">Billing</a>
                    <form method="POST" action="{{ route('logout') }}">@csrf
                        <button class="text-neutral-400 hover:text-white">Sign out</button>
                    </form>
                @else
                    <a href="{{ route('login') }}" class="text-neutral-300 hover:text-white">Sign in</a>
                    <a href="{{ route('register') }}" class="rounded-md bg-teal-500 px-3 py-1.5 font-medium text-neutral-950 hover:bg-teal-400">Start free</a>
                @endauth
            </nav>
        </div>
    </header>

    <main class="flex-1 mx-auto w-full max-w-5xl px-6 py-10">
        @if (session('status'))
            <div class="mb-6 rounded-md border border-teal-800 bg-teal-950/50 px-4 py-3 text-sm text-teal-200">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="mb-6 rounded-md border border-red-900 bg-red-950/50 px-4 py-3 text-sm text-red-200">{{ session('error') }}</div>
        @endif
        @yield('content')
    </main>

    <footer class="border-t border-neutral-800 py-6 text-xs text-neutral-600">
        <div class="mx-auto max-w-5xl px-6 flex flex-wrap items-center justify-between gap-3">
            <span>Your code, your server. Take it with you.</span>
            <nav class="flex flex-wrap gap-4">
                <a href="{{ route('pricing') }}" class="hover:text-neutral-300">Pricing</a>
                <a href="{{ route('explore') }}" class="hover:text-neutral-300">Explore</a>
                <a href="{{ route('terms') }}" class="hover:text-neutral-300">Terms</a>
                <a href="{{ route('privacy') }}" class="hover:text-neutral-300">Privacy</a>
                <a href="{{ route('refunds') }}" class="hover:text-neutral-300">Refunds</a>
                <a href="{{ config('legal.source.url') }}" class="hover:text-neutral-300" rel="noopener" data-source-link>Source on GitHub</a>
                <a href="{{ route('report') }}" class="hover:text-neutral-300">Report a site</a>
                <a href="mailto:{{ config('legal.support_email') }}" class="hover:text-neutral-300">{{ config('legal.support_email') }}</a>
            </nav>
        </div>
    </footer>
</div>
</body>
</html>

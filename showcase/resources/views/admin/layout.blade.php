<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title', 'Admin') - {{ config('shop.name') }}</title>
    <link rel="stylesheet" href="/css/shop.css">
</head>
<body>
<div class="admin">
    <aside>
        <a href="{{ route('shop') }}" class="brand" style="color: #fff; margin-bottom: 24px">Ember &amp; Oak</a>
        <a href="{{ route('admin.dashboard') }}" class="{{ request()->routeIs('admin.dashboard') ? 'on' : '' }}">Overview</a>
        <a href="{{ route('admin.orders') }}" class="{{ request()->routeIs('admin.orders') ? 'on' : '' }}">Orders</a>
        <a href="{{ route('admin.products') }}" class="{{ request()->routeIs('admin.products*') ? 'on' : '' }}">Products</a>
        <form method="POST" action="{{ route('admin.logout') }}" style="margin-top: 24px">@csrf
            <button class="btn ghost" style="color: #c9cec8; border-color: #3a433e">Sign out</button>
        </form>
    </aside>
    <main>
        @if (auth()->user()?->is_demo)
            <p class="demo-banner">You are signed in with the public demo login. Look around - everything is real - but nothing can be changed.</p>
        @endif
        @if (session('status'))<p class="flash" role="status">{{ session('status') }}</p>@endif
        @if (session('error'))<p class="flash error" role="alert">{{ session('error') }}</p>@endif
        @yield('content')
    </main>
</div>
</body>
</html>

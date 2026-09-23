<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('shop.name').' - small-batch coffee')</title>
    <meta name="description" content="Small-batch coffee, roasted to order. A demo store built by an AI agent in the codeinchrome editor.">
    <link rel="stylesheet" href="/css/shop.css">
</head>
<body>
<header class="top">
    <div class="wrap">
        <a href="{{ route('shop') }}" class="brand">Ember <span>&amp;</span> Oak</a>
        <nav aria-label="Shop">
            <a href="{{ route('shop') }}">Coffee</a>
            <a href="{{ route('admin.dashboard') }}">Admin</a>
            <a href="{{ route('cart') }}">Bag<span class="bagcount">{{ array_sum(session('cart', [])) }}</span></a>
        </nav>
    </div>
</header>
<main class="wrap">
    @if (session('status'))<p class="flash" role="status">{{ session('status') }}</p>@endif
    @if (session('error'))<p class="flash error" role="alert">{{ session('error') }}</p>@endif
    @yield('content')
</main>
<footer class="foot">
    <div class="wrap">
        <span>A demo store. Payments run in Stripe's test mode: no card is ever charged.</span>
        <span>Built by an AI agent in the <a href="https://codeinchrome.com">codeinchrome</a> editor.</span>
    </div>
</footer>
</body>
</html>

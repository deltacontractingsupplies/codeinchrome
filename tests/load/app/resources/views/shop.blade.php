<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Shop - {{ $category }}</title></head>
<body>
<header><h1>Shop</h1><p>{{ $total }} products in stock</p><p>Cart: {{ $cart }}</p></header>
<main>
    <h2>{{ $category }}</h2>
    <ul>
    @foreach ($products as $p)
        <li>
            <h3>{{ $p->name }}</h3>
            <p>{{ \Illuminate\Support\Str::limit($p->description, 80) }}</p>
            <p>${{ number_format($p->price_cents / 100, 2) }} @if ($p->stock === 0) - sold out @endif</p>
        </li>
    @endforeach
    </ul>
</main>
</body></html>

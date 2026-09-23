@extends('layouts.shop')
@section('title', $product->name.' - '.config('shop.name'))
@section('content')
<article class="product">
    @include('shop._bag')
    <div>
        <p class="meta"><a href="{{ route('shop') }}">Coffee</a> / {{ $product->origin }}</p>
        <h1>{{ $product->name }}</h1>
        <p class="notes">{{ $product->notes }}</p>
        <p>{{ $product->description }}</p>
        <dl>
            <dt>Origin</dt><dd>{{ $product->origin }}</dd>
            <dt>Roast</dt><dd>{{ ucfirst($product->roast) }}</dd>
            <dt>Size</dt><dd>250 g, whole bean</dd>
            <dt>In stock</dt><dd>{{ $product->stock > 0 ? $product->stock.' bags' : 'Sold out' }}</dd>
        </dl>
        <p style="font-size: 24px; font-weight: 600; margin: 0 0 16px">{{ $product->price() }}</p>
        @if ($product->stock > 0)
            <form method="POST" action="{{ route('cart.add', $product) }}">@csrf
                <button class="btn">Add to bag</button>
            </form>
        @else
            <p class="meta">Sold out - back after the next roast.</p>
        @endif
    </div>
</article>
@if ($more->isNotEmpty())
    <h2 style="font: 500 24px var(--serif)">Also {{ $product->roast }}</h2>
    <div class="grid">
        @foreach ($more as $product)
            <a class="card" href="{{ route('product', $product) }}">@include('shop._bag')<h3>{{ $product->name }}</h3><div class="price">{{ $product->price() }}</div></a>
        @endforeach
    </div>
@endif
@endsection

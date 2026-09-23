@extends('layouts.shop')
@section('content')
<section class="intro">
    <h1>Coffee roasted on Monday, at your door by Thursday.</h1>
    <p>Twelve coffees from farms we know by name, roasted in small batches and shipped the same week. Free shipping over $40.</p>
</section>
<nav class="roasts" aria-label="Filter by roast">
    <a href="{{ route('shop') }}" class="{{ $roast ? '' : 'on' }}">All</a>
    @foreach (['light', 'medium', 'dark'] as $r)
        <a href="{{ route('shop', ['roast' => $r]) }}" class="{{ $roast === $r ? 'on' : '' }}">{{ ucfirst($r) }}</a>
    @endforeach
</nav>
<div class="grid">
    @foreach ($products as $product)
        <a class="card" href="{{ route('product', $product) }}">
            @include('shop._bag')
            <h3>{{ $product->name }}</h3>
            <div class="meta">{{ $product->notes }}</div>
            <div class="price">{{ $product->price() }} <span class="meta">/ 250 g</span></div>
        </a>
    @endforeach
</div>
@endsection

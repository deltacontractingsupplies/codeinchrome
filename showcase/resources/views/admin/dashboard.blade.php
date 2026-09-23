@extends('admin.layout')
@section('title', 'Overview')
@section('content')
<h1>Overview</h1>
<div class="stats">
    <div><b>{{ $orders }}</b><span>paid orders</span></div>
    <div><b>${{ number_format($revenue / 100, 2) }}</b><span>revenue (test mode)</span></div>
    <div><b>{{ $lowStock->count() }}</b><span>coffees running low</span></div>
</div>
<h2 style="font: 500 20px var(--serif)">Latest orders</h2>
@include('admin._orders', ['orders' => $recent])
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 32px; margin-top: 32px">
    <section>
        <h2 style="font: 500 20px var(--serif)">Best sellers</h2>
        @forelse ($top as $t)<p>{{ $t->name }} <span class="meta">- {{ $t->sold }} sold</span></p>@empty<p class="meta">No sales yet. Buy something with the test card.</p>@endforelse
    </section>
    <section>
        <h2 style="font: 500 20px var(--serif)">Running low</h2>
        @forelse ($lowStock as $p)<p><a href="{{ route('admin.products.edit', $p) }}">{{ $p->name }}</a> <span class="meta">- {{ $p->stock }} left</span></p>@empty<p class="meta">Everything is well stocked.</p>@endforelse
    </section>
</div>
@endsection

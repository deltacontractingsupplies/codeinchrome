@extends('layouts.shop')
@section('title', 'Thank you - '.config('shop.name'))
@section('content')
<section style="padding: 56px 0 72px; max-width: 620px">
    <h1 style="font: 500 40px/1.1 var(--serif)">Thank you. Your order is on the roasting list.</h1>
    <p>Order <b>{{ $order->reference }}</b>. Pay <b>{{ $order->total() }}</b> in cash when it arrives.</p>
    <ul>
        @foreach ($order->items as $item)<li>{{ $item->quantity }} x {{ $item->name }}</li>@endforeach
    </ul>
    <p><a class="btn ghost" href="{{ route('shop') }}">Back to the coffee</a>
       <a class="btn ghost" href="{{ route('admin.orders') }}">See it in the admin panel</a></p>
</section>
@endsection

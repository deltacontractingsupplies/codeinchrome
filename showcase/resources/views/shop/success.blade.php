@extends('layouts.shop')
@section('title', 'Thank you - '.config('shop.name'))
@section('content')
<section style="padding: 56px 0 72px; max-width: 620px">
    @if ($order->status === 'paid')
        <h1 style="font: 500 40px/1.1 var(--serif)">Thank you. Your coffee is on the roasting list.</h1>
        <p>Order <b>{{ $order->reference }}</b>, {{ $order->total() }}, paid. A receipt goes to {{ $order->maskedEmail() }}.</p>
    @else
        <h1 style="font: 500 40px/1.1 var(--serif)">We are waiting for the payment to confirm.</h1>
        <p>Order <b>{{ $order->reference }}</b>. Refresh this page in a moment.</p>
    @endif
    <ul>
        @foreach ($order->items as $item)<li>{{ $item->quantity }} x {{ $item->name }}</li>@endforeach
    </ul>
    <p><a class="btn ghost" href="{{ route('shop') }}">Back to the coffee</a>
       <a class="btn ghost" href="{{ route('admin.orders') }}">See it in the admin panel</a></p>
</section>
@endsection

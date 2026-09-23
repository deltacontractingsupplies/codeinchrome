@extends('layouts.shop')
@section('title', 'Your bag - '.config('shop.name'))
@section('content')
<h1 style="font: 500 36px var(--serif); margin: 40px 0 0">Your bag</h1>
@if ($lines === [])
    <p>Nothing in it yet. <a href="{{ route('shop') }}">Choose a coffee</a>.</p>
@else
    <table class="lines">
        <thead><tr><th scope="col">Coffee</th><th scope="col">Quantity</th><th scope="col" class="num">Price</th></tr></thead>
        <tbody>
        @foreach ($lines as $line)
            <tr>
                <td><a href="{{ route('product', $line['product']) }}">{{ $line['product']->name }}</a></td>
                <td>
                    <form method="POST" action="{{ route('cart.update', $line['product']) }}" style="display: flex; gap: 8px">@csrf @method('PATCH')
                        <label class="meta" for="q{{ $line['product']->id }}" style="position: absolute; left: -9999px">Quantity of {{ $line['product']->name }}</label>
                        <input id="q{{ $line['product']->id }}" class="qty" type="number" name="quantity" min="0" max="10" value="{{ $line['quantity'] }}">
                        <button class="btn ghost" style="padding: 6px 12px">Update</button>
                    </form>
                </td>
                <td class="num">${{ number_format($line['line_cents'] / 100, 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <div class="totals">
        <div><span>Subtotal</span><span>${{ number_format($subtotal / 100, 2) }}</span></div>
        <div><span>Shipping</span><span>{{ $shipping ? '$'.number_format($shipping / 100, 2) : 'Free' }}</span></div>
        <div class="grand"><span>Total</span><span>${{ number_format($total / 100, 2) }}</span></div>
        <form method="POST" action="{{ route('checkout') }}" style="margin-top: 16px">@csrf
            <button class="btn" style="width: 100%">Pay with card</button>
        </form>
        <p class="testcard">Test mode: pay with card number 4242 4242 4242 4242, any future expiry date and any CVC.</p>
    </div>
@endif
@endsection

@if ($orders->isEmpty())
    <p class="meta">No orders yet.</p>
@else
<table class="lines">
    <thead><tr><th scope="col">Order</th><th scope="col">Customer</th><th scope="col">Items</th><th scope="col">Payment</th><th scope="col" class="num">Total</th><th scope="col">Placed</th></tr></thead>
    <tbody>
    @foreach ($orders as $order)
        <tr>
            <td>{{ $order->reference }}</td>
            {{-- Anyone can sign in as the demo admin, so it never sees a
                 visitor's full email address. --}}
            <td>
                @if (auth()->user()->is_demo)
                    {{ $order->maskedName() }} &middot; {{ $order->maskedEmail() }} &middot; {{ $order->maskedPhone() }}
                @else
                    {{ $order->name ?? '-' }} &middot; {{ $order->email ?? '-' }} &middot; {{ $order->phone ?? '-' }}
                    <div class="meta">{{ $order->address }}</div>
                @endif
            </td>
            <td>{{ $order->items->sum('quantity') }}</td>
            <td><span class="pill {{ $order->status }}">{{ $order->status === 'placed' ? 'cash on delivery' : $order->status }}</span></td>
            <td class="num">{{ $order->total() }}</td>
            <td class="meta">{{ $order->created_at->diffForHumans() }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
@endif

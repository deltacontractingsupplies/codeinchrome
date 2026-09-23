@if ($orders->isEmpty())
    <p class="meta">No orders yet.</p>
@else
<table class="lines">
    <thead><tr><th scope="col">Order</th><th scope="col">Customer</th><th scope="col">Items</th><th scope="col">Status</th><th scope="col" class="num">Total</th><th scope="col">Placed</th></tr></thead>
    <tbody>
    @foreach ($orders as $order)
        <tr>
            <td>{{ $order->reference }}</td>
            {{-- Anyone can sign in as the demo admin, so it never sees a
                 visitor's full email address. --}}
            <td>{{ auth()->user()->is_demo ? $order->maskedEmail() : ($order->email ?? '-') }}</td>
            <td>{{ $order->items->sum('quantity') }}</td>
            <td><span class="pill {{ $order->status }}">{{ $order->status }}</span></td>
            <td class="num">{{ $order->total() }}</td>
            <td class="meta">{{ $order->created_at->diffForHumans() }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
@endif

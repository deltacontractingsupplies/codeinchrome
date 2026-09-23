<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ShopController extends Controller
{
    public function index(Request $request): View
    {
        $roast = $request->query('roast');
        $products = Product::query()
            ->when(in_array($roast, ['light', 'medium', 'dark'], true), fn ($q) => $q->where('roast', $roast))
            ->orderByDesc('featured')->orderBy('name')->get();

        return view('shop.index', ['products' => $products, 'roast' => $roast]);
    }

    public function show(Product $product): View
    {
        $more = Product::where('id', '!=', $product->id)->where('roast', $product->roast)->limit(3)->get();

        return view('shop.product', compact('product', 'more'));
    }

    // ── the cart, kept in the session as [product id => quantity] ──

    private function cart(Request $request): array
    {
        return $request->session()->get('cart', []);
    }

    public function cartView(Request $request): View
    {
        return view('shop.cart', $this->totals($this->cart($request)));
    }

    public function add(Request $request, Product $product): RedirectResponse
    {
        $cart = $this->cart($request);
        $cart[$product->id] = min(($cart[$product->id] ?? 0) + 1, $product->stock, 10);
        $request->session()->put('cart', $cart);

        return redirect()->route('cart')->with('status', "{$product->name} is in your bag.");
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $qty = (int) $request->validate(['quantity' => ['required', 'integer', 'min:0', 'max:10']])['quantity'];
        $cart = $this->cart($request);
        if ($qty === 0) {
            unset($cart[$product->id]);
        } else {
            $cart[$product->id] = min($qty, $product->stock);
        }
        $request->session()->put('cart', $cart);

        return redirect()->route('cart');
    }

    /** @return array{lines: array, subtotal: int, shipping: int, total: int} */
    private function totals(array $cart): array
    {
        $products = Product::whereIn('id', array_keys($cart))->get()->keyBy('id');
        $lines = [];
        $subtotal = 0;
        foreach ($cart as $id => $qty) {
            if ($p = $products->get($id)) {
                $lines[] = ['product' => $p, 'quantity' => $qty, 'line_cents' => $p->price_cents * $qty];
                $subtotal += $p->price_cents * $qty;
            }
        }
        $shipping = $subtotal === 0 || $subtotal >= config('shop.free_shipping_over_cents') ? 0 : config('shop.shipping_cents');

        return ['lines' => $lines, 'subtotal' => $subtotal, 'shipping' => $shipping, 'total' => $subtotal + $shipping];
    }

    // ── checkout, through Stripe Checkout in TEST mode ──

    public function checkout(Request $request): RedirectResponse
    {
        $secret = (string) config('shop.stripe_secret');
        // Test keys only: this is a public demo and must never take real money.
        if (! str_starts_with($secret, 'sk_test_')) {
            return back()->with('error', 'Checkout is switched off: this demo store has no Stripe test key yet.');
        }
        $t = $this->totals($this->cart($request));
        if ($t['lines'] === []) {
            return redirect()->route('shop')->with('error', 'Your bag is empty.');
        }

        $order = DB::transaction(function () use ($t) {
            $order = Order::create(['reference' => 'EO-'.strtoupper(Str::random(8)), 'total_cents' => $t['total']]);
            foreach ($t['lines'] as $line) {
                $order->items()->create([
                    'product_id' => $line['product']->id, 'name' => $line['product']->name,
                    'quantity' => $line['quantity'], 'price_cents' => $line['product']->price_cents,
                ]);
            }

            return $order;
        });

        $form = [
            'mode' => 'payment',
            'client_reference_id' => $order->reference,
            'metadata[order]' => $order->reference,
            'success_url' => route('checkout.success').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('cart'),
        ];
        $i = 0;
        foreach ($t['lines'] as $line) {
            $form["line_items[$i][quantity]"] = $line['quantity'];
            $form["line_items[$i][price_data][currency]"] = config('shop.currency');
            $form["line_items[$i][price_data][unit_amount]"] = $line['product']->price_cents;
            $form["line_items[$i][price_data][product_data][name]"] = $line['product']->name.' - 250 g';
            $i++;
        }
        if ($t['shipping'] > 0) {
            $form["line_items[$i][quantity]"] = 1;
            $form["line_items[$i][price_data][currency]"] = config('shop.currency');
            $form["line_items[$i][price_data][unit_amount]"] = $t['shipping'];
            $form["line_items[$i][price_data][product_data][name]"] = 'Shipping';
        }

        $response = Http::asForm()->withToken($secret)->timeout(20)->post('https://api.stripe.com/v1/checkout/sessions', $form);
        if (! $response->successful()) {
            $order->update(['status' => 'cancelled']);
            report(new \RuntimeException('Stripe refused the checkout: '.$response->json('error.message')));

            return back()->with('error', 'The payment page could not be opened. Please try again.');
        }
        $order->update(['stripe_session_id' => $response->json('id')]);

        return redirect()->away($response->json('url'));
    }

    /**
     * Stripe sends the customer back here. The order is marked paid only
     * after asking Stripe itself - the query string alone proves nothing.
     */
    public function success(Request $request): View|RedirectResponse
    {
        $id = (string) $request->query('session_id');
        $order = $id !== '' ? Order::where('stripe_session_id', $id)->first() : null;
        if (! $order) {
            return redirect()->route('shop');
        }
        if ($order->status !== 'paid') {
            $session = Http::withToken((string) config('shop.stripe_secret'))->timeout(20)
                ->get('https://api.stripe.com/v1/checkout/sessions/'.urlencode($id));
            if ($session->successful() && $session->json('payment_status') === 'paid') {
                DB::transaction(function () use ($order, $session) {
                    $fresh = Order::lockForUpdate()->find($order->id);
                    if ($fresh->status === 'paid') {
                        return; // a second visit to this page does not sell the stock twice
                    }
                    $fresh->update(['status' => 'paid', 'paid_at' => now(), 'email' => $session->json('customer_details.email')]);
                    foreach ($fresh->items as $item) {
                        Product::where('id', $item->product_id)->where('stock', '>=', $item->quantity)->decrement('stock', $item->quantity);
                    }
                });
                $order->refresh();
            }
        }
        $request->session()->forget('cart');

        return view('shop.success', ['order' => $order->load('items')]);
    }
}

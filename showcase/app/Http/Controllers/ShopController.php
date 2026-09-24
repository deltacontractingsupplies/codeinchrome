<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    // ── checkout: cash on delivery ──

    /**
     * Place the order. Nothing is charged: the customer pays the total in
     * cash when it arrives. The stock is taken at once, inside the same
     * transaction, and only if there is enough - two people ordering the last
     * bag cannot both get it.
     */
    public function checkout(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['required', 'string', 'max:40', 'regex:/^[0-9 +()\-]{6,40}$/'],
            'address' => ['required', 'string', 'max:500'],
        ]);
        $t = $this->totals($this->cart($request));
        if ($t['lines'] === []) {
            return redirect()->route('shop')->with('error', 'Your bag is empty.');
        }

        $order = DB::transaction(function () use ($t, $data) {
            foreach ($t['lines'] as $line) {
                $product = Product::lockForUpdate()->find($line['product']->id);
                if (! $product || $product->stock < $line['quantity']) {
                    return null;
                }
                $product->decrement('stock', $line['quantity']);
            }
            $order = Order::create($data + [
                'reference' => 'EO-'.strtoupper(Str::random(8)),
                'total_cents' => $t['total'],
                'status' => 'placed',
                'payment_method' => 'cash',
            ]);
            foreach ($t['lines'] as $line) {
                $order->items()->create([
                    'product_id' => $line['product']->id, 'name' => $line['product']->name,
                    'quantity' => $line['quantity'], 'price_cents' => $line['product']->price_cents,
                ]);
            }

            return $order;
        });
        if (! $order) {
            return back()->withInput()->with('error', 'Something in your bag has just sold out. Please check the quantities.');
        }

        $request->session()->forget('cart');
        // The confirmation shows only the order this visitor just placed.
        $request->session()->put('last_order', $order->id);

        return redirect()->route('checkout.success');
    }

    public function success(Request $request): View|RedirectResponse
    {
        $order = Order::with('items')->find($request->session()->get('last_order'));
        if (! $order) {
            return redirect()->route('shop');
        }

        return view('shop.success', ['order' => $order]);
    }
}

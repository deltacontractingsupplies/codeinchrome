<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function showLogin(): View
    {
        return view('admin.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        if (! Auth::attempt($credentials)) {
            return back()->withErrors(['email' => 'Those details do not match.'])->onlyInput('email');
        }
        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('shop');
    }

    public function dashboard(): View
    {
        $paid = Order::where('status', 'paid');

        return view('admin.dashboard', [
            'orders' => (clone $paid)->count(),
            'revenue' => (clone $paid)->sum('total_cents'),
            'lowStock' => Product::where('stock', '<', 15)->orderBy('stock')->get(),
            'recent' => Order::with('items')->latest()->limit(8)->get(),
            'top' => OrderItem::query()->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.status', 'paid')
                ->select('order_items.name', DB::raw('SUM(order_items.quantity) as sold'))
                ->groupBy('order_items.name')->orderByDesc('sold')->limit(5)->get(),
        ]);
    }

    public function orders(): View
    {
        return view('admin.orders', ['orders' => Order::with('items')->latest()->paginate(20)]);
    }

    public function products(): View
    {
        return view('admin.products', ['products' => Product::orderBy('name')->get()]);
    }

    public function edit(Product $product): View
    {
        return view('admin.product-edit', compact('product'));
    }

    public function updateProduct(Request $request, Product $product): RedirectResponse
    {
        $product->update($request->validate([
            'name' => ['required', 'string', 'max:120'],
            'price_cents' => ['required', 'integer', 'min:50', 'max:100000'],
            'stock' => ['required', 'integer', 'min:0', 'max:10000'],
            'notes' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:2000'],
        ]));

        return redirect()->route('admin.products')->with('status', "{$product->name} saved.");
    }
}

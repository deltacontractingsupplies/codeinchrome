<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));

// A storefront page as a visitor sees it: a session, a category listing read
// from MySQL, a count for the cart badge, and a Blade render.
Route::get('/shop', function (Request $request) {
    $category = 'cat-' . random_int(0, 7);
    $request->session()->put('last_category', $category);
    $products = DB::table('bench_products')->where('category', $category)
        ->orderBy('price_cents')->limit(24)->get();
    $total = DB::table('bench_products')->where('stock', '>', 0)->count();

    return view('shop', ['products' => $products, 'category' => $category, 'total' => $total,
        'cart' => count($request->session()->get('cart', []))]);
});

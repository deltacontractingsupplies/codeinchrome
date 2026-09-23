<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\ShopController;
use App\Http\Middleware\Admin;
use App\Http\Middleware\DemoReadOnly;
use Illuminate\Support\Facades\Route;

Route::get('/', [ShopController::class, 'index'])->name('shop');
Route::get('/coffee/{product}', [ShopController::class, 'show'])->name('product');
Route::get('/bag', [ShopController::class, 'cartView'])->name('cart');
Route::post('/bag/{product}', [ShopController::class, 'add'])->name('cart.add');
Route::patch('/bag/{product}', [ShopController::class, 'update'])->name('cart.update');
Route::post('/checkout', [ShopController::class, 'checkout'])->middleware('throttle:10,1')->name('checkout');
Route::get('/checkout/done', [ShopController::class, 'success'])->name('checkout.success');

// Laravel's auth middleware sends guests to the route named "login".
Route::redirect('/login', '/admin/login')->name('login');

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/login', [AdminController::class, 'showLogin'])->name('login');
    Route::post('/login', [AdminController::class, 'login'])->middleware('throttle:10,1');
    Route::middleware(['auth', Admin::class, DemoReadOnly::class])->group(function () {
        Route::post('/logout', [AdminController::class, 'logout'])->name('logout');
        Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');
        Route::get('/orders', [AdminController::class, 'orders'])->name('orders');
        Route::get('/products', [AdminController::class, 'products'])->name('products');
        Route::get('/products/{product}', [AdminController::class, 'edit'])->name('products.edit');
        Route::put('/products/{product}', [AdminController::class, 'updateProduct'])->name('products.update');
    });
});

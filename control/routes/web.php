<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    // Rate limited because these are the two endpoints an attacker gets to
    // call as often as we let them.
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/sites', [SiteController::class, 'index'])->name('dashboard');
    // Provisioning creates a container and a DNS record, so it is throttled
    // separately and much harder than a page view.
    Route::post('/sites', [SiteController::class, 'store'])->middleware('throttle:10,1')->name('sites.store');
    Route::delete('/sites/{site}', [SiteController::class, 'destroy'])->name('sites.destroy');
});

/*
 * Lemon Squeezy posts here. The CSRF exclusion for `webhooks/*` lives in
 * bootstrap/app.php - an external service has no session and no CSRF token,
 * and its authenticity is established by an HMAC signature instead. See
 * WebhookController, which refuses outright when no secret is configured.
 */
Route::post('/webhooks/lemonsqueezy', WebhookController::class)
    ->name('webhooks.lemonsqueezy');

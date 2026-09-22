<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\ConsoleController;
use App\Http\Controllers\DatabaseController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\TwoFactorController;
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
    Route::get('/two-factor-challenge', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');

    // Password reset. Every action 404s unless mail really leaves the
    // building (fleet.mail_enabled); throttled because each one sends mail.
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:10,1')->name('password.update');
    // Brute-force limiting is per account, inside the controller; this is
    // only a coarse ceiling against floods.
    Route::post('/two-factor-challenge', [TwoFactorController::class, 'verify'])->middleware('throttle:30,1');
});

// auth.session: sessions carry a hash of the password, so changing it signs
// out every other session (AccountController::password).
Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/status', StatusController::class)->name('status');
    Route::get('/account', [AccountController::class, 'show'])->name('account');
    Route::put('/account/password', [AccountController::class, 'password'])->middleware('throttle:10,1')->name('account.password');
    Route::delete('/account', [AccountController::class, 'destroy'])->middleware('throttle:5,1')->name('account.destroy');
    Route::get('/account/two-factor', [TwoFactorController::class, 'setup'])->name('two-factor.setup');
    Route::post('/account/two-factor', [TwoFactorController::class, 'confirm'])->middleware('throttle:10,1')->name('two-factor.confirm');
    Route::delete('/account/two-factor', [TwoFactorController::class, 'disable'])->name('two-factor.disable');
    Route::get('/billing', [BillingController::class, 'index'])->name('billing');
    Route::post('/billing/checkout', [BillingController::class, 'checkout'])->middleware('throttle:10,1')->name('billing.checkout');
    Route::get('/billing/return', [BillingController::class, 'return'])->name('billing.return');
    Route::get('/sites', [SiteController::class, 'index'])->name('dashboard');
    // Provisioning creates a container and a DNS record, so it is throttled
    // separately and much harder than a page view.
    Route::post('/sites', [SiteController::class, 'store'])->middleware('throttle:10,1')->name('sites.store');
    Route::delete('/sites/{site}', [SiteController::class, 'destroy'])->name('sites.destroy');

    // The panel's file operations. Session-authenticated like the rest of the
    // dashboard, so the browser needs no second credential and there is no
    // long-lived token to leak.
    Route::get('/sites/{site}/edit', [SiteController::class, 'edit'])->name('sites.edit');

    Route::get('/sites/{site}/domains', [DomainController::class, 'index'])->name('domains.index');
    Route::post('/sites/{site}/domains', [DomainController::class, 'store'])->middleware('throttle:20,1')->name('domains.store');
    // Verification queries public DNS; throttled so it cannot be used to make
    // us hammer resolvers.
    Route::post('/sites/{site}/domains/{domain}/verify', [DomainController::class, 'verify'])->middleware('throttle:20,1')->name('domains.verify');
    Route::delete('/sites/{site}/domains/{domain}', [DomainController::class, 'destroy'])->name('domains.destroy');
    Route::get('/sites/{site}/files', [FileController::class, 'index'])->name('files.index');
    Route::put('/sites/{site}/files', [FileController::class, 'store'])->name('files.store');
    Route::delete('/sites/{site}/files', [FileController::class, 'destroy'])->name('files.destroy');

    // Commands can run for minutes and cost CPU on a shared host.
    Route::post('/sites/{site}/command', [ConsoleController::class, 'run'])->middleware('throttle:20,1')->name('console.run');
    Route::get('/sites/{site}/logs', [ConsoleController::class, 'logs'])->middleware('throttle:60,1')->name('console.logs');

    // The database browser. Queries are throttled harder than file reads:
    // each one opens a MySQL connection on a host shared with other tenants.
    Route::get('/sites/{site}/db', [DatabaseController::class, 'tables'])->name('db.tables');
    Route::post('/sites/{site}/db/query', [DatabaseController::class, 'query'])->middleware('throttle:60,1')->name('db.query');
});

/*
 * Lemon Squeezy posts here. The CSRF exclusion for `webhooks/*` lives in
 * bootstrap/app.php - an external service has no session and no CSRF token,
 * and its authenticity is established by an HMAC signature instead. See
 * WebhookController, which refuses outright when no secret is configured.
 */
Route::post('/webhooks/lemonsqueezy', WebhookController::class)
    ->name('webhooks.lemonsqueezy');

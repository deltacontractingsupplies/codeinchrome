<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

/*
 * Lemon Squeezy posts here. Deliberately outside the `web` session middleware
 * that CSRF lives in: an external service has no session and no CSRF token,
 * and its authenticity is established by the HMAC signature instead - see
 * WebhookController, which refuses outright when no secret is configured.
 */
Route::post('/webhooks/lemonsqueezy', WebhookController::class)
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
    ->name('webhooks.lemonsqueezy');

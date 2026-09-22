<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Lemon Squeezy posts to this route from its own servers. It has no
         * session and no CSRF token, so CSRF verification rejects every real
         * delivery with 419 BEFORE the signature is ever checked.
         *
         * This is the canonical place for the exclusion, not
         * ->withoutMiddleware() on the route: that did not take effect here,
         * and the failure was invisible to the feature tests because Laravel
         * disables CSRF verification while testing. Only a real HTTP request
         * showed it - which is why the Playwright suite posts to this endpoint
         * unsigned and asserts on the status.
         *
         * Authenticity is established by the HMAC signature instead; see
         * WebhookController, which refuses outright when no secret is set.
         */
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
        ]);

        /*
         * File contents are data, byte for byte. Laravel's TrimStrings trims
         * every request string by default, so every save from the editor was
         * stripping the file's leading and trailing whitespace: the final
         * newline vanished, and a file that began with indentation was
         * altered - silently, on every save. Found by the browser suite
         * writing a file and reading it back, not by the feature tests, which
         * used toContain() and could not see a missing newline.
         */
        $middleware->trimStrings(except: [
            'content',
            // SQL too: whitespace inside a string literal is part of the
            // statement, and a trailing comment needs its newline.
            'sql',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

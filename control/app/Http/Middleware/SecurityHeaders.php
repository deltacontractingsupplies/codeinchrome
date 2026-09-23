<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every response the application renders.
 *
 * The Content-Security-Policy allows scripts, styles and connections from
 * this origin ONLY: no inline script, no inline style, no eval. That holds
 * because no page has an inline script, an inline event handler or a style
 * attribute - tests/Feature/SecurityHeadersTest.php keeps it that way. Even
 * if customer-controlled text were ever rendered as markup by mistake, the
 * browser would refuse to run anything in it.
 *
 * form-action includes Lemon Squeezy because choosing a plan POSTs here and
 * is then redirected to its checkout, and browsers apply form-action to the
 * redirect as well as to the form.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // A response that set its own policy keeps it: a file download says
        // `sandbox`, stricter than anything below, and must not be loosened.
        if (! $response->headers->has('Content-Security-Policy')) {
            // One page may add a nonce for style: the editor, whose code
            // editor (Monaco) builds <style> elements as it runs. It carries
            // this request's nonce (resources/js/csp-nonce.js); inline style
            // from anywhere else is still refused. Scripts are never nonced.
            $styleNonce = $request->attributes->get('csp_style_nonce');
            $response->headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self'",
                $styleNonce ? "style-src 'self' 'nonce-{$styleNonce}'" : "style-src 'self'",
                // Monaco positions its lines and line numbers with style
                // attributes in markup it builds. Attributes only: they
                // cannot run script, and img-src/font-src still keep them
                // from loading anything from elsewhere. <style> elements
                // stay nonce-only, scripts stay 'self'.
                ...($styleNonce ? ["style-src-attr 'unsafe-inline'"] : []),
                "img-src 'self' data:",
                "font-src 'self'",
                "connect-src 'self'",
                "object-src 'none'",
                "base-uri 'self'",
                "frame-ancestors 'none'",
                "form-action 'self' https://*.lemonsqueezy.com",
                'upgrade-insecure-requests',
            ]));
        }
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}

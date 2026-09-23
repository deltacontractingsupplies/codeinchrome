<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The demo admin login is published on codeinchrome.com, so anyone can use
 * it. It may look at everything and change nothing: every request that is
 * not a read is refused here, on the server - not merely hidden in the page.
 */
class DemoReadOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user?->is_demo && ! in_array($request->method(), ['GET', 'HEAD'], true)
            && ! $request->routeIs('admin.logout')) {
            return back()->with('error', 'This is the public demo login, so it is read-only: nothing was changed.');
        }

        return $next($request);
    }
}

<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Request every page of the app and report any that fail (the codeinchrome
// skill gives every app one): php artisan app:check [--as=<user id>].
Artisan::command('app:check {--as= : a user id to check signed in}', function () {
    // One real key per route parameter, so /coffee/{product} is checked too.
    $samples = ['product' => \App\Models\Product::query()->value((new \App\Models\Product)->getRouteKeyName())];
    if ($this->option('as')) {
        Auth::loginUsingId((int) $this->option('as'));
    }
    $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
    $bad = 0;
    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true) || str_starts_with($route->uri(), '_')) {
            continue;
        }
        $uri = preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => $samples[$m[1]] ?? '__missing__', $route->uri());
        if (str_contains($uri, '__missing__')) {
            $this->warn("skipped /{$route->uri()} (no sample for its parameter)");
            continue;
        }
        $path = '/'.ltrim($uri, '/');
        $request = \Illuminate\Http\Request::create($path, 'GET');
        $response = $kernel->handle($request);
        $status = $response->getStatusCode();
        $kernel->terminate($request, $response);
        if ($status >= 400) {
            $bad++;
            $this->error("$status $path");
        } else {
            $this->line("$status $path");
        }
    }
    $bad === 0 ? $this->info('every page answered') : $this->error("$bad page(s) failed");

    return $bad === 0 ? 0 : 1;
})->purpose('Request every page of the app and report any that fail');

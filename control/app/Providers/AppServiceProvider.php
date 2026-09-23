<?php

namespace App\Providers;

use App\Fleet\Stock;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // How many of each paid plan the fleet can still take (App\Fleet\Stock),
        // for every page that lists plans. For a signed-in customer the count
        // is theirs: what they hold now is released by a change of plan.
        View::composer(['partials.plans', 'billing'], function ($view) {
            $stock = app(Stock::class);
            $user = auth()->user();
            $view->with('stock', collect(config('billing.plans'))
                ->map(fn ($p, $key) => $p['price'] > 0
                    ? ($user ? $stock->availableFor($user, $key) : $stock->available($key))
                    : null)
                ->all());
        });

        /*
         * Every limit has its own NAME, and so its own bucket.
         *
         * The routes used to say `throttle:10,1`, and Laravel keys that by IP
         * (or user) alone - not by route. Register, login and every site action
         * shared one budget: ten failed logins made registration answer 429,
         * and a customer who created a site, added a domain and ran a few
         * commands in one minute was throttled everywhere. Found by the
         * end-to-end suite, which does exactly that from one address.
         */
        $by = fn (Request $r, string $suffix = '') => ($r->user()?->id ?? $r->ip()).$suffix;

        // Per address AND per IP: one attacker cannot lock a victim out by
        // failing their logins from elsewhere, and cannot spray many addresses
        // from one IP either (the per-IP ceiling).
        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(10)->by(strtolower((string) $r->input('email')).'|'.$r->ip()),
            Limit::perMinute(30)->by('ip:'.$r->ip()),
        ]);
        RateLimiter::for('register', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));
        RateLimiter::for('email-code', fn (Request $r) => Limit::perMinute(10)->by($by($r)));
        RateLimiter::for('password-mail', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));
        RateLimiter::for('account', fn (Request $r) => Limit::perMinute(10)->by($by($r)));
        RateLimiter::for('provision', fn (Request $r) => Limit::perMinute(10)->by($by($r)));
        RateLimiter::for('domains', fn (Request $r) => Limit::perMinute(20)->by($by($r)));
        RateLimiter::for('command', fn (Request $r) => Limit::perMinute(20)->by($by($r)));
        RateLimiter::for('db', fn (Request $r) => Limit::perMinute(60)->by($by($r)));
        RateLimiter::for('logs', fn (Request $r) => Limit::perMinute(60)->by($by($r)));
        RateLimiter::for('billing', fn (Request $r) => Limit::perMinute(10)->by($by($r)));
        RateLimiter::for('two-factor', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));

        //
    }
}

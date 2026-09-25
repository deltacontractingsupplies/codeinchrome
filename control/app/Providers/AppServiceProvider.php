<?php

namespace App\Providers;

use App\Fleet\Stock;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Apple\AppleExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Billing\Capacity::class);
        $this->app->singleton(\App\Support\FileIcons::class);
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Every scheduled job's outcome feeds monitoring (App\Fleet\Monitoring::recordJob).
        // A non-zero exit fires Finished and then Failed, an exception only
        // Failed: so success is Finished with exit 0, failure is Failed.
        Event::listen(\Illuminate\Console\Events\ScheduledTaskFinished::class, function ($e) {
            if ((int) $e->task->exitCode === 0) {
                app(\App\Fleet\Monitoring::class)->recordJob($e->task, true, 'last run succeeded in '.$e->runtime.' s');
            }
        });
        Event::listen(\Illuminate\Console\Events\ScheduledTaskFailed::class, function ($e) {
            app(\App\Fleet\Monitoring::class)->recordJob($e->task, false,
                'last run failed: '.mb_substr($e->exception->getMessage(), 0, 300));
        });

        // Sign in with Apple is a SocialiteProviders driver, registered here.
        Event::listen(
            SocialiteWasCalled::class,
            [AppleExtendSocialite::class, 'handle'],
        );

        // How many of each paid plan the fleet can still take (App\Fleet\Stock),
        // for every page that lists plans. For a signed-in customer the count
        // is theirs: what they hold now is released by a change of plan.
        View::composer(['partials.plans', 'billing'], function ($view) {
            $stock = app(Stock::class);
            $user = auth()->user();
            $view->with('stock', collect(\App\Billing\Sales::plans())
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
        // An IPv6 visitor holds a /64: keyed on the full address, a per-IP
        // limit was no limit (App\Auth\ClientNet, the second security audit).
        $ip = fn (Request $r) => \App\Auth\ClientNet::key($r->ip());
        $by = fn (Request $r, string $suffix = '') => ($r->user()?->id ?? $ip($r)).$suffix;

        // Per address AND per IP: one attacker cannot lock a victim out by
        // failing their logins from elsewhere, and cannot spray many addresses
        // from one IP either (the per-IP ceiling).
        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(10)->by(strtolower((string) $r->input('email')).'|'.$ip($r)),
            Limit::perMinute(30)->by('ip:'.$ip($r)),
        ]);
        // Hourly and daily too: a free account is a free site, and the minute
        // limit alone allowed thousands a day from one network.
        RateLimiter::for('register', fn (Request $r) => [
            Limit::perMinute(10)->by($ip($r)), Limit::perHour(20)->by('h:'.$ip($r)), Limit::perDay(50)->by('d:'.$ip($r)),
        ]);
        // Public and unauthenticated: enough for a person, not for a flood.
        RateLimiter::for('abuse-report', fn (Request $r) => [Limit::perMinute(3)->by($ip($r)), Limit::perHour(10)->by('h:'.$ip($r))]);
        RateLimiter::for('email-code', fn (Request $r) => Limit::perMinute(10)->by($by($r)));
        RateLimiter::for('password-mail', fn (Request $r) => [Limit::perMinute(5)->by($ip($r)), Limit::perHour(20)->by('h:'.$ip($r))]);
        RateLimiter::for('account', fn (Request $r) => Limit::perMinute(10)->by($by($r)));
        RateLimiter::for('provision', fn (Request $r) => Limit::perMinute(10)->by($by($r)));
        RateLimiter::for('domains', fn (Request $r) => Limit::perMinute(20)->by($by($r)));
        RateLimiter::for('command', fn (Request $r) => Limit::perMinute(20)->by($by($r)));
        // Read-only and capped at 20 s on the host, and cic.check's code
        // review makes several at once: it shared 'command' (20 a minute)
        // with artisan and failed on a busy session.
        RateLimiter::for('search', fn (Request $r) => Limit::perMinute(90)->by($by($r)));
        RateLimiter::for('db', fn (Request $r) => Limit::perMinute(60)->by($by($r)));
        // Completion fires as someone types: generous, but still bounded.
        RateLimiter::for('lsp', fn (Request $r) => Limit::perMinute(600)->by($by($r)));
        // Each MCP call boots the site's application: bounded like queries.
        RateLimiter::for('mcp', fn (Request $r) => Limit::perMinute(60)->by($by($r)));
        // PHP in the site's own app, and test sign-ins: an agent checks as it builds.
        RateLimiter::for('eval', fn (Request $r) => Limit::perMinute(60)->by($by($r)));
        // An agent testing what it built: a page and its assets, a form, a login.
        RateLimiter::for('site-request', fn (Request $r) => Limit::perMinute(300)->by($by($r)));
        // Each check makes ~50 requests to the site from outside.
        RateLimiter::for('exposure', fn (Request $r) => Limit::perMinute(6)->by($by($r)));
        // The public demo source pages: generous for a reader, not for a scraper.
        RateLimiter::for('demo-code', fn (Request $r) => Limit::perMinute(120)->by($ip($r)));
        RateLimiter::for('logs', fn (Request $r) => Limit::perMinute(60)->by($by($r)));
        RateLimiter::for('billing', fn (Request $r) => Limit::perMinute(10)->by($by($r)));
        RateLimiter::for('two-factor', fn (Request $r) => Limit::perMinute(30)->by($ip($r)));

        //
    }
}

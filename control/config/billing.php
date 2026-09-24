<?php

return [
    'store_id' => env('LEMONSQUEEZY_STORE_ID'),
    'api_key' => env('LEMONSQUEEZY_API_KEY'),
    'webhook_secret' => env('LEMONSQUEEZY_WEBHOOK_SECRET'),
    // Pause between retries of a dropped connection to the API. 0 in tests.
    'retry_sleep_ms' => (int) env('LEMONSQUEEZY_RETRY_SLEEP_MS', 500),

    /*
     * Lemon Squeezy cannot create products over its API - POST /v1/products
     * returns 405, they are dashboard-only. So each variant_id below is filled
     * in by hand once the product exists, and every code path that consumes
     * one treats a null as "this plan is not purchasable yet" rather than
     * as an error.
     */
    /*
     * The free plan is a trial: three days, no card. It reserves nothing -
     * a trial site takes room only if the fleet has room left over after
     * every paid plan (App\Fleet\Stock) - and when it ends unpaid the sites
     * are paused, then deleted after the grace period (App\Console\Commands\TrialsExpire).
     * A paying customer who lapses to free gets the longer grace.
     */
    // Paid plans for sale? Off until the payment provider approves the store
    // (App\Billing\Sales says what off means, and how to turn it on).
    'paid_open' => (bool) env('CIC_PAID_PLANS_OPEN', false),

    'trial' => [
        'days' => (int) env('CIC_TRIAL_DAYS', 3),
        'grace_days' => (int) env('CIC_TRIAL_GRACE_DAYS', 2),
        // A former paying customer's paused sites: deleted this long after
        // the account moved to free - and only once a final backup of each
        // is confirmed, kept 30 days (infra/cic-backup-maintain).
        'lapsed_grace_days' => 3,
        'warn_hours' => 24,
    ],

    /*
     * A failed payment does not stop anything for this long: the customer
     * keeps the paid plan while the card is retried or replaced, and is
     * emailed at once and a day before it ends. Then the account moves to
     * free (sites paused), and lapsed_grace_days later the sites are deleted.
     */
    'payment_grace_days' => (int) env('CIC_PAYMENT_GRACE_DAYS', 7),

    /*
     * One paid plan. cpu and memory are PER SITE ceilings (cgroups); disk_gb
     * is each site's own filesystem ceiling (kernel-enforced), and storage_gb
     * is the plan's total across its sites and their databases, counted by
     * fleet:sync-usage. Stock reserves cpu x sites, memory x sites and
     * storage_gb for every paid account.
     */
    'plans' => [
        'free' => [
            'name' => 'Free trial', 'price' => 0, 'variant_id' => null,
            // The trial runs at Starter's speed: the point is to feel the real thing.
            'sites' => 1, 'cpu' => '0.5', 'memory' => '384m', 'disk_gb' => 2, 'storage_gb' => 2,
            'custom_domains' => false,
            // Queue worker, scheduler, Reverb: each takes memory and a
            // database connection, so they come with the paid plan.
            'background' => false,
        ],
        'starter' => [
            'name' => 'Starter', 'price' => 12, 'variant_id' => env('LS_VARIANT_STARTER'),
            // Sized so a full fleet pays for itself: 52 plans on today's three
            // hosts (memory decides), about $170 a month over the servers and
            // tools when all are sold. Customers see measured capacity only.
            // Storage is 5 GB PER SITE (owner's decision, 2026-09-24): each
            // site's files are capped at disk_gb by its own disk, and files and
            // databases together at storage_gb for the account. Disk is
            // overcommitted (fleet.stock.overcommit.disk) so this does not
            // halve how many fit - sites use ~0.7 GB of their 5 GB (measured).
            'sites' => 3, 'cpu' => '0.5', 'memory' => '384m', 'disk_gb' => 5, 'storage_gb' => 15,
            'custom_domains' => true,
            'background' => true,
        ],
    ],

];

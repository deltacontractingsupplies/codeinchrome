<?php

/*
 * The public demos on the home page: real sites, built by AI agents through
 * the editor, with their admin logins published on purpose (each store's admin
 * panel is read-only for that login) and their source open to read at
 * /demos/{key}/code - never to edit (App\Http\Controllers\DemoCodeController).
 *
 * A demo is shown only once its url is set, so the home page never links to a
 * site that does not exist yet. `site` is the site id on the fleet. `hide`
 * lists paths of the site that are not its source (leftovers of the build,
 * uploads) and are left out of the code view.
 */
return [
    /*
     * Explore (App\Showcase\Explore): every free, live, built site, listed by
     * its address only. Free sites are listed with no opt-out; the person is
     * told before creating one.
     */
    'explore' => [
        'on_home' => 12,
        // The platform's own test accounts, never listed.
        'exclude_email_suffixes' => ['@codeinchrome.test'],
    ],

    'demos' => array_filter([
        'ember-and-oak' => [
            'site' => 'shop',
            'name' => 'Ember & Oak',
            'what' => 'A small-batch coffee store: a catalogue with roast filters, a bag, cash-on-delivery checkout that holds stock, and an admin panel with sales, orders and products.',
            'built' => 'Built by Claude, driving the editor through window.cic.',
            'url' => env('SHOWCASE_URL'),
            'admin_url' => env('SHOWCASE_ADMIN_URL'),
            'admin_email' => env('SHOWCASE_ADMIN_EMAIL'),
            'admin_password' => env('SHOWCASE_ADMIN_PASSWORD'),
            'hide' => [],
            // The file the code window opens first (else README.md, then routes/web.php).
            'feature' => ['/app/Http/Controllers/ShopController.php'],
        ],
        'petal-and-stem' => [
            'site' => 'larashop',
            'name' => 'Petal & Stem',
            'what' => 'A flower shop: bouquets by occasion, a cart, cash-only checkout with order numbers, and an admin dashboard for orders and products - every asset drawn by the agent.',
            'built' => 'Built by Claude in Chrome, the browser extension, from a single conversation.',
            'url' => env('SHOWCASE_FLOWERS_URL'),
            'admin_url' => env('SHOWCASE_FLOWERS_ADMIN_URL'),
            'admin_email' => env('SHOWCASE_FLOWERS_ADMIN_EMAIL'),
            'admin_password' => env('SHOWCASE_FLOWERS_ADMIN_PASSWORD'),
            'feature' => ['/app/Http/Controllers/ShopController.php'],
            'hide' => ['_flowershop_staging', 'flowershop-files.zip', 'flowershop-new.zip', 'flowershop-staging.zip', 'web.php'],
        ],
    ], fn ($demo) => ! empty($demo['url'])),

    // A recording of a build, served from public/ (e.g. /showcase/build.gif).
    'recording' => env('SHOWCASE_RECORDING'),
];

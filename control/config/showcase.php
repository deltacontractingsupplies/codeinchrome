<?php

/*
 * The public demo store on the home page: a site built through the editor by
 * the agent. Nothing is shown until SHOWCASE_URL is set, so the home page
 * never links to a demo that does not exist yet. The admin login is public
 * on purpose - the store's admin panel is read-only for that account.
 */
return [
    'url' => env('SHOWCASE_URL'),
    'admin_url' => env('SHOWCASE_ADMIN_URL'),
    'admin_email' => env('SHOWCASE_ADMIN_EMAIL'),
    'admin_password' => env('SHOWCASE_ADMIN_PASSWORD'),
    // A recording of the build, served from public/ (e.g. /showcase/build.gif).
    'recording' => env('SHOWCASE_RECORDING'),
    // True once the store has its Stripe TEST key, so the home page only
    // invites visitors to pay when paying actually works.
    'checkout' => (bool) env('SHOWCASE_CHECKOUT', false),
];

<?php

return [
    'name' => 'Ember & Oak',
    // Stripe TEST secret key. A live key is refused (ShopController): this
    // store is a public demo and must never take real money.
    'stripe_secret' => env('STRIPE_SECRET'),
    'currency' => 'usd',
    'shipping_cents' => 500,
    'free_shipping_over_cents' => 4000,
];

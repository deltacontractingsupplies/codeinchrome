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
    'plans' => [
        'free' => [
            'name' => 'Free', 'price' => 0, 'variant_id' => null,
            'sites' => 1,  'cpu' => '0.25', 'memory' => '256m', 'disk_gb' => 1,
            'custom_domains' => false,
        ],
        'starter' => [
            'name' => 'Starter', 'price' => 12, 'variant_id' => env('LS_VARIANT_STARTER'),
            'sites' => 3,  'cpu' => '0.5', 'memory' => '512m', 'disk_gb' => 5,
            'custom_domains' => true,
        ],
        'pro' => [
            'name' => 'Pro', 'price' => 29, 'variant_id' => env('LS_VARIANT_PRO'),
            'sites' => 10, 'cpu' => '1.0', 'memory' => '1024m', 'disk_gb' => 20,
            'custom_domains' => true,
        ],
        'studio' => [
            'name' => 'Studio', 'price' => 79, 'variant_id' => env('LS_VARIANT_STUDIO'),
            'sites' => 40, 'cpu' => '2.0', 'memory' => '2048m', 'disk_gb' => 100,
            'custom_domains' => true,
        ],
    ],
];

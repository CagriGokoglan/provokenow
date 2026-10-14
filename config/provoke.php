<?php

return [

    /*
    | Default superadmin created by AdminUserSeeder. Leave ADMIN_PASSWORD empty to
    | have a random password generated (and printed once) at seed time.
    */
    'admin' => [
        'name' => env('ADMIN_NAME', 'Provoke Now Admin'),
        'email' => env('ADMIN_EMAIL', 'admin@provokenow.com'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    'commerce' => [
        'currency' => env('STORE_CURRENCY', 'USD'),

        // Flat estimate in basis points (825 = 8.25%). Replace with a tax provider before launch.
        'tax_rate_bps' => (int) env('TAX_RATE_BPS', 0),

        // Applied when the cart contains anything that ships.
        'shipping_flat_cents' => (int) env('SHIPPING_FLAT_CENTS', 995),
        'free_shipping_threshold_cents' => (int) env('FREE_SHIPPING_THRESHOLD_CENTS', 15000),

        'max_line_quantity' => 10,
    ],

    'upsell' => [
        // Discount on a POD box / accessory added alongside a matching digital deck.
        'bundle_discount_percent' => 15,
        'max_suggestions' => 6,
    ],

    'vault' => [
        // Deck segments whose cards stay hidden until the vault is unlocked
        // (only for users who have set a vault passcode).
        'protected_segments' => ['Advanced_Taboo'],
        'unlock_ttl_minutes' => (int) env('VAULT_UNLOCK_TTL_MINUTES', 15),
    ],

    // POST /api/v1/orders/fulfill-test simulates a payment webhook. Never enable in production.
    'enable_test_fulfillment' => (bool) env('ENABLE_TEST_FULFILLMENT', false),

];

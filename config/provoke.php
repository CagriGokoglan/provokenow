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

    'brand' => [
        'name' => 'PROVOKE NOW',
        'legal_name' => env('BRAND_LEGAL_NAME', 'PROVOKE NOW'),
        'description' => 'Luxury intimacy and lifestyle brand creating conversation and connection card decks for couples.',
        'logo_url' => env('BRAND_LOGO_URL', 'https://provokenow.com/brand/logo.png'),
        // Official profiles; feeds Organization.sameAs so answer engines can resolve the entity.
        'same_as' => array_values(array_filter(explode(',', (string) env('BRAND_SAME_AS', '')))),
    ],

    /*
    | Public-facing site (Next.js). All canonical / sitemap URLs point here, not at the API host.
    */
    'frontend' => [
        'url' => rtrim((string) env('FRONTEND_URL', 'https://provokenow.com'), '/'),
        'paths' => [
            'deck' => '/decks/{code}',
            'product' => '/store/{slug}',
            // Clean listing URLs: an intensity level and a store category.
            'deck_level' => '/decks/{level}',
            'store_category' => '/store/{slug}',
            // Signed-in reading view for an owned deck (linked from order emails).
            'library_deck' => '/decks/{code}/play',
        ],
        // Admin edits ping the web app to refresh its cache (shared secret; unset = off).
        // On the VPS point it straight at the local Next.js process: http://127.0.0.1:3000/revalidate
        'revalidate_url' => env('FRONTEND_REVALIDATE_URL', rtrim((string) env('FRONTEND_URL', 'https://provokenow.com'), '/').'/revalidate'),
        'revalidate_secret' => env('FRONTEND_REVALIDATE_SECRET'),
    ],

    /*
    | Admin image uploads are re-encoded in the background (App\Jobs\OptimizeModelImages):
    | WebP for site imagery, JPEG for social share images.
    */
    'images' => [
        'quality' => (int) env('IMAGE_QUALITY', 82),
        'max_dimension' => (int) env('IMAGE_MAX_DIMENSION', 2400),
        'og_width' => 1200,
        'og_quality' => 85,
    ],

    'seo' => [
        'default_og_image' => env('SEO_DEFAULT_OG_IMAGE', 'https://provokenow.com/og/default.jpg'),
        'default_locale' => 'en',
        'supported_locales' => ['en'],

        // Indexable static pages: [path, changefreq, priority].
        'static_pages' => [
            ['/', 'daily', '1.0'],
            ['/decks', 'daily', '0.9'],
            ['/store', 'daily', '0.9'],
            ['/about', 'monthly', '0.5'],
            ['/faq', 'monthly', '0.5'],
        ],

        // Never emitted in the sitemap, even if misconfigured above.
        'sitemap_disallowed_prefixes' => ['/vault', '/checkout', '/cart', '/user', '/account', '/library', '/api', '/admin'],
        'sitemap_cache_minutes' => 60,
    ],

    // Public, browser-safe identifiers only. Secrets live in config/services.php.
    'tracking' => [
        'meta_pixel_id' => env('META_PIXEL_ID'),
        'gtm_container_id' => env('GTM_CONTAINER_ID'),
        'google_site_verification' => env('GOOGLE_SITE_VERIFICATION'),
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
        'protected_segments' => ['Taboo'],
        'unlock_ttl_minutes' => (int) env('VAULT_UNLOCK_TTL_MINUTES', 15),
    ],

    // POST /api/v1/orders/fulfill-test simulates a payment webhook. Never enable in production.
    'enable_test_fulfillment' => (bool) env('ENABLE_TEST_FULFILLMENT', false),

];

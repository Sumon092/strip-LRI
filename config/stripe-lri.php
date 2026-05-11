<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Register package routes
    |--------------------------------------------------------------------------
    |
    | When true, Stripe-LRI registers workspace + admin billing routes.
    | Disable if you mount the same URLs manually.
    |
    */
    'register_routes' => (bool) env('STRIPE_LRI_REGISTER_ROUTES', false),

    /*
    |--------------------------------------------------------------------------
    | Register Stripe webhook routes (GET info + POST handler)
    |--------------------------------------------------------------------------
    |
    | Production Stripe billing is webhook-driven (renewals, payments, failed
    | charges, subscription state). Keep this true, expose POST /stripe/webhook
    | to Stripe, and set stripe.webhook_secret below. Set false only if your app
    | registers an equivalent signed POST endpoint elsewhere (never omit
    | webhooks from a live billing design).
    |
    */
    'register_webhook' => (bool) env('STRIPE_LRI_REGISTER_WEBHOOK', true),

    /*
    |--------------------------------------------------------------------------
    | Credit-based product model
    |--------------------------------------------------------------------------
    |
    | Set via `php artisan stripe-lri:install` or STRIPE_LRI_CREDIT_BASED.
    | When false: credit-only migrations, credit Artisan commands, and credit
    | scheduler hooks are not registered. Set true before first migrate if you
    | need credits schema; changing later requires `php artisan migrate` again.
    |
    */
    'credit_based' => (bool) env('STRIPE_LRI_CREDIT_BASED', false),

    /*
    |--------------------------------------------------------------------------
    | Site-limit product model
    |--------------------------------------------------------------------------
    |
    | Set via `php artisan stripe-lri:install` or STRIPE_LRI_SITE_LIMIT.
    | When true: site-limit migrations (site_limit on subscription_products,
    | site_count on subscription_product_user) are registered. Set before
    | first migrate; changing later requires `php artisan migrate` again.
    |
    */
    'site_limit' => (bool) env('STRIPE_LRI_SITE_LIMIT', false),

    /*
    |--------------------------------------------------------------------------
    | Premium features (catalog + per-package inclusion)
    |--------------------------------------------------------------------------
    |
    | Set via `php artisan stripe-lri:install` or STRIPE_LRI_PREMIUM_FEATURES.
    | When true: optional migrations add `premium_features` (five default rows)
    | and `subscription_product_premium_feature`; admin package forms show toggles.
    |
    */
    'premium_features' => (bool) env('STRIPE_LRI_PREMIUM_FEATURES', false),

    /*
    |--------------------------------------------------------------------------
    | Application code published into the host app
    |--------------------------------------------------------------------------
    |
    | When true (set by `php artisan stripe-lri:install`), controllers, models,
    | requests, and support live under app/StripeLri; migrations are copied to
    | database/migrations; routes/stripe-lri.php is created. The package no longer
    | loads migrations from vendor for this app.
    |
    */
    'published_to_app' => (bool) env('STRIPE_LRI_PUBLISHED_TO_APP', false),

    /*
    |--------------------------------------------------------------------------
    | Eloquent user model
    |--------------------------------------------------------------------------
    */
    'models' => [
        'user'                    => env('STRIPE_LRI_USER_MODEL', 'App\\Models\\User'),
        'subscription_product_user' => env('STRIPE_LRI_SPU_MODEL', 'App\\Models\\Billing\\SubscriptionProductUser'),
        'payment'                 => env('STRIPE_LRI_PAYMENT_MODEL', 'App\\Models\\Billing\\Payment'),
        'account_deletion_log'    => env('STRIPE_LRI_DELETION_LOG_MODEL', 'App\\Models\\AccountDeletionLog'),
    ],

    'tables' => [
        'users' => env('STRIPE_LRI_USERS_TABLE', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Route middleware (string or array)
    |--------------------------------------------------------------------------
    */
    'middleware' => [
        'workspace' => ['web', 'auth', 'verified'],
        'admin' => ['web', 'auth', 'verified', 'admin'],
    ],

    'stripe' => [
        'secret' => env('STRIPE_SECRET', env('STRIPE_LRI_SECRET', '')),
        /*
         * Mandatory for verifying Stripe webhook signatures in production.
         * Without this, POST /stripe/webhook cannot trust inbound events (handler returns 503).
         */
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', env('STRIPE_LRI_WEBHOOK_SECRET', '')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduler (Indexchecker parity)
    |--------------------------------------------------------------------------
    |
    | Only applied when credit_based is true (see above).
    |
    | Indexchecker registers in bootstrap/app.php:
    | - credits:process-history hourly
    | - credits:add-monthly-for-yearly daily
    |
    | When enabled, Stripe-LRI registers equivalent artisan names under the
    | stripe-lri:* namespace. Bind Contracts\CreditLedger to your port of
    | CreditTypeService / ProcessCreditsHistory logic.
    |
    */
    'schedule' => [
        'enabled' => (bool) env('STRIPE_LRI_SCHEDULE_ENABLED', false),
        'process_history_hourly' => (bool) env('STRIPE_LRI_SCHEDULE_PROCESS_HISTORY', true),
        'monthly_credits_daily' => (bool) env('STRIPE_LRI_SCHEDULE_MONTHLY_CREDITS', true),
    ],
];

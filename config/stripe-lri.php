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
    | Credit-based product model
    |--------------------------------------------------------------------------
    |
    | Set via `php artisan stripe-lri:install` or STRIPE_LRI_CREDIT_BASED.
    | Exposed to the frontend for conditional UI (credits vs seat-only).
    |
    */
    'credit_based' => (bool) env('STRIPE_LRI_CREDIT_BASED', false),

    /*
    |--------------------------------------------------------------------------
    | Eloquent user model
    |--------------------------------------------------------------------------
    */
    'models' => [
        'user' => env('STRIPE_LRI_USER_MODEL', 'App\\Models\\User'),
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
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', env('STRIPE_LRI_WEBHOOK_SECRET', '')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduler (Indexchecker parity)
    |--------------------------------------------------------------------------
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

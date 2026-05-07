<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Legacy filename kept for migration history. Stripe-LRI no longer creates
 * `stripe_lri_webhook_events` — the default schema is exactly 9 tables (no credit)
 * or 10 with credit; use your app for webhook idempotency if needed.
 *
 * @see 2026_05_09_000000_drop_stripe_lri_webhook_events_table
 */
return new class extends Migration
{
    public function up(): void
    {
        // Intentionally empty.
    }

    public function down(): void
    {
        // Intentionally empty.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Removes deprecated `stripe_lri_webhook_events` so the package footprint stays
 * at 9 tables (no credit) or 10 (with credit_ledger).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('stripe_lri_webhook_events');
    }

    public function down(): void
    {
        // No restore: table was optional / deprecated.
    }
};

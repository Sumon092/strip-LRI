<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row (id=1) updated whenever POST /stripe/webhook verifies an event.
 * Admin package creation stays disabled until {@code last_valid_event_at} is set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_lri_webhook_health', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->timestamp('last_valid_event_at')->nullable();
            $table->string('last_stripe_event_id', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_lri_webhook_health');
    }
};

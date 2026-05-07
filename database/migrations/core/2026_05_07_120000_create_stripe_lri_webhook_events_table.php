<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_lri_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_event_id')->unique();
            $table->string('type')->index();
            $table->timestamp('processed_at')->useCurrent();

            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_lri_webhook_events');
    }
};

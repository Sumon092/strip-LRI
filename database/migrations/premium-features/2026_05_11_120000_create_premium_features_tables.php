<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Optional install: `php artisan stripe-lri:install` with “premium features” enabled.
 *
 * Defines five catalog rows (Premium Feature 1 … 5) and a pivot on subscription_products
 * so admins can mark which premium features each package includes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('premium_features', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('sort_order');
        });

        Schema::create('subscription_product_premium_feature', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_product_id')->constrained('subscription_products')->cascadeOnDelete();
            $table->foreignId('premium_feature_id')->constrained('premium_features')->cascadeOnDelete();
            $table->boolean('is_included')->default(false);
            $table->timestamps();

            $table->unique(['subscription_product_id', 'premium_feature_id'], 'sppf_product_feature_uidx');
        });

        for ($i = 1; $i <= 5; $i++) {
            DB::table('premium_features')->insert([
                'name' => 'Premium Feature '.$i,
                'sort_order' => $i,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_product_premium_feature');
        Schema::dropIfExists('premium_features');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loaded only when STRIPE_LRI_SITE_LIMIT=true (see StripeLriServiceProvider).
 * Adds site_limit to subscription_products and site_count to subscription_product_user.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('subscription_products') && ! Schema::hasColumn('subscription_products', 'site_limit')) {
            Schema::table('subscription_products', function (Blueprint $table) {
                $table->unsignedInteger('site_limit')->nullable()->after('billing_cycle');
            });
        }

        if (Schema::hasTable('subscription_product_user') && ! Schema::hasColumn('subscription_product_user', 'site_count')) {
            Schema::table('subscription_product_user', function (Blueprint $table) {
                $table->unsignedInteger('site_count')->default(0)->after('is_active');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('subscription_product_user') && Schema::hasColumn('subscription_product_user', 'site_count')) {
            Schema::table('subscription_product_user', function (Blueprint $table) {
                $table->dropColumn('site_count');
            });
        }

        if (Schema::hasTable('subscription_products') && Schema::hasColumn('subscription_products', 'site_limit')) {
            Schema::table('subscription_products', function (Blueprint $table) {
                $table->dropColumn('site_limit');
            });
        }
    }
};

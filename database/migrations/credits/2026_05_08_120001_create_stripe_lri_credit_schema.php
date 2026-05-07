<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loaded only when STRIPE_LRI_CREDIT_BASED=true (see StripeLriServiceProvider).
 * Adds credit columns and the credit_ledger table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('subscription_products') && ! Schema::hasColumn('subscription_products', 'credits_limit')) {
            Schema::table('subscription_products', function (Blueprint $table) {
                $table->unsignedInteger('credits_limit')->nullable()->after('billing_cycle');
            });
        }

        if (Schema::hasTable('payments') && ! Schema::hasColumn('payments', 'credits_purchased')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->integer('credits_purchased')->default(0)->after('currency');
            });
        }

        if (Schema::hasTable('invoices') && ! Schema::hasColumn('invoices', 'credits_purchased')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->integer('credits_purchased')->default(0)->after('total_amount');
            });
        }

        if (Schema::hasTable('subscription_product_user') && ! Schema::hasColumn('subscription_product_user', 'credits_balance')) {
            Schema::table('subscription_product_user', function (Blueprint $table) {
                $table->unsignedBigInteger('credits_balance')->default(0)->after('is_active');
                $table->timestamp('credits_expires_at')->nullable()->after('expires_at');
            });
        }

        if (! Schema::hasTable('credit_ledger')) {
            Schema::create('credit_ledger', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('subscription_product_id')->nullable()->constrained('subscription_products')->nullOnDelete();
                $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
                $table->bigInteger('delta');
                $table->bigInteger('balance_after')->nullable();
                $table->string('entry_type', 64)->index();
                $table->string('ref_type')->nullable();
                $table->unsignedBigInteger('ref_id')->nullable();
                $table->text('description')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'created_at']);
                $table->index(['subscription_product_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_ledger');

        if (Schema::hasTable('subscription_product_user') && Schema::hasColumn('subscription_product_user', 'credits_balance')) {
            Schema::table('subscription_product_user', function (Blueprint $table) {
                $table->dropColumn(['credits_balance', 'credits_expires_at']);
            });
        }

        if (Schema::hasTable('invoices') && Schema::hasColumn('invoices', 'credits_purchased')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropColumn('credits_purchased');
            });
        }

        if (Schema::hasTable('payments') && Schema::hasColumn('payments', 'credits_purchased')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropColumn('credits_purchased');
            });
        }

        if (Schema::hasTable('subscription_products') && Schema::hasColumn('subscription_products', 'credits_limit')) {
            Schema::table('subscription_products', function (Blueprint $table) {
                $table->dropColumn('credits_limit');
            });
        }
    }
};

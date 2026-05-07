<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loaded only when STRIPE_LRI_CREDIT_BASED=true (see StripeLriServiceProvider).
 * Adds credit columns to core billing tables and creates credit_* tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('packages') && ! Schema::hasColumn('packages', 'credits_limit')) {
            Schema::table('packages', function (Blueprint $table) {
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

        if (! Schema::hasTable('credit_wallets')) {
            Schema::create('credit_wallets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
                $table->unsignedBigInteger('credit_remaining')->default(200);
                $table->enum('credit_type', [
                    '1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11',
                ])->default('1');
                $table->timestamp('credit_added_at')->useCurrent();
                $table->timestamp('credit_expires_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('credit_types')) {
            Schema::create('credit_types', function (Blueprint $table) {
                $table->id();
                $table->uuid('unique_id')->unique();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('package_id')->constrained()->cascadeOnDelete();
                $table->string('type');
                $table->integer('credits')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('last_monthly_credit_added_at')->nullable()->index();
                $table->boolean('is_active')->default(true);
                $table->boolean('is_selected')->default(false)->nullable();
                $table->enum('subscription_status', ['active', 'canceled', 'expired'])->default('active');
                $table->string('stripe_subscription_id')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'package_id', 'type']);
                $table->index(['user_id', 'is_active']);
                $table->index(['user_id', 'is_selected']);
                $table->index(['user_id', 'subscription_status']);
                $table->index('stripe_subscription_id');
                $table->index('expires_at');
            });
        }

        if (! Schema::hasTable('credit_transactions')) {
            Schema::create('credit_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('wallet_id')->constrained('credit_wallets')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->enum('type', ['purchase', 'spend', 'refund', 'admin_add', 'admin_remove']);
                $table->bigInteger('amount');
                $table->string('ref_type')->nullable();
                $table->unsignedBigInteger('ref_id')->nullable();
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
        Schema::dropIfExists('credit_types');
        Schema::dropIfExists('credit_wallets');

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

        if (Schema::hasTable('packages') && Schema::hasColumn('packages', 'credits_limit')) {
            Schema::table('packages', function (Blueprint $table) {
                $table->dropColumn('credits_limit');
            });
        }
    }
};

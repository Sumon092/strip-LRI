<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core billing tables (Indexchecker-style) shipped with Stripe-LRI.
 * Loaded via StripeLriServiceProvider::loadMigrationsFrom — run `php artisan migrate` after installing the package.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('plan_name');
            $table->text('description')->nullable();
            $table->string('plan_type')->default('stripe')->comment('free, stripe, subscription, custom, …');
            $table->decimal('price', 10, 2)->nullable();
            $table->enum('billing_cycle', ['monthly', 'yearly'])->nullable();
            $table->unsignedInteger('credits_limit')->nullable();
            $table->unsignedInteger('max_devices')->nullable();
            $table->boolean('is_popular')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->boolean('allow_trial')->default(false);
            $table->string('status')->default('active');
            $table->integer('sort_order')->default(0);
            $table->json('allowed_user_ids')->nullable();
            $table->string('stripe_product_id')->nullable();
            $table->string('stripe_price_id')->nullable();
            $table->string('temp_request_id')->nullable();
            $table->string('created_by')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'sort_order']);
            $table->index('plan_type');
            $table->index('billing_cycle');
            $table->index('stripe_product_id');
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->enum('type', ['fixed_amount', 'percentage']);
            $table->decimal('value', 10, 2);
            $table->string('currency', 3)->default('USD');
            $table->integer('max_redemptions')->nullable();
            $table->integer('times_redeemed')->default(0);
            $table->boolean('active')->default(true);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('stripe_coupon_id')->nullable()->unique();
            $table->string('stripe_promotion_code_id')->nullable()->index();
            $table->boolean('custom_validation_rules')->default(false);
            $table->boolean('valid_for_new_users_only')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index('code');
            $table->index('active');
            $table->index(['valid_from', 'valid_until']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->nullable()->constrained('packages')->nullOnDelete();
            $table->string('stripe_subscription_id')->nullable();
            $table->string('name')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('cancel_at')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->json('cancellation_details')->nullable();
            $table->timestamp('created_time')->nullable();
            $table->timestamp('start_date')->nullable();
            $table->integer('quantity')->default(1);
            $table->string('default_payment_method')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('stripe_subscription_id');
        });

        Schema::create('subscription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_id')->nullable();
            $table->string('stripe_price')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('interval')->nullable();
            $table->integer('interval_count')->nullable();
            $table->string('stripe_product')->nullable();
            $table->string('nickname')->nullable();
            $table->timestamps();

            $table->index('subscription_id');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->nullable()->constrained('packages')->nullOnDelete();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->string('stripe_charge_id')->nullable();
            $table->string('stripe_subscription_id')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->integer('credits_purchased')->default(0);
            $table->enum('payment_type', ['single', 'subscription'])->default('single');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'cancelled', 'refunded'])->default('pending');
            $table->json('billing_details')->nullable();
            $table->json('payment_method_details')->nullable();
            $table->json('stripe_metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('stripe_payment_intent_id');
            $table->index('stripe_charge_id');
            $table->index('stripe_subscription_id');
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->cascadeOnDelete();
            $table->foreignId('package_id')->nullable()->constrained('packages')->nullOnDelete();
            $table->string('invoice_number')->unique();
            $table->string('stripe_invoice_id')->nullable();
            $table->string('payment_intent_id')->nullable();
            $table->string('payment_charge_id')->nullable();
            $table->string('customer_name');
            $table->string('customer_email');
            $table->json('billing_address')->nullable();
            $table->decimal('amount', 10, 2);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2);
            $table->integer('credits_purchased')->default(0);
            $table->string('currency', 3)->default('USD');
            $table->enum('status', ['draft', 'paid', 'void', 'open', 'uncollectible', 'unpaid'])->default('draft');
            $table->string('stripe_invoice_url')->nullable();
            $table->string('stripe_invoice_pdf')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('invoice_number');
            $table->index('stripe_invoice_id');
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('package_id')->nullable()->constrained('packages')->nullOnDelete();
            $table->enum('status', ['pending', 'paid', 'failed', 'refunded', 'canceled'])->default('pending');
            $table->unsignedInteger('amount_cents')->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('provider')->nullable();
            $table->string('provider_charge_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

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

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
        Schema::dropIfExists('credit_types');
        Schema::dropIfExists('credit_wallets');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('subscription_items');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('packages');
    }
};

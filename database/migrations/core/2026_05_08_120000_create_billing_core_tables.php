<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core billing: exactly **nine** tables when STRIPE_LRI_CREDIT_BASED=false:
 * subscription_products, subscription_product_items, subscription_product_prices,
 * subscriptions, subscription_items, subscription_product_user, payments, invoices, coupons.
 *
 * When credit-based is true, credits migrations add **credit_ledger** (10th table) plus credit columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_products', function (Blueprint $table) {
            $table->id();
            $table->string('plan_name');
            $table->text('description')->nullable();
            $table->string('plan_type')->default('stripe_plan');
            $table->decimal('price', 10, 2)->nullable();
            $table->enum('billing_cycle', ['monthly', 'yearly'])->nullable();
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

        Schema::create('subscription_product_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_product_id')->constrained('subscription_products')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['subscription_product_id', 'sort_order'], 'spi_spid_sort_idx');
        });

        Schema::create('subscription_product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_product_id')->constrained('subscription_products')->cascadeOnDelete();
            $table->string('plan_type', 32);
            $table->string('stripe_price_id')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('currency', 3)->default('usd');
            $table->string('nickname')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['subscription_product_id', 'plan_type'], 'spp_spid_plan_idx');
            $table->index('stripe_price_id');
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_product_id')->nullable()->constrained('subscription_products')->nullOnDelete();
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

        Schema::create('subscription_product_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_product_id')->constrained('subscription_products')->cascadeOnDelete();
            $table->string('stripe_subscription_id')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'subscription_product_id'], 'spu_user_product_uidx');
            $table->index(['subscription_product_id', 'is_active'], 'spu_spid_active_idx');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscription_product_id')->nullable()->constrained('subscription_products')->nullOnDelete();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->string('stripe_charge_id')->nullable();
            $table->string('stripe_subscription_id')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('currency', 3)->default('USD');
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
            $table->foreignId('subscription_product_id')->nullable()->constrained('subscription_products')->nullOnDelete();
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

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->string('stripe_coupon_id')->nullable()->unique();
            $table->unsignedTinyInteger('percent_off')->nullable();
            $table->unsignedBigInteger('amount_off')->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('duration', 32)->default('once');
            $table->unsignedInteger('duration_in_months')->nullable();
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('times_redeemed')->default(0);
            $table->timestamp('redeem_by')->nullable();
            $table->boolean('active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('active', 'coupons_active_idx');
            $table->index('stripe_coupon_id', 'coupons_stripe_cpn_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('subscription_product_user');
        Schema::dropIfExists('subscription_items');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('subscription_product_prices');
        Schema::dropIfExists('subscription_product_items');
        Schema::dropIfExists('subscription_products');
    }
};

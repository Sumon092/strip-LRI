<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds discount_amount and promo_code to invoices so Stripe coupon/promo data
 * can be stored when invoice.paid webhook fires.
 * Also corrects amount (pre-discount subtotal) vs total_amount (amount actually paid).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invoices')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'discount_amount')) {
                $table->decimal('discount_amount', 10, 2)->default(0)->after('tax_amount');
            }
            if (! Schema::hasColumn('invoices', 'promo_code')) {
                $table->string('promo_code')->nullable()->after('discount_amount');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('invoices')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('invoices', 'promo_code')) {
                $cols[] = 'promo_code';
            }
            if (Schema::hasColumn('invoices', 'discount_amount')) {
                $cols[] = 'discount_amount';
            }
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};

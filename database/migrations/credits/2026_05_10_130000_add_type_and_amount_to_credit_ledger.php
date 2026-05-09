<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds type (credit/debit) and credit_amount columns to credit_ledger.
 * Safe to run on existing installations — guards prevent duplicate columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('credit_ledger')) {
            return;
        }

        Schema::table('credit_ledger', function (Blueprint $table) {
            if (! Schema::hasColumn('credit_ledger', 'type')) {
                $table->string('type', 16)->default('credit')->after('subscription_id')
                    ->comment('credit = adding credits, debit = consuming/removing credits');
            }
            if (! Schema::hasColumn('credit_ledger', 'credit_amount')) {
                $table->unsignedBigInteger('credit_amount')->default(0)->after('type')
                    ->comment('Absolute (always positive) number of credits');
            }
        });

        // Backfill existing rows from the delta column
        DB::table('credit_ledger')->where('delta', '>=', 0)->update([
            'type'          => 'credit',
            'credit_amount' => DB::raw('ABS(delta)'),
        ]);
        DB::table('credit_ledger')->where('delta', '<', 0)->update([
            'type'          => 'debit',
            'credit_amount' => DB::raw('ABS(delta)'),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('credit_ledger')) {
            return;
        }

        Schema::table('credit_ledger', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('credit_ledger', 'type')) {
                $cols[] = 'type';
            }
            if (Schema::hasColumn('credit_ledger', 'credit_amount')) {
                $cols[] = 'credit_amount';
            }
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};

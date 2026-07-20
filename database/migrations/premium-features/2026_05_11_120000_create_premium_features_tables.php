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
 *
 * FK constraint names are shortened — MySQL limits identifiers to 64 characters;
 * Laravel’s default name for this pivot exceeds that limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('premium_features')) {
            Schema::create('premium_features', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->unsignedTinyInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index('sort_order');
            });
        }

        if (! Schema::hasTable('subscription_product_premium_feature')) {
            Schema::create('subscription_product_premium_feature', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('subscription_product_id');
                $table->unsignedBigInteger('premium_feature_id');
                $table->boolean('is_included')->default(false);
                $table->timestamps();

                $table->unique(['subscription_product_id', 'premium_feature_id'], 'sppf_product_feature_uidx');

                $table->foreign('subscription_product_id', 'sppf_product_fk')
                    ->references('id')
                    ->on('subscription_products')
                    ->cascadeOnDelete();

                $table->foreign('premium_feature_id', 'sppf_feature_fk')
                    ->references('id')
                    ->on('premium_features')
                    ->cascadeOnDelete();
            });
        } else {
            $this->ensurePivotIndexesAndForeignKeys();
        }

        if (DB::table('premium_features')->count() === 0) {
            for ($i = 1; $i <= 5; $i++) {
                DB::table('premium_features')->insert([
                    'name' => 'Premium Feature '.$i,
                    'sort_order' => $i,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function ensurePivotIndexesAndForeignKeys(): void
    {
        $table = 'subscription_product_premium_feature';

        $indexNames = collect(DB::select('SHOW INDEX FROM `'.$table.'`'))
            ->pluck('Key_name')
            ->unique()
            ->all();

        if (! in_array('sppf_product_feature_uidx', $indexNames, true)) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unique(['subscription_product_id', 'premium_feature_id'], 'sppf_product_feature_uidx');
            });
        }

        $fkNames = collect(DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_TYPE = ?',
            [$table, 'FOREIGN KEY']
        ))->pluck('CONSTRAINT_NAME')->all();

        Schema::table($table, function (Blueprint $blueprint) use ($fkNames) {
            if (! in_array('sppf_product_fk', $fkNames, true)) {
                $blueprint->foreign('subscription_product_id', 'sppf_product_fk')
                    ->references('id')
                    ->on('subscription_products')
                    ->cascadeOnDelete();
            }

            if (! in_array('sppf_feature_fk', $fkNames, true)) {
                $blueprint->foreign('premium_feature_id', 'sppf_feature_fk')
                    ->references('id')
                    ->on('premium_features')
                    ->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_product_premium_feature');
        Schema::dropIfExists('premium_features');
    }
};

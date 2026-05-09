<?php

declare(strict_types=1);

namespace StripeLri\Services;

use StripeLri\Contracts\CreditLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Real credit ledger implementation.
 * Resolves SubscriptionProductUser via the host app's model binding.
 */
class DatabaseCreditLedger implements CreditLedger
{
    public function addMonthlyCreditsForYearlyAndLifetime(): int
    {
        /** @var class-string<\Illuminate\Database\Eloquent\Model> $spuClass */
        $spuClass = config('stripe-lri.models.subscription_product_user');
        $updated = 0;
        $now = Carbon::now();

        $rows = $spuClass::with('product.prices')
            ->where('is_active', true)
            ->whereNotNull('credits_balance')
            ->get();

        foreach ($rows as $spu) {
            $planType = $spu->product?->prices->first()?->plan_type ?? 'monthly';
            if ($planType === 'monthly') {
                continue;
            }

            $creditsLimit = (int) ($spu->product?->getAttribute('credits_limit') ?? 0);
            if ($creditsLimit <= 0) {
                continue;
            }

            $lastRefill = $spu->credits_expires_at;
            if ($lastRefill !== null && $lastRefill->isAfter($now)) {
                continue;
            }

            $newBalance = $creditsLimit;
            $nextExpiry = $now->copy()->addMonth();

            DB::table('credit_ledger')->insert([
                'user_id'                 => $spu->user_id,
                'subscription_product_id' => $spu->subscription_product_id,
                'delta'                   => $creditsLimit,
                'balance_after'           => $newBalance,
                'entry_type'              => 'monthly_refill',
                'description'             => 'Monthly credit refill for '.$planType.' plan',
                'created_at'              => $now,
                'updated_at'              => $now,
            ]);

            $spu->update([
                'credits_balance'    => $newBalance,
                'credits_expires_at' => $nextExpiry,
            ]);

            $updated++;
        }

        return $updated;
    }

    public function processCreditsHistory(): void
    {
        /** @var class-string<\Illuminate\Database\Eloquent\Model> $spuClass */
        $spuClass = config('stripe-lri.models.subscription_product_user');

        $spuClass::where('is_active', true)
            ->whereNotNull('credits_expires_at')
            ->where('credits_expires_at', '<', Carbon::now())
            ->update(['credits_balance' => 0]);
    }

    /**
     * Write a credit ledger entry and update the user's credit balance.
     */
    public static function recordEntry(
        int $userId,
        ?int $productId,
        int $delta,
        string $entryType,
        ?string $description = null,
        ?string $refType = null,
        ?int $refId = null,
    ): void {
        $now = Carbon::now();

        /** @var class-string<\Illuminate\Database\Eloquent\Model> $spuClass */
        $spuClass = config('stripe-lri.models.subscription_product_user');

        $spu = $spuClass::where('user_id', $userId)
            ->where('subscription_product_id', $productId)
            ->first();

        $currentBalance = $spu ? (int) ($spu->credits_balance ?? 0) : 0;
        $newBalance = max(0, $currentBalance + $delta);

        DB::table('credit_ledger')->insert([
            'user_id'                 => $userId,
            'subscription_product_id' => $productId,
            'delta'                   => $delta,
            'balance_after'           => $newBalance,
            'entry_type'              => $entryType,
            'ref_type'                => $refType,
            'ref_id'                  => $refId,
            'description'             => $description,
            'created_at'              => $now,
            'updated_at'              => $now,
        ]);

        if ($spu !== null) {
            $spu->update(['credits_balance' => $newBalance]);
        }
    }
}

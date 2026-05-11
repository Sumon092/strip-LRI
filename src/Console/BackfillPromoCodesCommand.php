<?php

namespace StripeLri\Console;

use Illuminate\Console\Command;
use Stripe\StripeClient;
use StripeLri\Models\Invoice;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'stripe-lri:backfill-promo-codes')]
class BackfillPromoCodesCommand extends Command
{
    protected $signature = 'stripe-lri:backfill-promo-codes';

    protected $description = 'Back-fill promo_code on invoices that have a discount_amount but no stored promo code.';

    public function handle(): int
    {
        $secret = trim((string) config('stripe-lri.stripe.secret', ''));
        if ($secret === '') {
            $this->error('stripe-lri.stripe.secret is not configured.');

            return self::FAILURE;
        }

        $stripe = new StripeClient($secret);

        $invoices = Invoice::query()
            ->whereNull('promo_code')
            ->where('discount_amount', '>', 0)
            ->whereNotNull('stripe_invoice_id')
            ->get(['id', 'stripe_invoice_id', 'discount_amount']);

        if ($invoices->isEmpty()) {
            $this->info('No invoices need back-filling.');

            return self::SUCCESS;
        }

        $this->info("Found {$invoices->count()} invoice(s) to back-fill.");
        $updated = 0;
        $failed  = 0;

        foreach ($invoices as $invoice) {
            try {
                $stripeInv = $stripe->invoices->retrieve(
                    (string) $invoice->stripe_invoice_id,
                    ['expand' => ['discounts']],
                );

                $promoCode = $this->resolvePromoCode($stripeInv);

                if ($promoCode !== null) {
                    $invoice->update(['promo_code' => $promoCode]);
                    $this->line("  [OK] {$invoice->stripe_invoice_id} → {$promoCode}");
                    $updated++;
                } else {
                    $this->warn("  [?]  {$invoice->stripe_invoice_id} — no coupon found in Stripe");
                }
            } catch (\Throwable $e) {
                $this->error("  [!]  {$invoice->stripe_invoice_id}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("Done. Updated: {$updated}, failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolvePromoCode(object $stripeInv): ?string
    {
        foreach ($stripeInv->discounts ?? [] as $d) {
            if ($d === null || is_string($d)) {
                continue;
            }
            $coupon = $d->coupon ?? null;
            if (is_object($coupon)) {
                $raw = (string) ($coupon->id ?? $coupon->name ?? '');
                if ($raw !== '') {
                    return $raw;
                }
            } elseif (is_string($coupon) && $coupon !== '') {
                return $coupon;
            }
            $couponId = (string) ($d->coupon_id ?? '');
            if ($couponId !== '') {
                return $couponId;
            }
        }

        // Legacy single discount
        $legacy = $stripeInv->discount ?? null;
        if ($legacy !== null && ! is_string($legacy)) {
            $coupon = $legacy->coupon ?? null;
            if (is_object($coupon)) {
                $raw = (string) ($coupon->id ?? $coupon->name ?? '');

                return $raw !== '' ? $raw : null;
            }
            if (is_string($coupon) && $coupon !== '') {
                return $coupon;
            }
        }

        return null;
    }
}

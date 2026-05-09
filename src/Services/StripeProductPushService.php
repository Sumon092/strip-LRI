<?php

declare(strict_types=1);

namespace StripeLri\Services;

use Stripe\StripeClient;
use StripeLri\Models\Package;

/**
 * Pushes Package / SubscriptionProductPrice data to Stripe via API so that Stripe
 * fires product.created / price.created webhook events back to this app.
 */
final class StripeProductPushService
{
    private StripeClient $client;

    public function __construct()
    {
        $this->client = new StripeClient((string) config('stripe-lri.stripe.secret', ''));
    }

    /**
     * Create a Stripe Product (if not already linked) then create a Stripe Price
     * for each price row that has no stripe_price_id yet.
     */
    public function pushNewPackage(Package $package): void
    {
        $package->loadMissing('prices');

        if (empty($package->stripe_product_id)) {
            $product = $this->client->products->create(array_filter([
                'name'        => (string) $package->plan_name,
                'description' => $package->description ?: null,
                'metadata'    => [
                    'package_type' => (string) $package->plan_type,
                    'status'       => (string) $package->status,
                ],
            ]));

            $package->stripe_product_id = $product->id;
            $package->saveQuietly();
        }

        $this->pushPrices($package);
    }

    /**
     * Update the Stripe Product name/description and push any prices that have
     * no stripe_price_id yet.
     */
    public function pushUpdatedPackage(Package $package): void
    {
        $package->loadMissing('prices');

        if (! empty($package->stripe_product_id)) {
            $this->client->products->update($package->stripe_product_id, array_filter([
                'name'        => (string) $package->plan_name,
                'description' => $package->description ?: null,
                'metadata'    => [
                    'package_type' => (string) $package->plan_type,
                    'status'       => (string) $package->status,
                ],
            ]));
        }

        $this->pushPrices($package);
    }

    private function pushPrices(Package $package): void
    {
        foreach ($package->prices as $price) {
            if (! empty($price->stripe_price_id)) {
                continue;
            }

            $unitAmount = (int) round((float) $price->amount * 100);

            $data = array_filter([
                'product'     => $package->stripe_product_id,
                'unit_amount' => $unitAmount,
                'currency'    => (string) ($price->currency ?? 'usd'),
                'nickname'    => $price->nickname ?: null,
                'metadata'    => ['plan_type' => (string) $price->plan_type],
            ]);

            if ($price->plan_type === 'monthly') {
                $data['recurring'] = ['interval' => 'month'];
            } elseif ($price->plan_type === 'yearly') {
                $data['recurring'] = ['interval' => 'year'];
            }
            // lifetime → one-time price (no recurring key)

            $stripePrice = $this->client->prices->create($data);

            $price->stripe_price_id = $stripePrice->id;
            $price->saveQuietly();
        }
    }
}

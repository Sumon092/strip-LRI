<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Billing\Package;
use App\Models\Billing\SubscriptionProductPrice;
use Stripe\StripeClient;
use Throwable;

/**
 * Pushes Package / SubscriptionProductPrice data to Stripe.
 *
 * Stripe Prices are immutable (unit_amount / currency / recurring). On edit we:
 * - update the Stripe Product (name, description, active)
 * - create a new Stripe Price when amount/currency/interval change
 * - archive (active=false) the previous Stripe Price
 * - archive orphaned prices removed from the package
 */
final class StripeProductPushService
{
    /** @var object{products: object, prices: object} */
    private object $client;

    /**
     * @param  object{products: object, prices: object}|null  $client
     */
    public function __construct(?object $client = null)
    {
        $this->client = $client ?? new StripeClient((string) config('stripe-lri.stripe.secret', ''));
    }

    /**
     * Create a Stripe Product (if not already linked) then create a Stripe Price
     * for each price row that has no stripe_price_id yet.
     */
    public function pushNewPackage(Package $package): void
    {
        $package->loadMissing('prices');

        if (empty($package->stripe_product_id)) {
            $product = $this->client->products->create($this->productPayload($package));

            $package->stripe_product_id = $product->id;
            $package->saveQuietly();
        }

        $this->syncPrices($package, []);
        $this->syncPackageDefaultPriceId($package);
    }

    /**
     * Update Stripe Product and sync prices: replace+archive when immutable fields change,
     * archive prices removed from the package.
     *
     * @param  list<array{stripe_price_id:?string,amount:float|int|string,currency:?string,plan_type:?string}>  $previousPrices
     */
    public function pushUpdatedPackage(Package $package, array $previousPrices = []): void
    {
        $package->loadMissing('prices');

        if (empty($package->stripe_product_id)) {
            $this->pushNewPackage($package);

            return;
        }

        $this->client->products->update(
            (string) $package->stripe_product_id,
            $this->productPayload($package),
        );

        $this->syncPrices($package, $previousPrices);
        $this->syncPackageDefaultPriceId($package);
    }

    /**
     * Soft-archive the Stripe Product and all linked prices when a package is deleted.
     */
    public function archivePackage(Package $package): void
    {
        $package->loadMissing('prices');

        $failures = [];

        foreach ($package->prices as $price) {
            $id = $price->stripe_price_id !== null ? (string) $price->stripe_price_id : null;
            try {
                $this->archiveStripePrice($id);
            } catch (Throwable $e) {
                $failures[] = 'price '.$id.': '.$e->getMessage();
                logger()->error('stripe-lri.archive_price_failed', [
                    'error' => $e->getMessage(),
                    'stripe_price_id' => $id,
                    'package_id' => $package->getKey(),
                ]);
            }
        }

        if (! empty($package->stripe_product_id)) {
            try {
                $this->client->products->update((string) $package->stripe_product_id, [
                    'active' => false,
                    'metadata' => [
                        'package_type' => (string) $package->plan_type,
                        'status' => 'deleted',
                    ],
                ]);
            } catch (Throwable $e) {
                $failures[] = 'product '.$package->stripe_product_id.': '.$e->getMessage();
                logger()->error('stripe-lri.archive_product_failed', [
                    'error' => $e->getMessage(),
                    'stripe_product_id' => $package->stripe_product_id,
                    'package_id' => $package->getKey(),
                ]);
            }
        }

        if ($failures !== []) {
            throw new \RuntimeException('Stripe archive incomplete: '.implode('; ', $failures));
        }
    }

    /**
     * @param  list<array{stripe_price_id:?string,amount:float|int|string,currency:?string,plan_type:?string}>  $previousPrices
     */
    private function syncPrices(Package $package, array $previousPrices): void
    {
        $previousByStripeId = [];
        foreach ($previousPrices as $row) {
            $id = isset($row['stripe_price_id']) ? trim((string) $row['stripe_price_id']) : '';
            if ($id !== '') {
                $previousByStripeId[$id] = $row;
            }
        }

        $keptStripeIds = [];

        foreach ($package->prices as $price) {
            $existingId = $price->stripe_price_id !== null ? trim((string) $price->stripe_price_id) : '';

            if ($existingId === '') {
                $this->createStripePrice($package, $price);
                if (! empty($price->stripe_price_id)) {
                    $keptStripeIds[] = (string) $price->stripe_price_id;
                }

                continue;
            }

            $previous = $previousByStripeId[$existingId] ?? null;
            if ($previous !== null && $this->priceImmutableFieldsChanged($previous, $price)) {
                // Stripe prices are immutable — create a replacement; orphan cleanup archives the old ID.
                $price->stripe_price_id = null;
                $this->createStripePrice($package, $price);

                if (! empty($price->stripe_price_id)) {
                    $keptStripeIds[] = (string) $price->stripe_price_id;
                }

                continue;
            }

            $keptStripeIds[] = $existingId;
        }

        foreach (array_keys($previousByStripeId) as $oldId) {
            if (! in_array($oldId, $keptStripeIds, true)) {
                $this->archiveStripePrice($oldId);
            }
        }
    }

    /**
     * @param  array{stripe_price_id:?string,amount:float|int|string,currency:?string,plan_type:?string}  $previous
     */
    private function priceImmutableFieldsChanged(array $previous, SubscriptionProductPrice $current): bool
    {
        $prevAmount = (int) round((float) ($previous['amount'] ?? 0) * 100);
        $currAmount = (int) round((float) $current->amount * 100);

        $prevCurrency = strtolower((string) ($previous['currency'] ?? 'usd'));
        $currCurrency = strtolower((string) ($current->currency ?? 'usd'));

        $prevPlan = (string) ($previous['plan_type'] ?? '');
        $currPlan = (string) $current->plan_type;

        return $prevAmount !== $currAmount
            || $prevCurrency !== $currCurrency
            || $prevPlan !== $currPlan;
    }

    private function createStripePrice(Package $package, SubscriptionProductPrice $price): void
    {
        if (empty($package->stripe_product_id)) {
            return;
        }

        $unitAmount = (int) round((float) $price->amount * 100);

        // Do not use array_filter() — it drops unit_amount=0 (free tiers).
        $data = [
            'product' => $package->stripe_product_id,
            'unit_amount' => $unitAmount,
            'currency' => (string) ($price->currency ?? 'usd'),
            'metadata' => ['plan_type' => (string) $price->plan_type],
        ];

        if (is_string($price->nickname) && trim($price->nickname) !== '') {
            $data['nickname'] = trim($price->nickname);
        }

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

    private function archiveStripePrice(?string $stripePriceId): void
    {
        $id = $stripePriceId !== null ? trim($stripePriceId) : '';
        if ($id === '') {
            return;
        }

        $this->client->prices->update($id, ['active' => false]);
    }

    /**
     * @return array{name:string,description?:string,active:bool,metadata:array<string,string>}
     */
    private function productPayload(Package $package): array
    {
        $payload = [
            'name' => (string) $package->plan_name,
            'active' => (string) $package->status === 'active',
            'metadata' => [
                'package_type' => (string) $package->plan_type,
                'status' => (string) $package->status,
            ],
        ];

        if ($package->description) {
            $payload['description'] = (string) $package->description;
        }

        return $payload;
    }

    private function syncPackageDefaultPriceId(Package $package): void
    {
        $package->load('prices');
        $first = $package->prices->first();
        $defaultId = $first?->stripe_price_id;

        if ((string) ($package->stripe_price_id ?? '') !== (string) ($defaultId ?? '')) {
            $package->stripe_price_id = $defaultId;
            $package->saveQuietly();
        }
    }
}

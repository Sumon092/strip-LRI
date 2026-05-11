<?php

namespace StripeLri\Support;

use Illuminate\Support\Facades\Schema;
use StripeLri\Models\Package;
use StripeLri\Models\PremiumFeature;

/**
 * Maps Eloquent {@see Package} rows to admin Inertia list/form shapes (no demo catalog).
 */
final class PackagePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function emptyForm(): array
    {
        $base = [
            'name'             => '',
            'stripe_product_id' => '',
            'package_type'     => 'stripe_plan',
            'credit_limit'     => 0,
            'site_limit'       => 0,
            'status'           => 'draft',
            'is_popular'       => false,
            'is_featured'      => false,
            'description'      => '',
            'items'            => [['id' => null, 'name' => '']],
            'prices'           => [
                [
                    'plan_type'      => 'monthly',
                    'stripe_price_id' => null,
                    'amount'         => 0,
                    'nickname'       => 'Monthly',
                ],
            ],
        ];

        if (self::premiumFeaturesEnabled()) {
            $base['premium_features'] = self::premiumFeaturesStateForForm(null);
        }

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    public static function toIndexRow(Package $p): array
    {
        $items  = self::resolveItems($p);
        $prices = self::resolvePrices($p);
        $meta   = is_array($p->metadata) ? $p->metadata : [];

        $namedItems = array_values(array_filter(
            $items,
            static fn (mixed $it): bool => is_array($it) && trim((string) ($it['name'] ?? '')) !== '',
        ));

        $creditLimit = (int) ($meta['credit_limit'] ?? 0);
        if ($creditLimit === 0 && Schema::hasColumn($p->getTable(), 'credits_limit') && $p->getAttribute('credits_limit') !== null) {
            $creditLimit = (int) $p->getAttribute('credits_limit');
        }

        $siteLimit = (int) ($meta['site_limit'] ?? 0);
        if ($siteLimit === 0 && Schema::hasColumn($p->getTable(), 'site_limit') && $p->getAttribute('site_limit') !== null) {
            $siteLimit = (int) $p->getAttribute('site_limit');
        }

        $status = (string) $p->status;
        if (! in_array($status, ['active', 'inactive', 'draft'], true)) {
            $status = 'active';
        }

        $row = [
            'id'               => (int) $p->getKey(),
            'stripe_product_id' => (string) ($p->stripe_product_id ?? ''),
            'name'             => (string) $p->plan_name,
            'package_type'     => (string) ($meta['package_type'] ?? $p->plan_type ?? 'stripe_plan'),
            'payment_type'     => (string) ($meta['payment_type'] ?? 'subscription'),
            'user_scope'       => (string) ($meta['user_scope'] ?? 'All'),
            'credit_limit'     => $creditLimit,
            'site_limit'       => $siteLimit,
            'is_popular'       => (bool) $p->is_popular,
            'is_featured'      => (bool) $p->is_featured,
            'status'           => $status,
            'description'      => (string) ($p->description ?? ''),
            'active'           => $status === 'active',
            'total_prices'     => max(1, count($prices)),
            /** Human-readable amounts for the admin list (not the price-tier count). */
            'price_summary'    => self::formatIndexPriceSummary($p, $prices),
            'total_items'      => max(1, count($namedItems) > 0 ? count($namedItems) : 1),
            'last_synced_at'   => $p->updated_at?->toIso8601String(),
            'created_at'       => $p->created_at?->toIso8601String(),
            'updated_at'       => $p->updated_at?->toIso8601String(),
        ];

        if (self::premiumFeaturesEnabled() && $p->relationLoaded('premiumFeatures')) {
            $total = $p->premiumFeatures->count();
            $included = $p->premiumFeatures->filter(
                static fn (PremiumFeature $pf): bool => (bool) ($pf->pivot->is_included ?? false),
            )->count();
            $row['premium_features_summary'] = $total > 0 ? "{$included}/{$total}" : '—';
        } else {
            $row['premium_features_summary'] = null;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public static function toForm(Package $p): array
    {
        $items  = self::resolveItems($p);
        $prices = self::resolvePrices($p);
        $meta   = is_array($p->metadata) ? $p->metadata : [];

        $creditLimit = (int) ($meta['credit_limit'] ?? 0);
        if ($creditLimit === 0 && Schema::hasColumn($p->getTable(), 'credits_limit') && $p->getAttribute('credits_limit') !== null) {
            $creditLimit = (int) $p->getAttribute('credits_limit');
        }

        $siteLimit = (int) ($meta['site_limit'] ?? 0);
        if ($siteLimit === 0 && Schema::hasColumn($p->getTable(), 'site_limit') && $p->getAttribute('site_limit') !== null) {
            $siteLimit = (int) $p->getAttribute('site_limit');
        }

        $status = (string) $p->status;
        if (! in_array($status, ['active', 'inactive', 'draft'], true)) {
            $status = 'active';
        }

        $form = [
            'name'             => (string) $p->plan_name,
            'stripe_product_id' => (string) ($p->stripe_product_id ?? ''),
            'package_type'     => (string) ($meta['package_type'] ?? $p->plan_type ?? 'stripe_plan'),
            'credit_limit'     => $creditLimit,
            'site_limit'       => $siteLimit,
            'status'           => $status,
            'is_popular'       => (bool) $p->is_popular,
            'is_featured'      => (bool) $p->is_featured,
            'description'      => (string) ($p->description ?? ''),
            'items'            => self::normalizeItemsForForm($items),
            'prices'           => self::normalizePricesForForm($prices),
        ];

        if (self::premiumFeaturesEnabled()) {
            $form['premium_features'] = self::premiumFeaturesStateForForm($p);
        }

        return $form;
    }

    /**
     * @param  array<string, mixed>  $validated  Output of StorePackageRequest / UpdatePackageRequest
     * @return array<string, mixed>
     */
    public static function validatedToAttributes(array $validated): array
    {
        $items = self::normalizeItemsInput($validated['items'] ?? []);
        $prices = self::normalizePricesInput($validated['prices'] ?? []);

        $meta = [
            'package_type' => (string) $validated['package_type'],
            'payment_type' => isset($validated['payment_type']) ? (string) $validated['payment_type'] : 'subscription',
            'user_scope' => isset($validated['user_scope']) ? (string) $validated['user_scope'] : 'All',
            'credit_limit' => (int) ($validated['credit_limit'] ?? 0),
            'site_limit' => (int) ($validated['site_limit'] ?? 0),
            'items' => $items,
            'prices' => $prices,
        ];

        $first = $prices[0] ?? [];
        $planType = (string) ($first['plan_type'] ?? 'monthly');
        $billingCycle = in_array($planType, ['monthly', 'yearly'], true) ? $planType : null;
        $amount = isset($first['amount']) ? (float) $first['amount'] : 0.0;

        $productId = isset($validated['stripe_product_id']) && is_string($validated['stripe_product_id']) && trim($validated['stripe_product_id']) !== ''
            ? trim($validated['stripe_product_id'])
            : null;

        $attrs = [
            'plan_name' => (string) $validated['name'],
            'description' => ($validated['description'] ?? '') !== '' ? (string) $validated['description'] : null,
            'plan_type' => (string) $validated['package_type'],
            'price' => $amount,
            'billing_cycle' => $billingCycle,
            'status' => (string) $validated['status'],
            'stripe_price_id' => isset($first['stripe_price_id']) && $first['stripe_price_id'] !== null && $first['stripe_price_id'] !== ''
                ? (string) $first['stripe_price_id']
                : null,
            'stripe_product_id' => $productId,
            'metadata' => $meta,
            'sort_order'  => 0,
            'is_popular'  => (bool) ($validated['is_popular'] ?? false),
            'is_featured' => (bool) ($validated['is_featured'] ?? false),
            'allow_trial' => false,
            'max_devices' => null,
        ];

        if (Schema::hasColumn((new Package)->getTable(), 'credits_limit')) {
            $attrs['credits_limit'] = (int) ($validated['credit_limit'] ?? 0);
        }

        if (Schema::hasColumn((new Package)->getTable(), 'site_limit')) {
            $attrs['site_limit'] = (int) ($validated['site_limit'] ?? 0);
        }

        return $attrs;
    }

    /**
     * Persist nested items/prices to normalized tables (metadata is still written in {@see validatedToAttributes}).
     *
     * @param  array<string, mixed>  $validated
     */
    public static function syncChildTables(Package $package, array $validated): void
    {
        $items = self::normalizeItemsInput($validated['items'] ?? []);
        $prices = self::normalizePricesInput($validated['prices'] ?? []);

        $package->items()->delete();
        $sort = 0;
        foreach ($items as $row) {
            $package->items()->create([
                'name' => $row['name'],
                'sort_order' => $sort++,
                'metadata' => [],
            ]);
        }

        $package->prices()->delete();
        foreach ($prices as $row) {
            $meta = [];
            if (($row['plan_type'] ?? '') === 'yearly' && isset($row['yearly_discount_percent'])) {
                $meta['yearly_discount_percent'] = (int) $row['yearly_discount_percent'];
            }
            $package->prices()->create([
                'plan_type' => (string) ($row['plan_type'] ?? 'monthly'),
                'stripe_price_id' => $row['stripe_price_id'] ?? null,
                'amount' => (float) ($row['amount'] ?? 0),
                'currency' => 'usd',
                'nickname' => (string) ($row['nickname'] ?? ''),
                'metadata' => $meta,
            ]);
        }

        if (! self::premiumFeaturesEnabled() || ! Schema::hasTable('subscription_product_premium_feature')) {
            return;
        }

        $premiumRows = $validated['premium_features'] ?? null;
        if (! is_array($premiumRows)) {
            return;
        }

        $sync = [];
        foreach ($premiumRows as $row) {
            if (! is_array($row) || ! isset($row['id'])) {
                continue;
            }
            $id = (int) $row['id'];
            if ($id < 1) {
                continue;
            }
            $sync[$id] = ['is_included' => (bool) ($row['included'] ?? false)];
        }

        $package->premiumFeatures()->sync($sync);
    }

    private static function premiumFeaturesEnabled(): bool
    {
        return (bool) config('stripe-lri.premium_features')
            && Schema::hasTable('premium_features');
    }

    /**
     * @return list<array{id: int, name: string, included: bool}>
     */
    private static function premiumFeaturesStateForForm(?Package $package): array
    {
        if (! self::premiumFeaturesEnabled()) {
            return [];
        }

        $catalog = PremiumFeature::query()->orderBy('sort_order')->orderBy('id')->get();

        $byId = collect();
        if ($package !== null && $package->exists) {
            $package->loadMissing('premiumFeatures');
            $byId = $package->premiumFeatures->keyBy(static fn (PremiumFeature $pf): int => (int) $pf->getKey());
        }

        $out = [];
        foreach ($catalog as $f) {
            $pivot = $byId->get($f->getKey());

            $out[] = [
                'id' => (int) $f->getKey(),
                'name' => (string) $f->name,
                'included' => (bool) ($pivot?->pivot?->is_included ?? false),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function resolveItems(Package $p): array
    {
        if ($p->exists && $p->items->isNotEmpty()) {
            $out = [];
            foreach ($p->items as $row) {
                $out[] = [
                    'id' => (string) $row->getKey(),
                    'name' => (string) $row->name,
                ];
            }

            return $out;
        }

        $meta = is_array($p->metadata) ? $p->metadata : [];
        $items = isset($meta['items']) && is_array($meta['items']) ? $meta['items'] : [];

        return $items !== [] ? $items : [['id' => null, 'name' => '']];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function resolvePrices(Package $p): array
    {
        if ($p->exists && $p->prices->isNotEmpty()) {
            $out = [];
            foreach ($p->prices as $row) {
                $m = is_array($row->metadata) ? $row->metadata : [];
                $entry = [
                    'plan_type' => (string) $row->plan_type,
                    'stripe_price_id' => $row->stripe_price_id,
                    'amount' => (float) $row->amount,
                    'nickname' => (string) ($row->nickname ?? ''),
                ];
                if ($row->plan_type === 'yearly' && isset($m['yearly_discount_percent'])) {
                    $entry['yearly_discount_percent'] = (int) $m['yearly_discount_percent'];
                }
                $out[] = $entry;
            }

            return $out;
        }

        $meta = is_array($p->metadata) ? $p->metadata : [];
        $prices = isset($meta['prices']) && is_array($meta['prices']) ? $meta['prices'] : [];

        if ($prices === []) {
            return self::pricesFromLegacyColumns($p);
        }

        return $prices;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{id: string|null, name: string}>
     */
    private static function normalizeItemsForForm(array $items): array
    {
        $out = [];
        foreach ($items as $it) {
            if (! is_array($it)) {
                continue;
            }
            $out[] = [
                'id' => isset($it['id']) && $it['id'] !== null && $it['id'] !== '' ? (string) $it['id'] : null,
                'name' => (string) ($it['name'] ?? ''),
            ];
        }

        return $out !== [] ? $out : [['id' => null, 'name' => '']];
    }

    /**
     * @param  list<array<string, mixed>>  $prices
     * @return list<array<string, mixed>>
     */
    private static function normalizePricesForForm(array $prices): array
    {
        $out = [];
        foreach ($prices as $row) {
            if (! is_array($row)) {
                continue;
            }
            $pt = (string) ($row['plan_type'] ?? 'monthly');
            $entry = [
                'plan_type' => in_array($pt, ['monthly', 'yearly', 'lifetime'], true) ? $pt : 'monthly',
                'stripe_price_id' => isset($row['stripe_price_id']) && $row['stripe_price_id'] !== ''
                    ? (string) $row['stripe_price_id']
                    : null,
                'amount' => isset($row['amount']) ? (float) $row['amount'] : (isset($row['unit_amount']) ? round(((int) $row['unit_amount']) / 100, 2) : 0.0),
                'nickname' => (string) ($row['nickname'] ?? ''),
            ];
            if ($entry['plan_type'] === 'yearly' && isset($row['yearly_discount_percent'])) {
                $entry['yearly_discount_percent'] = (int) $row['yearly_discount_percent'];
            }
            $out[] = $entry;
        }

        return $out !== [] ? $out : [
            ['plan_type' => 'monthly', 'stripe_price_id' => null, 'amount' => 0, 'nickname' => 'Monthly'],
        ];
    }

    /**
     * @param  list<mixed>  $items
     * @return list<array{id: int|null, name: string}>
     */
    private static function normalizeItemsInput(array $items): array
    {
        $out = [];
        foreach ($items as $it) {
            if (! is_array($it)) {
                continue;
            }
            $name = trim((string) ($it['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $id = $it['id'] ?? null;
            $out[] = [
                'id' => $id !== null && $id !== '' && is_numeric($id) ? (int) $id : null,
                'name' => $name,
            ];
        }

        return $out !== [] ? $out : [['id' => null, 'name' => 'Default']];
    }

    /**
     * @param  list<mixed>  $prices
     * @return list<array<string, mixed>>
     */
    private static function normalizePricesInput(array $prices): array
    {
        $out = [];
        foreach ($prices as $row) {
            if (! is_array($row)) {
                continue;
            }
            $pt = (string) ($row['plan_type'] ?? 'monthly');
            $entry = [
                'plan_type' => in_array($pt, ['monthly', 'yearly', 'lifetime'], true) ? $pt : 'monthly',
                'stripe_price_id' => isset($row['stripe_price_id']) && $row['stripe_price_id'] !== null && $row['stripe_price_id'] !== ''
                    ? (string) $row['stripe_price_id']
                    : null,
                'amount' => round((float) ($row['amount'] ?? 0), 2),
                'nickname' => (string) ($row['nickname'] ?? ''),
            ];
            if ($entry['plan_type'] === 'yearly' && array_key_exists('yearly_discount_percent', $row)) {
                $entry['yearly_discount_percent'] = (int) $row['yearly_discount_percent'];
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function pricesFromLegacyColumns(Package $p): array
    {
        $cycle = $p->billing_cycle;
        $planType = in_array($cycle, ['monthly', 'yearly'], true) ? $cycle : 'lifetime';
        $amount = $p->price !== null ? (float) $p->price : 0.0;

        return [
            [
                'plan_type' => $planType,
                'stripe_price_id' => $p->stripe_price_id,
                'amount' => $amount,
                'nickname' => '',
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $prices
     */
    private static function formatIndexPriceSummary(Package $p, array $prices): string
    {
        if ($prices === []) {
            return '—';
        }

        $parts = [];
        foreach ($prices as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $amount = (float) ($row['amount'] ?? 0);
            $planType = (string) ($row['plan_type'] ?? '');
            $currency = 'usd';
            if ($p->relationLoaded('prices') && isset($p->prices[$i])) {
                $currency = strtolower((string) ($p->prices[$i]->currency ?: 'usd'));
            }
            $parts[] = self::formatMoneyForLocale($amount, $currency).self::indexPlanSuffix($planType);
        }

        return $parts !== [] ? implode(', ', $parts) : '—';
    }

    private static function indexPlanSuffix(string $planType): string
    {
        return match ($planType) {
            'monthly' => '/mo',
            'yearly' => '/yr',
            'lifetime' => ' once',
            default => '',
        };
    }

    private static function formatMoneyForLocale(float $amount, string $currency): string
    {
        $currency = strtolower($currency) !== '' ? strtolower($currency) : 'usd';

        if (class_exists(\NumberFormatter::class)) {
            $locale = class_exists(\Locale::class) ? \Locale::getDefault() : 'en_US';
            $fmt = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
            $out = $fmt->formatCurrency($amount, strtoupper($currency));
            if (is_string($out) && $out !== '') {
                return $out;
            }
        }

        $sym = match ($currency) {
            'usd' => '$',
            'eur' => '€',
            'gbp' => '£',
            default => strtoupper($currency).' ',
        };

        return $sym.number_format($amount, 2, '.', ',');
    }
}

<?php

declare(strict_types=1);

namespace StripeLri\Services;

use Illuminate\Support\Facades\Schema;
use StripeLri\Models\SubscriptionProductUser;

/**
 * Central read-only service for a user's billing state.
 * Returns active subscriptions, credits balance, and site usage — all
 * feature-flagged so callers need no knowledge of which features are enabled.
 *
 * Usage:
 *   $billing = app(UserBillingService::class)->forUser($userId);
 *   $active  = app(UserBillingService::class)->activeSubscription($userId);
 */
class UserBillingService
{
    private readonly bool $creditBased;
    private readonly bool $siteLimited;
    private readonly bool $hasCreditsBalance;
    private readonly bool $hasSiteCount;

    public function __construct()
    {
        $this->creditBased       = (bool) config('stripe-lri.credit_based');
        $this->siteLimited       = (bool) config('stripe-lri.site_limit');
        $this->hasCreditsBalance = $this->creditBased
            && Schema::hasColumn('subscription_product_user', 'credits_balance');
        $this->hasSiteCount      = $this->siteLimited
            && Schema::hasColumn('subscription_product_user', 'site_count');
    }

    /**
     * Full billing snapshot for a user.
     *
     * @return array{
     *   subscriptions: list<array<string, mixed>>,
     *   credits: int|null,
     *   site_count: int|null,
     *   site_limit: int|null,
     * }
     */
    public function forUser(int $userId): array
    {
        $rows = SubscriptionProductUser::query()
            ->with('product.prices')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->get();

        $activeRows = $rows->where('is_active', true);

        $subscriptions = $rows->map(fn (SubscriptionProductUser $row): array => $this->toRow($row))
            ->values()
            ->all();

        // Credits: sum across all active subscriptions
        $credits = null;
        if ($this->hasCreditsBalance) {
            $credits = (int) $activeRows->sum(
                fn (SubscriptionProductUser $r): int => (int) ($r->credits_balance ?? 0),
            );
        }

        // Sites: sum across all active subscriptions
        $siteCount = null;
        $siteLimit = null;
        if ($this->hasSiteCount) {
            $siteCount = (int) $activeRows->sum(
                fn (SubscriptionProductUser $r): int => (int) ($r->getAttribute('site_count') ?? 0),
            );
            $siteLimit = (int) $activeRows->sum(
                fn (SubscriptionProductUser $r): int => (int) ($r->product?->getAttribute('site_limit') ?? 0),
            );
        }

        return compact('subscriptions', 'credits', 'siteCount', 'siteLimit');
    }

    /**
     * Convenience: first active subscription row, or null if the user has none.
     * Includes credits / site fields when the features are enabled.
     *
     * @return array<string, mixed>|null
     */
    public function activeSubscription(int $userId): ?array
    {
        $row = SubscriptionProductUser::query()
            ->with('product.prices')
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->latest('started_at')
            ->first();

        if ($row === null) {
            return null;
        }

        $data = $this->toRow($row);

        if ($this->hasCreditsBalance) {
            $data['credits'] = (int) ($row->credits_balance ?? 0);
        }

        if ($this->hasSiteCount) {
            $data['site_count'] = (int) ($row->getAttribute('site_count') ?? 0);
            $data['site_limit'] = (int) ($row->product?->getAttribute('site_limit') ?? 0);
        }

        return $data;
    }

    /**
     * True when the user has at least one active subscription.
     */
    public function hasActiveSubscription(int $userId): bool
    {
        return SubscriptionProductUser::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->exists();
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function toRow(SubscriptionProductUser $row): array
    {
        $product  = $row->product;
        $price    = $product?->prices->first();
        $planType = $price?->plan_type ?? ($product?->billing_cycle ?? 'monthly');

        return [
            'id'                     => (int) $row->getKey(),
            'plan_name'              => (string) ($product?->plan_name ?? '—'),
            'plan_type'              => $planType,
            'is_active'              => (bool) $row->is_active,
            'status'                 => $row->is_active ? 'active' : 'expired',
            'started_at'             => $row->started_at?->toIso8601String(),
            'expires_at'             => $row->expires_at?->toIso8601String(),
            'stripe_subscription_id' => $row->stripe_subscription_id,
        ];
    }
}

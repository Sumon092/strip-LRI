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
 *   $all     = app(UserBillingService::class)->activeSubscriptions($userId);  // all active, oldest-first (FIFO)
 *   $first   = app(UserBillingService::class)->activeSubscription($userId);   // oldest active, or null
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
     *   active: list<array<string, mixed>>,
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

        // Active rows ordered oldest-first: ensures FIFO credit/site consumption
        $activeRows = $rows->where('is_active', true)->sortBy('started_at')->values();

        $subscriptions = $rows->map(fn (SubscriptionProductUser $row): array => $this->toRow($row))
            ->values()
            ->all();

        $active = $activeRows->map(fn (SubscriptionProductUser $row): array => $this->toRowWithFeatures($row))
            ->values()
            ->all();

        // Credits: sum across all active subscriptions (oldest-first order)
        $credits = null;
        if ($this->hasCreditsBalance) {
            $credits = (int) $activeRows->sum(
                fn (SubscriptionProductUser $r): int => (int) ($r->credits_balance ?? 0),
            );
        }

        // Sites: sum across all active subscriptions (oldest-first order)
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

        return compact('subscriptions', 'active', 'credits', 'siteCount', 'siteLimit');
    }

    /**
     * All active subscriptions for a user, ordered oldest-started first (FIFO).
     * Credits and site data are included per subscription when features are enabled.
     * Use this when iterating subscriptions for credit/site deduction — consume
     * the first entry before moving to subsequent ones.
     *
     * @return list<array<string, mixed>>
     */
    public function activeSubscriptions(int $userId): array
    {
        $rows = SubscriptionProductUser::query()
            ->with('product.prices')
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->oldest('started_at')
            ->get();

        return $rows->map(fn (SubscriptionProductUser $row): array => $this->toRowWithFeatures($row))
            ->values()
            ->all();
    }

    /**
     * Convenience: oldest active subscription (first to consume credits/sites), or null.
     *
     * @return array<string, mixed>|null
     */
    public function activeSubscription(int $userId): ?array
    {
        return $this->activeSubscriptions($userId)[0] ?? null;
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

    /** @return array<string, mixed> */
    private function toRowWithFeatures(SubscriptionProductUser $row): array
    {
        $data = $this->toRow($row);

        if ($this->hasCreditsBalance) {
            $data['credits_balance'] = (int) ($row->credits_balance ?? 0);
        }

        if ($this->hasSiteCount) {
            $data['site_count'] = (int) ($row->getAttribute('site_count') ?? 0);
            $data['site_limit'] = (int) ($row->product?->getAttribute('site_limit') ?? 0);
        }

        return $data;
    }
}

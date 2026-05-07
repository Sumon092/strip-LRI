<?php

namespace StripeLri\Support;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Static demo payloads shaped like FollowMate admin APIs (UI-only).
 */
final class DemoCatalog
{
    /**
     * @param  list<array<string, mixed>>|array<int, mixed>  $items
     */
    public static function paginate(
        array $items,
        Request $request,
        string $routeName,
        int $defaultPerPage = 12,
        array $allowedPerPage = [12, 25, 50, 100],
        string $pageName = 'page',
    ): LengthAwarePaginator {
        $perPage = (int) $request->query('per_page', $defaultPerPage);
        if (! in_array($perPage, $allowedPerPage, true)) {
            $perPage = $defaultPerPage;
        }
        $page = max(1, (int) $request->query($pageName, 1));
        $collection = collect($items);
        $total = $collection->count();
        $slice = $collection->forPage($page, $perPage)->values()->all();

        return (new LengthAwarePaginator($slice, $total, $perPage, $page, [
            'path' => route($routeName),
            'pageName' => $pageName,
        ]))->withQueryString();
    }

    /** @return list<array<string, mixed>> */
    public static function users(): array
    {
        $base = [
            ['name' => 'Alex Morgan', 'email' => 'alex@example.com', 'username' => 'alexm', 'role' => 'admin', 'type' => 'Premium'],
            ['name' => 'Jamie Chen', 'email' => 'jamie@example.com', 'username' => 'jamiec', 'role' => 'user', 'type' => 'Premium'],
            ['name' => 'Riley Santos', 'email' => 'riley@example.com', 'username' => null, 'role' => 'user', 'type' => 'Free'],
            ['name' => 'Taylor Brooks', 'email' => 'taylor@example.com', 'username' => 'tbrooks', 'role' => 'user', 'type' => 'Premium'],
            ['name' => 'Morgan Lee', 'email' => 'morgan@example.com', 'username' => 'mlee', 'role' => 'user', 'type' => 'Free'],
            ['name' => 'Casey Nguyen', 'email' => 'casey@example.com', 'username' => 'cnguyen', 'role' => 'user', 'type' => 'Premium'],
            ['name' => 'Jordan Patel', 'email' => 'jordan@example.com', 'username' => 'jpatel', 'role' => 'user', 'type' => 'Free'],
            ['name' => 'Skyler White', 'email' => 'skyler@example.com', 'username' => 'skylerw', 'role' => 'user', 'type' => 'Premium'],
            ['name' => 'Drew Martinez', 'email' => 'drew@example.com', 'username' => 'drewm', 'role' => 'user', 'type' => 'Free'],
            ['name' => 'Reese Kim', 'email' => 'reese@example.com', 'username' => 'rkim', 'role' => 'user', 'type' => 'Premium'],
            ['name' => 'Avery Singh', 'email' => 'avery@example.com', 'username' => 'asingh', 'role' => 'user', 'type' => 'Free'],
            ['name' => 'Quinn Rivera', 'email' => 'quinn@example.com', 'username' => 'qrivera', 'role' => 'user', 'type' => 'Premium'],
            ['name' => 'Blake Ortiz', 'email' => 'blake@example.com', 'username' => 'bortiz', 'role' => 'user', 'type' => 'Free'],
            ['name' => 'Cameron Diaz', 'email' => 'cameron@example.com', 'username' => 'cdiaz', 'role' => 'user', 'type' => 'Premium'],
            ['name' => 'Parker Hall', 'email' => 'parker@example.com', 'username' => 'phall', 'role' => 'user', 'type' => 'Free'],
            ['name' => 'Logan Wright', 'email' => 'logan@example.com', 'username' => 'lwright', 'role' => 'user', 'type' => 'Premium'],
            ['name' => 'Harper Green', 'email' => 'harper@example.com', 'username' => 'hgreen', 'role' => 'user', 'type' => 'Free'],
            ['name' => 'Rowan Adams', 'email' => 'rowan@example.com', 'username' => 'radams', 'role' => 'user', 'type' => 'Premium'],
        ];

        $rows = [];
        $now = now()->toIso8601String();
        foreach ($base as $i => $u) {
            $id = $i + 1;
            $active = $id !== 14;
            $premium = $u['type'] === 'Premium';
            $plan = $premium ? 5000 : 500;
            $used = $premium ? 120 + ($id * 17) % 400 : 10 + ($id * 3) % 80;
            $left = max(0, $plan - $used);
            $rows[] = [
                'id' => $id,
                'name' => $u['name'],
                'email' => $u['email'],
                'username' => $u['username'],
                'role' => $u['role'],
                'credits_used' => $used,
                'remaining_credits' => $left,
                'plan_credits' => $plan,
                'type' => $u['type'],
                'last_login_at' => $active ? now()->subHours($id * 3)->toIso8601String() : null,
                'last_login_ip' => $active ? '192.0.2.'.(10 + ($id % 200)) : null,
                'status' => $active ? 'active' : 'inactive',
                'is_active' => $active,
                'email_verified_at' => $id % 5 === 0 ? null : $now,
                'subscribed_at' => $premium ? now()->subMonths($id % 8)->toIso8601String() : null,
                'created_at' => now()->subMonths(3 + $id)->toIso8601String(),
                'avatar' => null,
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed>|null */
    public static function findUser(int $id): ?array
    {
        foreach (self::users() as $u) {
            if ((int) $u['id'] === $id) {
                return $u;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public static function accountLogs(): array
    {
        return [
            ['id' => 1, 'email' => 'former1@example.com', 'name' => 'Former One', 'handle' => 'f1', 'removal_type' => 'soft', 'subject_user_id' => 101, 'created_at' => now()->subDays(2)->toIso8601String(), 'admin_name' => 'Alex Morgan'],
            ['id' => 2, 'email' => 'former2@example.com', 'name' => 'Former Two', 'handle' => null, 'removal_type' => 'permanent', 'subject_user_id' => 102, 'created_at' => now()->subDays(5)->toIso8601String(), 'admin_name' => 'Alex Morgan'],
            ['id' => 3, 'email' => 'spam@example.com', 'name' => 'Spam Bot', 'handle' => 'spam', 'removal_type' => 'permanent', 'subject_user_id' => 55, 'created_at' => now()->subDays(9)->toIso8601String(), 'admin_name' => 'Jamie Chen'],
            ['id' => 4, 'email' => 'old@example.com', 'name' => 'Old Account', 'handle' => null, 'removal_type' => 'soft', 'subject_user_id' => null, 'created_at' => now()->subDays(12)->toIso8601String(), 'admin_name' => 'Alex Morgan'],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function transactions(): array
    {
        $rows = [];
        for ($i = 1; $i <= 24; $i++) {
            $ok = $i % 7 !== 0;
            $rows[] = [
                'id' => $i,
                'reference' => 'TXN-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'customerName' => 'Customer '.$i,
                'customerEmail' => 'cust'.$i.'@example.com',
                'customerHandle' => 'cust'.$i,
                'planName' => $i % 3 === 0 ? 'Pro annual' : 'Pro monthly',
                'type' => 'Subscription',
                'invoiceNumber' => 'INV-'.(2400 + $i),
                'invoiceReason' => 'subscription_cycle',
                'invoicePeriod' => now()->subDays($i)->format('M Y'),
                'invoiceUrl' => null,
                'invoicePdfUrl' => null,
                'amount' => '$'.number_format(29 + ($i % 5) * 10, 2),
                'amountReceived' => $ok ? '$'.number_format(29 + ($i % 5) * 10, 2) : '$0.00',
                'method' => $i % 2 === 0 ? 'Card' : 'Link',
                'status' => $ok ? 'Paid' : 'Failed',
                'statusVariant' => $ok ? 'success' : 'danger',
                'date' => now()->subDays($i)->format('M j, Y'),
                'createdAt' => now()->subDays($i)->toIso8601String(),
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public static function invoices(): array
    {
        $rows = [];
        for ($i = 1; $i <= 22; $i++) {
            $paid = $i % 6 !== 0;
            $rows[] = [
                'id' => $i,
                'number' => 'INV-'.(5000 + $i),
                'customerName' => 'Invoice Customer '.$i,
                'customerEmail' => 'inv'.$i.'@example.com',
                'customerHandle' => 'inv'.$i,
                'planName' => 'Team plan',
                'status' => $paid ? 'Paid' : 'Open',
                'statusVariant' => $paid ? 'success' : 'warning',
                'planPrice' => '$'.number_format(99, 2),
                'discount' => $i % 4 === 0 ? '$10.00' : '$0.00',
                'promoCode' => $i % 4 === 0 ? 'SAVE10' : null,
                'amountPaid' => $paid ? '$99.00' : '$0.00',
                'period' => now()->subDays($i)->format('M j').' – '.now()->subDays($i - 30)->format('M j, Y'),
                'date' => now()->subDays($i)->format('M j, Y'),
                'credits' => 500 + ($i % 5) * 250,
                'viewUrl' => null,
                'pdfUrl' => null,
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public static function premiumInvoices(): array
    {
        $rows = [];
        for ($i = 1; $i <= 16; $i++) {
            $planBilling = match (true) {
                $i % 5 === 0 => 'lifetime',
                $i % 2 === 0 => 'yearly',
                default => 'monthly',
            };
            $product = match ($planBilling) {
                'lifetime' => 'Pro lifetime',
                'yearly' => 'Pro annual',
                default => 'Pro monthly',
            };
            $rows[] = [
                'id' => $i,
                'number' => 'PM-'.(800 + $i),
                'customerName' => 'Premium User '.$i,
                'customerEmail' => 'prem'.$i.'@example.com',
                'customerHandle' => 'prem'.$i,
                'planBilling' => $planBilling,
                'product' => $product,
                'reason' => 'subscription_cycle',
                'subtotal' => '$'.number_format(120, 2),
                'discount' => '$0.00',
                'paid' => '$120.00',
                'promoCode' => '—',
                'invoiceStatus' => 'Paid',
                'invoiceStatusVariant' => 'success',
                'period' => now()->subDays($i)->format('M Y'),
                'paidAt' => now()->subDays($i)->toIso8601String(),
                'subscriptionStatus' => $i % 9 === 0 ? 'Canceled' : 'Active',
                'subscriptionStatusVariant' => $i % 9 === 0 ? 'danger' : 'success',
                'subscriptionScheduleLine' => 'Renews '.now()->addMonth()->format('M j, Y'),
                'subscriptionScheduleSub' => null,
                'cancelReasonDetail' => $i % 9 === 0 ? 'User churn' : null,
                'canViewCancelReason' => $i % 9 === 0,
                'cancellationReason' => $i % 9 === 0 ? 'Too expensive' : null,
                'userId' => 100 + $i,
                'userRole' => 'user',
                'userIsActive' => true,
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public static function products(): array
    {
        return [
            [
                'id' => 1,
                'stripe_product_id' => 'prod_demo_1',
                'name' => 'Starter credits',
                'package_type' => 'stripe_plan',
                'payment_type' => 'free',
                'user_scope' => 'All',
                'credit_limit' => 500,
                'status' => 'active',
                'description' => 'Entry tier',
                'active' => true,
                'default_stripe_price_id' => 'price_demo_1',
                'total_prices' => 2,
                'total_items' => 1,
                'created_at' => now()->subMonths(2)->toIso8601String(),
                'updated_at' => now()->subDays(1)->toIso8601String(),
                'last_synced_at' => now()->subHours(6)->toIso8601String(),
                'prices' => [
                    [
                        'id' => 10,
                        'stripe_price_id' => 'price_m',
                        'plan_type' => 'monthly',
                        'nickname' => 'Monthly',
                        'currency' => 'usd',
                        'unit_amount' => 2900,
                        'type' => 'recurring',
                        'interval' => 'month',
                        'interval_count' => 1,
                        'active' => true,
                        'lookup_key' => null,
                        'tax_behavior' => 'exclusive',
                        'created_at' => null,
                        'updated_at' => null,
                        'items' => [
                            ['id' => 1, 'stripe_product_item_id' => 'si_1', 'name' => '500 credits', 'active' => true, 'sort_order' => 0, 'created_at' => null, 'updated_at' => null],
                        ],
                    ],
                    [
                        'id' => 11,
                        'stripe_price_id' => 'price_y',
                        'plan_type' => 'yearly',
                        'nickname' => 'Yearly',
                        'currency' => 'usd',
                        'unit_amount' => 29000,
                        'type' => 'recurring',
                        'interval' => 'year',
                        'interval_count' => 1,
                        'active' => true,
                        'lookup_key' => null,
                        'tax_behavior' => 'exclusive',
                        'created_at' => null,
                        'updated_at' => null,
                        'items' => [],
                    ],
                ],
            ],
            [
                'id' => 2,
                'stripe_product_id' => 'prod_demo_2',
                'name' => 'Growth pack',
                'package_type' => 'stripe_plan',
                'payment_type' => 'monthly',
                'user_scope' => '_',
                'credit_limit' => 5000,
                'status' => 'active',
                'description' => 'Scale usage',
                'active' => true,
                'default_stripe_price_id' => 'price_demo_2',
                'total_prices' => 1,
                'total_items' => 2,
                'created_at' => now()->subMonths(4)->toIso8601String(),
                'updated_at' => now()->subDays(3)->toIso8601String(),
                'last_synced_at' => now()->subDays(1)->toIso8601String(),
                'prices' => [
                    [
                        'id' => 20,
                        'stripe_price_id' => 'price_g',
                        'plan_type' => 'monthly',
                        'nickname' => 'Growth monthly',
                        'currency' => 'usd',
                        'unit_amount' => 9900,
                        'type' => 'recurring',
                        'interval' => 'month',
                        'interval_count' => 1,
                        'active' => true,
                        'lookup_key' => null,
                        'tax_behavior' => 'exclusive',
                        'created_at' => null,
                        'updated_at' => null,
                        'items' => [
                            ['id' => 2, 'stripe_product_item_id' => 'si_2', 'name' => 'Main pool', 'active' => true, 'sort_order' => 0, 'created_at' => null, 'updated_at' => null],
                            ['id' => 3, 'stripe_product_item_id' => 'si_3', 'name' => 'Bonus pool', 'active' => true, 'sort_order' => 1, 'created_at' => null, 'updated_at' => null],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function findProduct(int $id): ?array
    {
        foreach (self::products() as $p) {
            if ((int) $p['id'] === $id) {
                return $p;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public static function emptyPackageForm(): array
    {
        return [
            'name' => '',
            'package_type' => 'stripe_plan',
            'credit_limit' => 500,
            'status' => 'draft',
            'description' => '',
            'items' => [['id' => null, 'name' => '']],
            'prices' => [
                [
                    'plan_type' => 'monthly',
                    'stripe_price_id' => null,
                    'amount' => 0,
                    'nickname' => 'Monthly',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function packageFormFromProduct(array $product): array
    {
        $prices = [];
        foreach ($product['prices'] as $price) {
            $prices[] = [
                'plan_type' => $price['plan_type'],
                'stripe_price_id' => $price['stripe_price_id'],
                'amount' => isset($price['unit_amount']) ? round(((int) $price['unit_amount']) / 100, 2) : 0,
                'nickname' => $price['nickname'] ?? '',
                'yearly_discount_percent' => ($price['plan_type'] ?? '') === 'yearly' ? 0 : null,
            ];
        }
        $items = [];
        foreach (($product['prices'][0]['items'] ?? []) as $it) {
            $items[] = ['id' => (string) $it['id'], 'name' => $it['name']];
        }
        if ($items === []) {
            $items = [['id' => null, 'name' => '']];
        }

        return [
            'name' => $product['name'],
            'package_type' => $product['package_type'],
            'credit_limit' => (int) $product['credit_limit'],
            'status' => $product['status'],
            'description' => (string) ($product['description'] ?? ''),
            'items' => $items,
            'prices' => $prices,
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function coupons(): array
    {
        return [
            ['id' => 1, 'code' => 'LAUNCH20', 'name' => 'Launch discount', 'coupon_type' => 'percent', 'percent_off' => 20, 'amount_off' => null, 'currency' => null, 'max_redemptions' => 100, 'is_active' => true, 'custom_validation_rules' => false, 'valid_from' => now()->subMonth()->toIso8601String(), 'valid_until' => now()->addMonths(2)->toIso8601String(), 'times_redeemed' => 12, 'stripe_coupon_id' => 'cpn_1', 'stripe_promotion_code_id' => 'promo_1'],
            ['id' => 2, 'code' => 'SAVE10USD', 'name' => 'Fixed welcome', 'coupon_type' => 'fixed_amount', 'percent_off' => null, 'amount_off' => 1000, 'currency' => 'usd', 'max_redemptions' => null, 'is_active' => true, 'custom_validation_rules' => true, 'valid_from' => null, 'valid_until' => null, 'times_redeemed' => 3, 'stripe_coupon_id' => 'cpn_2', 'stripe_promotion_code_id' => 'promo_2'],
            ['id' => 3, 'code' => 'EXPIRED', 'name' => 'Old promo', 'coupon_type' => 'percent', 'percent_off' => 15, 'amount_off' => null, 'currency' => null, 'max_redemptions' => 10, 'is_active' => false, 'custom_validation_rules' => false, 'valid_from' => now()->subYear()->toIso8601String(), 'valid_until' => now()->subMonths(6)->toIso8601String(), 'times_redeemed' => 10, 'stripe_coupon_id' => 'cpn_3', 'stripe_promotion_code_id' => 'promo_3'],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function findCoupon(int $id): ?array
    {
        foreach (self::coupons() as $c) {
            if ((int) $c['id'] === $id) {
                return $c;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public static function couponFormFromRow(array $row): array
    {
        $type = $row['coupon_type'];
        $value = $type === 'percent' && $row['percent_off'] !== null
            ? (string) $row['percent_off']
            : ($type === 'fixed_amount' && $row['amount_off'] !== null
                ? (string) round(((int) $row['amount_off']) / 100, 2)
                : '');

        return [
            'name' => $row['name'],
            'code' => $row['code'],
            'description' => '',
            'coupon_type' => $type,
            'value' => $value,
            'currency' => $row['currency'] ?? 'usd',
            'max_redemptions' => $row['max_redemptions'] !== null ? (string) $row['max_redemptions'] : '',
            'is_active' => (bool) $row['is_active'],
            'custom_validation_rules' => (bool) $row['custom_validation_rules'],
            'valid_from' => $row['valid_from'] ? substr((string) $row['valid_from'], 0, 10) : '',
            'valid_until' => $row['valid_until'] ? substr((string) $row['valid_until'], 0, 10) : '',
        ];
    }

    /** @return array<string, mixed> */
    public static function emptyCouponForm(): array
    {
        return [
            'name' => '',
            'code' => '',
            'description' => '',
            'coupon_type' => '',
            'value' => '',
            'currency' => 'usd',
            'max_redemptions' => '',
            'is_active' => true,
            'custom_validation_rules' => false,
            'valid_from' => '',
            'valid_until' => '',
        ];
    }

    /** @return list<string> */
    public static function currencies(): array
    {
        return ['usd', 'eur', 'gbp'];
    }

    /** @return list<array<string, mixed>> */
    public static function billingPayments(): array
    {
        $rows = [];
        for ($i = 1; $i <= 28; $i++) {
            $ok = $i % 6 !== 0;
            $rows[] = [
                'id' => $i,
                'reference' => 'PAY-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'planName' => $i % 4 === 0 ? 'Growth yearly' : 'Pro monthly',
                'amount' => '$'.number_format(29 + ($i % 5) * 15, 2),
                'status' => $ok ? 'Paid' : 'Failed',
                'statusVariant' => $ok ? 'success' : 'danger',
                'method' => $i % 2 === 0 ? 'Card' : 'Link',
                'paidAt' => now()->subDays($i)->format('M j, Y g:i A'),
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public static function billingCustomerInvoices(): array
    {
        $rows = [];
        for ($i = 1; $i <= 22; $i++) {
            $paid = $i % 5 !== 0;
            $rows[] = [
                'id' => $i,
                'number' => 'INV-U-'.(9100 + $i),
                'planName' => $i % 3 === 0 ? 'Team annual' : 'Pro monthly',
                'status' => $paid ? 'Paid' : 'Open',
                'statusVariant' => $paid ? 'success' : 'warning',
                'subtotal' => '$'.number_format(99, 2),
                'amountPaid' => $paid ? '$99.00' : '$0.00',
                'discountAmount' => $i % 4 === 0 ? '$10.00' : '$0.00',
                'promotionCode' => $i % 4 === 0 ? 'SAVE10' : '—',
                'billingReason' => $i % 2 === 0 ? 'subscription_cycle' : 'subscription_create',
                'period' => now()->subDays($i)->format('M j').' – '.now()->subDays($i - 27)->format('M j, Y'),
                'paidAt' => $paid ? now()->subDays($i)->format('M j, Y') : '—',
                'hostedInvoiceUrl' => $paid ? 'https://example.com/invoice/'.$i : null,
                'invoicePdfUrl' => $paid ? 'https://example.com/invoice/'.$i.'.pdf' : null,
            ];
        }

        return $rows;
    }

    /**
     * Workspace pricing plans payload (FollowMate-shaped, demo).
     *
     * @return array<string, mixed>
     */
    public static function workspacePricingPlans(): array
    {
        $feat = static fn (array $names): array => array_map(
            fn (string $n): array => ['name' => $n, 'description' => null],
            $names
        );

        return [
            'plans' => [
                'monthly' => [
                    [
                        'product_id' => 1,
                        'product_name' => 'Starter pack',
                        'product_description' => 'Essential workspace limits.',
                        'credit_limit' => 500,
                        'stripe_price_id' => 'price_demo_ws_starter_m',
                        'plan_type' => 'monthly',
                        'nickname' => null,
                        'currency' => 'usd',
                        'amount' => 2900,
                        'type' => 'recurring',
                        'interval' => 'month',
                        'interval_count' => 1,
                        'features' => $feat(['Email support', 'Usage analytics', 'API access']),
                    ],
                    [
                        'product_id' => 2,
                        'product_name' => 'Growth pack',
                        'product_description' => 'For scaling teams.',
                        'credit_limit' => 5000,
                        'stripe_price_id' => 'price_demo_ws_growth_m',
                        'plan_type' => 'monthly',
                        'nickname' => 'Most popular',
                        'currency' => 'usd',
                        'amount' => 9900,
                        'type' => 'recurring',
                        'interval' => 'month',
                        'interval_count' => 1,
                        'features' => $feat(['Priority support', 'Advanced exports', 'Audit log']),
                    ],
                    [
                        'product_id' => 3,
                        'product_name' => 'Scale pack',
                        'product_description' => 'Maximum throughput.',
                        'credit_limit' => 25000,
                        'stripe_price_id' => 'price_demo_ws_scale_m',
                        'plan_type' => 'monthly',
                        'nickname' => null,
                        'currency' => 'usd',
                        'amount' => 19900,
                        'type' => 'recurring',
                        'interval' => 'month',
                        'interval_count' => 1,
                        'features' => $feat(['Dedicated success', 'SLA', 'Custom integrations']),
                    ],
                ],
                'yearly' => [
                    [
                        'product_id' => 1,
                        'product_name' => 'Starter pack',
                        'product_description' => 'Essential workspace limits.',
                        'credit_limit' => 500,
                        'stripe_price_id' => 'price_demo_ws_starter_y',
                        'plan_type' => 'yearly',
                        'nickname' => null,
                        'currency' => 'usd',
                        'amount' => 29000,
                        'type' => 'recurring',
                        'interval' => 'year',
                        'interval_count' => 1,
                        'features' => $feat(['Email support', 'Usage analytics', 'API access']),
                    ],
                    [
                        'product_id' => 2,
                        'product_name' => 'Growth pack',
                        'product_description' => 'For scaling teams.',
                        'credit_limit' => 5000,
                        'stripe_price_id' => 'price_demo_ws_growth_y',
                        'plan_type' => 'yearly',
                        'nickname' => 'Best value',
                        'currency' => 'usd',
                        'amount' => 95000,
                        'type' => 'recurring',
                        'interval' => 'year',
                        'interval_count' => 1,
                        'features' => $feat(['Priority support', 'Advanced exports', 'Audit log']),
                    ],
                ],
                'lifetime' => [
                    [
                        'product_id' => 4,
                        'product_name' => 'Lifetime access',
                        'product_description' => 'One-time purchase for solo operators.',
                        'credit_limit' => 2000,
                        'stripe_price_id' => 'price_demo_ws_lifetime',
                        'plan_type' => 'lifetime',
                        'nickname' => null,
                        'currency' => 'usd',
                        'amount' => 49900,
                        'type' => 'one_time',
                        'interval' => null,
                        'interval_count' => null,
                        'features' => $feat(['All core features', 'No recurring fees', '12 months of updates']),
                    ],
                ],
            ],
            'currentSubscriptions' => [
                [
                    'id' => 1,
                    'type' => 'subscription',
                    'status' => 'active',
                    'plan_type' => 'yearly',
                    'stripe_price_id' => 'price_demo_ws_growth_y',
                    'cancel_at_period_end' => false,
                    'current_period_end_at' => now()->addMonth()->toIso8601String(),
                    'current_period_end_at_human' => now()->addMonth()->toFormattedDateString(),
                ],
            ],
            'recentInvoices' => [
                [
                    'id' => 1,
                    'number' => 'INV-WS-1001',
                    'status' => 'paid',
                    'currency' => 'usd',
                    'total' => 95000,
                    'hosted_invoice_url' => 'https://example.com/hosted/1001',
                    'paid_at' => now()->subDays(2)->toIso8601String(),
                    'paid_at_human' => now()->subDays(2)->toFormattedDateString(),
                ],
                [
                    'id' => 2,
                    'number' => 'INV-WS-0992',
                    'status' => 'paid',
                    'currency' => 'usd',
                    'total' => 29000,
                    'hosted_invoice_url' => 'https://example.com/hosted/0992',
                    'paid_at' => now()->subWeeks(6)->toIso8601String(),
                    'paid_at_human' => now()->subWeeks(6)->toFormattedDateString(),
                ],
            ],
            'yearlyDiscountPercent' => 20,
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function subscriptionHistory(): array
    {
        $rows = [];
        for ($i = 1; $i <= 26; $i++) {
            $rows[] = [
                'id' => $i,
                'planName' => $i % 3 === 0 ? 'Growth pack' : 'Starter pack',
                'billingCycle' => $i % 2 === 0 ? 'Yearly' : 'Monthly',
                'type' => $i % 7 === 0 ? 'one_time' : 'subscription',
                'price' => '$'.number_format(29 + ($i % 4) * 20, 2),
                'paidAmount' => '$'.number_format(29 + ($i % 4) * 20, 2),
                'credits' => (string) (500 + ($i % 5) * 250),
                'status' => $i === 1 ? 'Active' : ($i % 8 === 0 ? 'Canceled' : 'Complete'),
                'statusVariant' => $i === 1 ? 'success' : ($i % 8 === 0 ? 'danger' : 'neutral'),
                'accessUntil' => now()->subDays($i)->addMonth()->format('M j, Y'),
                'startDate' => now()->subDays($i + 30)->format('M j, Y'),
                'endDate' => now()->subDays($i)->format('M j, Y'),
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public static function subscriptionCenter(): array
    {
        return [
            'active' => [
                [
                    'id' => 1,
                    'planName' => 'Growth pack (yearly)',
                    'price' => '$950.00 / yr',
                    'paidAmount' => '$950.00',
                    'credits' => '5,000',
                    'period' => now()->subMonth()->format('M j, Y').' – '.now()->addMonths(11)->format('M j, Y'),
                    'status' => 'Active',
                    'statusVariant' => 'success',
                    'accessUntil' => now()->addMonths(11)->toFormattedDateString(),
                    'canCancel' => true,
                    'cancelScheduled' => false,
                ],
            ],
            'cancelNotice' => [
                'show' => false,
                'accessUntil' => now()->addMonths(11)->toFormattedDateString(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function adminCredentialsForm(string $suggestedGoogleRedirectUri): array
    {
        return [
            'suggestedGoogleRedirectUri' => $suggestedGoogleRedirectUri,
            'credentialForm' => [
                'google' => [
                    'client_id' => '',
                    'client_secret' => '',
                    'client_secret_set' => true,
                    'client_secret_suffix' => 'a1b2',
                    'redirect_uri' => $suggestedGoogleRedirectUri,
                ],
                'stripe' => [
                    'publishable_key' => 'pk_test_demo',
                    'secret_key' => '',
                    'secret_key_set' => true,
                    'secret_key_suffix' => 'x9y8',
                    'webhook_secret' => '',
                    'webhook_secret_set' => false,
                    'webhook_secret_suffix' => null,
                ],
                'mail' => [
                    'mailer' => 'smtp',
                    'host' => 'smtp.example.com',
                    'port' => '465',
                    'username' => 'notifications@example.com',
                    'password' => '',
                    'password_set' => true,
                    'password_suffix' => 'c3d4',
                    'encryption' => 'ssl',
                    'from_address' => 'hello@example.com',
                    'from_name' => 'Admin Template',
                ],
                'otp_mail' => [
                    'host' => '',
                    'port' => '465',
                    'username' => '',
                    'password' => '',
                    'password_set' => false,
                    'password_suffix' => null,
                    'encryption' => 'ssl',
                    'from_address' => '',
                    'from_name' => '',
                ],
                'welcome' => ['enabled' => true, 'credits' => '200'],
                'package_login' => ['enabled' => false],
                'pricing' => ['yearly_discount_percent' => 20],
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace StripeLri\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use StripeLri\Models\Invoice;
use StripeLri\Models\Package;
use StripeLri\Models\Payment;
use StripeLri\Models\Subscription;
use StripeLri\Models\SubscriptionProductUser;

class BillingLedgerController extends Controller
{
    public function transactions(Request $request): Response
    {
        $perPage = $this->perPage($request, [10, 12, 25, 50]);
        $creditBased = (bool) config('stripe-lri.credit_based');

        $paginator = Payment::query()
            ->with(['user', 'product'])
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Payment $p): array => [
                'reference'     => (string) ($p->stripe_payment_intent_id ?? $p->stripe_charge_id ?? '#'.$p->getKey()),
                'customerName'  => (string) ($p->user?->name ?? '—'),
                'customerEmail' => (string) ($p->user?->email ?? '—'),
                'type'          => (string) ($p->payment_type ?? '—'),
                'planName'      => (string) ($p->product?->plan_name ?? '—'),
                'amount'        => self::money((float) $p->amount, (string) ($p->currency ?? 'usd')),
                'method'        => self::paymentMethod($p->payment_method_details),
                'status'        => ucfirst((string) $p->status),
                'statusVariant' => self::paymentVariant((string) $p->status),
                'date'          => self::fmt($p->paid_at ?? $p->created_at),
                'createdAt'     => ($p->paid_at ?? $p->created_at)?->toIso8601String() ?? '',
            ]),
        );

        $stats = [
            'total'         => Payment::count(),
            'successful'    => Payment::where('status', 'completed')->count(),
            'totalReceived' => self::money((float) Payment::where('status', 'completed')->sum('amount')),
        ];

        return Inertia::render('Admin/Transactions', [
            'creditBased'  => $creditBased,
            'transactions' => $paginator,
            'stats'        => $stats,
        ]);
    }

    public function invoices(Request $request): Response
    {
        $perPage = $this->perPage($request, [10, 12, 25, 50]);
        $creditBased = (bool) config('stripe-lri.credit_based');
        $siteLimited = (bool) config('stripe-lri.site_limit');

        $paginator = Invoice::query()
            ->with(['user', 'product'])
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Invoice $inv): array => [
                'number'        => (string) $inv->invoice_number,
                'customerName'  => (string) ($inv->customer_name ?? $inv->user?->name ?? '—'),
                'customerEmail' => (string) ($inv->customer_email ?? $inv->user?->email ?? '—'),
                'planName'      => (string) ($inv->product?->plan_name ?? '—'),
                'planPrice'     => self::money((float) $inv->amount, (string) ($inv->currency ?? 'usd')),
                'discount'      => self::money((float) ($inv->discount_amount ?? 0), (string) ($inv->currency ?? 'usd')),
                'amountPaid'    => self::money((float) $inv->total_amount, (string) ($inv->currency ?? 'usd')),
                'promoCode'     => $inv->promo_code ?? null,
                'status'        => ucfirst((string) $inv->status),
                'statusVariant' => self::invoiceVariant((string) $inv->status),
                'period'        => self::fmt($inv->created_at),
                'date'          => self::fmt($inv->paid_at ?? $inv->created_at),
                'credits'       => $creditBased ? self::creditsLabel($inv) : null,
                'site_limit'    => $siteLimited ? self::siteLimitLabel($inv) : null,
                'viewUrl'       => $inv->stripe_invoice_url,
                'pdfUrl'        => $inv->stripe_invoice_pdf,
            ]),
        );

        $stats = [
            'total'     => Invoice::count(),
            'paid'      => Invoice::where('status', 'paid')->count(),
            'totalPaid' => self::money((float) Invoice::where('status', 'paid')->sum('total_amount')),
        ];

        return Inertia::render('Admin/Invoices', [
            'creditBased' => $creditBased,
            'siteLimited' => $siteLimited,
            'invoices'    => $paginator,
            'stats'       => $stats,
        ]);
    }

    public function premiumCustomers(Request $request): Response
    {
        $perPage = $this->perPage($request, [10, 12, 25, 50]);
        $creditBased = (bool) config('stripe-lri.credit_based');

        $q        = (string) $request->query('q', '');
        $plan     = (string) $request->query('plan', 'all');
        $subStatus = (string) $request->query('sub_status', 'all');
        $billing  = (string) $request->query('billing', 'all');
        if (! in_array($billing, ['all', 'monthly', 'yearly', 'lifetime'], true)) {
            $billing = 'all';
        }

        $query = SubscriptionProductUser::query()
            ->with(['user', 'product.prices'])
            ->orderByDesc('created_at');

        if ($q !== '') {
            $term = '%'.mb_strtolower($q).'%';
            $query->whereHas('user', function ($uq) use ($term): void {
                $uq->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$term]);
            });
        }

        if ($plan !== 'all') {
            $query->where('subscription_product_id', $plan);
        }

        if ($subStatus === 'active') {
            $query->where('is_active', true);
        } elseif ($subStatus === 'canceled') {
            $query->where('is_active', false);
        }

        if ($billing !== 'all') {
            $query->whereHas('product', fn ($pq) => $pq->where('billing_cycle', $billing));
        }

        $paginator = $query->paginate($perPage)->withQueryString();

        // Build a lookup of latest paid invoice per (user_id, subscription_product_id) for this page
        $pageRows = $paginator->getCollection();
        $invoiceMap = [];
        if ($pageRows->isNotEmpty()) {
            $pairs = $pageRows->map(fn ($r) => [$r->user_id, $r->subscription_product_id])->all();
            $userIds    = array_unique(array_column($pairs, 0));
            $productIds = array_unique(array_column($pairs, 1));
            Invoice::query()
                ->whereIn('user_id', $userIds)
                ->whereIn('subscription_product_id', $productIds)
                ->where('status', 'paid')
                ->orderByDesc('id')
                ->get(['user_id', 'subscription_product_id', 'discount_amount', 'promo_code', 'currency'])
                ->each(function (Invoice $inv) use (&$invoiceMap): void {
                    $key = $inv->user_id.'_'.$inv->subscription_product_id;
                    $invoiceMap[$key] ??= $inv;
                });
        }

        // Batch-fetch Subscription records for cancel state and cancellation reason
        $stripeSubIds = $pageRows->pluck('stripe_subscription_id')->filter()->unique()->values()->all();
        $subscriptionMap = [];
        if ($stripeSubIds !== []) {
            Subscription::whereIn('stripe_subscription_id', $stripeSubIds)
                ->get(['stripe_subscription_id', 'cancel_at_period_end', 'cancel_at', 'current_period_end', 'canceled_at', 'cancellation_details', 'status'])
                ->each(function (Subscription $sub) use (&$subscriptionMap): void {
                    $subscriptionMap[(string) $sub->stripe_subscription_id] = $sub;
                });
        }

        $paginator->setCollection(
            $pageRows->map(function (SubscriptionProductUser $row) use ($invoiceMap, $subscriptionMap): array {
                $invKey = $row->user_id.'_'.$row->subscription_product_id;
                $inv    = $invoiceMap[$invKey] ?? null;
                $currency = (string) ($inv?->currency ?? 'usd');
                $discount = (float) ($inv?->discount_amount ?? 0);

                $sub             = $subscriptionMap[(string) ($row->stripe_subscription_id ?? '')] ?? null;
                $cancelPeriodEnd = (bool) ($sub?->cancel_at_period_end ?? false);
                $isCanceled      = ! $row->is_active;

                if ($cancelPeriodEnd && ! $isCanceled) {
                    $subStatus        = 'Canceling';
                    $subStatusVariant = 'warning';
                } elseif ($isCanceled) {
                    $subStatus        = 'Canceled';
                    $subStatusVariant = 'neutral';
                } else {
                    $subStatus        = 'Active';
                    $subStatusVariant = 'success';
                }

                $cancelReasonDisplay = self::subscriptionCancelReasonDisplay($sub, $cancelPeriodEnd, $isCanceled);

                return [
                    'id'                       => (int) $row->getKey(),
                    'number'                   => 'SUB-'.str_pad((string) $row->getKey(), 6, '0', STR_PAD_LEFT),
                    'customerName'             => (string) ($row->user?->name ?? '—'),
                    'customerEmail'            => (string) ($row->user?->email ?? '—'),
                    'customerHandle'           => $row->user?->username ?? $row->user?->handle ?? null,
                    'planBilling'              => self::planBilling($row),
                    'product'                  => (string) ($row->product?->plan_name ?? '—'),
                    'reason'                   => 'Subscription',
                    'subtotal'                 => self::productPrice($row),
                    'discount'                 => self::money($discount, $currency),
                    'paid'                     => self::money(max(0.0, (float) ($row->product?->price ?? 0) - $discount), $currency),
                    'promoCode'                => $inv?->promo_code ?? '',
                    'invoiceStatus'            => $row->is_active ? 'Active' : 'Canceled',
                    'invoiceStatusVariant'     => $row->is_active ? 'success' : 'neutral',
                    'period'                   => self::accessPeriod($row),
                    'paidAt'                   => ($row->started_at ?? $row->created_at)?->toIso8601String() ?? '',
                    'subscriptionStatus'       => $subStatus,
                    'subscriptionStatusVariant' => $subStatusVariant,
                    'subscriptionScheduleLine' => self::scheduleLine($row, $sub, $cancelPeriodEnd, $isCanceled),
                    'subscriptionScheduleSub'  => self::scheduleSubLine($row, $sub, $cancelPeriodEnd, $isCanceled),
                    'cancelReasonDetail'       => $cancelReasonDisplay,
                    'cancellationReason'       => $cancelReasonDisplay,
                    'cancelReason'             => $cancelReasonDisplay,
                    'cancel_reason'            => $cancelReasonDisplay,
                    'cancellation_reason'      => $cancelReasonDisplay,
                    'cancellationCustomerComment' => self::cancellationCustomerCommentFromSub($sub),
                    'cancellation_customer_comment' => self::cancellationCustomerCommentFromSub($sub),
                    'canViewCancelReason'      => $cancelReasonDisplay !== null,
                    'userId'                   => $row->user_id,
                    'userRole'                 => $row->user?->getAttribute('role') ?? null,
                    'userIsActive'             => (bool) ($row->user?->getAttribute('is_active') ?? false),
                ];
            }),
        );

        $plans = Package::query()
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->get(['id', 'plan_name']);

        $planOptions = array_merge(
            [['value' => 'all', 'label' => 'All products']],
            $plans->map(fn (Package $p): array => ['value' => (string) $p->getKey(), 'label' => (string) $p->plan_name])->all(),
        );

        // ── Revenue card stats ────────────────────────────────────────────────
        $prevStart = now()->subMonth()->startOfMonth();
        $prevEnd   = now()->subMonth()->endOfMonth();
        $currStart = now()->startOfMonth();

        $prevSub     = (float) Payment::where('status', 'completed')->where('payment_type', 'subscription')->whereBetween('paid_at', [$prevStart, $prevEnd])->sum('amount');
        $prevOneTime = (float) Payment::where('status', 'completed')->where('payment_type', 'single')->whereBetween('paid_at', [$prevStart, $prevEnd])->sum('amount');

        $mtdSub     = (float) Payment::where('status', 'completed')->where('payment_type', 'subscription')->whereBetween('paid_at', [$currStart, now()])->sum('amount');
        $mtdOneTime = (float) Payment::where('status', 'completed')->where('payment_type', 'single')->whereBetween('paid_at', [$currStart, now()])->sum('amount');

        // Expected next month = MRR from monthly plans that will actually renew (exclude cancel_at_period_end)
        $expectedRenewingBase = SubscriptionProductUser::query()
            ->join('subscription_products as sp', 'sp.id', '=', 'subscription_product_user.subscription_product_id')
            ->leftJoin('subscriptions as sub', 'sub.stripe_subscription_id', '=', 'subscription_product_user.stripe_subscription_id')
            ->where('subscription_product_user.is_active', true)
            ->where('sp.billing_cycle', 'monthly')
            ->whereNull('sp.deleted_at')
            ->where(function ($q): void {
                $q->whereNull('sub.id')
                    ->orWhere('sub.cancel_at_period_end', false);
            });

        $expectedTotal  = (float) (clone $expectedRenewingBase)->sum('sp.price');
        $renewingNext   = (int) (clone $expectedRenewingBase)->count();

        $stats = [
            'total'  => SubscriptionProductUser::count(),
            'active' => SubscriptionProductUser::where('is_active', true)->count(),
            'monthlyRenewalsThisMonth'  => (string) SubscriptionProductUser::where('is_active', true)->whereHas('product', fn ($q) => $q->where('billing_cycle', 'monthly'))->count(),
            'yearlyRenewalsThisMonth'   => (string) SubscriptionProductUser::where('is_active', true)->whereHas('product', fn ($q) => $q->where('billing_cycle', 'yearly'))->count(),
            'lifetimeRenewalsThisMonth' => (string) SubscriptionProductUser::where('is_active', true)->whereHas('product', fn ($q) => $q->where('plan_type', 'custom')->orWhere('billing_cycle', null))->count(),
            'prevMonth' => [
                'paidTotal'    => self::money($prevSub + $prevOneTime),
                'subscription' => self::money($prevSub),
                'oneTime'      => self::money($prevOneTime),
            ],
            'mtd' => [
                'paidTotal'    => self::money($mtdSub + $mtdOneTime),
                'subscription' => self::money($mtdSub),
                'oneTime'      => self::money($mtdOneTime),
            ],
            // `activeMonthlyRenewing` matches subscriptions included in paidTotal; use for the expected card’s “Active” chip (not stats.active).
            'expected' => [
                'paidTotal'             => self::money($expectedTotal),
                'renewingNext'          => $renewingNext,
                'activeMonthlyRenewing' => $renewingNext,
            ],
        ];

        return Inertia::render('Admin/PremiumCustomers', [
            'creditBased' => $creditBased,
            'invoices'    => $paginator,
            'filters'     => compact('q', 'plan', 'billing') + ['sub_status' => $subStatus],
            'filterOptions' => [
                'plans'    => $planOptions,
                'statuses' => [
                    ['value' => 'all', 'label' => 'All subscriptions'],
                    ['value' => 'active', 'label' => 'Active'],
                    ['value' => 'canceled', 'label' => 'Canceled'],
                ],
            ],
            'stats' => $stats,
        ]);
    }

    public function premiumRevenueMonth(Request $request): JsonResponse
    {
        $month = (string) $request->query('month', now()->format('Y-m'));
        $creditBased = (bool) config('stripe-lri.credit_based');

        try {
            $start = Carbon::createFromFormat('Y-m', $month)?->startOfMonth();
            $end   = $start?->copy()->endOfMonth();
        } catch (\Throwable) {
            $start = now()->startOfMonth();
            $end   = now()->endOfMonth();
        }

        $cents = (int) round(
            (float) Payment::query()
                ->where('status', 'completed')
                ->whereBetween('paid_at', [$start, $end])
                ->sum('amount') * 100
        );

        return response()->json([
            'creditBased'  => $creditBased,
            'month'        => $month,
            'currency'     => 'usd',
            'amount_cents' => $cents,
            'label'        => $cents === 0 ? 'No revenue for this month.' : null,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function perPage(Request $request, array $allowed): int
    {
        $v = (int) $request->query('per_page', $allowed[0]);

        return in_array($v, $allowed, true) ? $v : $allowed[0];
    }

    private static function money(float $amount, string $currency = 'usd'): string
    {
        $symbol = match (strtolower($currency)) {
            'eur'   => '€',
            'gbp'   => '£',
            default => '$',
        };

        return $symbol.number_format($amount, 2);
    }

    private static function fmt(mixed $dt, string $fallback = '—'): string
    {
        if ($dt === null) {
            return $fallback;
        }
        try {
            return Carbon::parse($dt)->format('M d, Y');
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private static function paymentMethod(mixed $details): string
    {
        if (! is_array($details)) {
            return '—';
        }
        $type = (string) ($details['type'] ?? '');
        if ($type === 'card') {
            $brand = ucfirst((string) ($details['card']['brand'] ?? 'Card'));
            $last4 = (string) ($details['card']['last4'] ?? '');

            return $last4 !== '' ? "{$brand} ···{$last4}" : $brand;
        }

        return $type !== '' ? ucfirst($type) : '—';
    }

    private static function paymentVariant(string $status): string
    {
        return match ($status) {
            'completed'                           => 'success',
            'pending', 'processing'               => 'warning',
            'failed', 'cancelled', 'refunded'     => 'danger',
            default                               => 'neutral',
        };
    }

    private static function invoiceVariant(string $status): string
    {
        return match ($status) {
            'paid'                                => 'success',
            'open', 'draft', 'unpaid'             => 'warning',
            'void', 'uncollectible'               => 'danger',
            default                               => 'neutral',
        };
    }

    private static function creditsLabel(Invoice $inv): string
    {
        // credits_purchased=0 means "not recorded", fall back to product's credits_limit
        $credits = (int) ($inv->getAttribute('credits_purchased') ?: $inv->product?->getAttribute('credits_limit') ?: 0);

        return $credits > 0 ? number_format($credits) : '—';
    }

    private static function siteLimitLabel(Invoice $inv): string
    {
        $limit = (int) ($inv->product?->getAttribute('site_limit') ?? 0);

        return $limit > 0 ? number_format($limit) : '—';
    }

    private static function planBilling(SubscriptionProductUser $row): string
    {
        $cycle = (string) ($row->product?->billing_cycle ?? '');

        return match ($cycle) {
            'monthly' => 'monthly',
            'yearly'  => 'yearly',
            default   => 'lifetime',
        };
    }

    private static function scheduleLine(
        SubscriptionProductUser $row,
        ?Subscription $sub,
        bool $cancelPeriodEnd,
        bool $isCanceled,
    ): string {
        if ($isCanceled) {
            $date = $sub?->canceled_at ?? $row->expires_at;
            return 'Canceled '.self::fmt($date);
        }
        if ($cancelPeriodEnd) {
            $date = $sub?->cancel_at ?? $row->expires_at;
            return 'Cancels '.self::fmt($date);
        }
        // Active — show next renewal date
        $date = $sub?->current_period_end ?? $row->expires_at;
        $cycle = (string) ($row->product?->billing_cycle ?? '');
        $prefix = match ($cycle) {
            'yearly'  => 'Renews yearly ',
            'monthly' => 'Renews ',
            default   => 'Access until ',
        };
        return $prefix.self::fmt($date);
    }

    private static function scheduleSubLine(
        SubscriptionProductUser $row,
        ?Subscription $sub,
        bool $cancelPeriodEnd,
        bool $isCanceled,
    ): ?string {
        if ($cancelPeriodEnd && ! $isCanceled) {
            $detail = self::cancelReasonText($sub);

            return $detail !== null
                ? 'Scheduled to cancel at period end — '.$detail
                : 'Scheduled to cancel at period end';
        }
        if ($isCanceled && $sub !== null) {
            $details = $sub->cancellation_details;
            $feedback = is_array($details) ? (string) ($details['feedback'] ?? '') : '';
            return $feedback !== '' ? ucwords(str_replace('_', ' ', $feedback)) : null;
        }
        return null;
    }

    /**
     * Text for the “Cancel reason” table column: Stripe details when present, otherwise a clear fallback for canceling/canceled rows.
     */
    private static function subscriptionCancelReasonDisplay(?Subscription $sub, bool $cancelPeriodEnd, bool $isCanceled): ?string
    {
        $fromStripe = self::cancelReasonText($sub);
        if ($fromStripe !== null) {
            return $fromStripe;
        }
        if ($sub !== null && $cancelPeriodEnd && ! $isCanceled) {
            return 'Scheduled at period end — Stripe did not include customer feedback.';
        }
        if ($isCanceled) {
            return $sub !== null
                ? 'Canceled — no cancellation details on file.'
                : 'Canceled — subscription not linked to a synced Stripe record.';
        }

        return null;
    }

    private static function cancellationCustomerCommentFromSub(?Subscription $sub): ?string
    {
        if ($sub === null) {
            return null;
        }
        $details = $sub->cancellation_details;
        if (! is_array($details)) {
            return null;
        }
        $comment = trim((string) ($details['comment'] ?? ''));

        return $comment !== '' ? $comment : null;
    }

    private static function cancelReasonText(?Subscription $sub): ?string
    {
        if ($sub === null) {
            return null;
        }
        $details = $sub->cancellation_details;
        if (! is_array($details)) {
            return null;
        }
        $comment  = trim((string) ($details['comment'] ?? ''));
        $feedback = (string) ($details['feedback'] ?? '');
        $reason   = (string) ($details['reason'] ?? '');
        $reasonLabel = self::stripeCancellationReasonLabel($reason);
        $segments      = [];
        if ($comment !== '') {
            $segments[] = 'Customer message: '.$comment;
        }
        if ($feedback !== '') {
            $segments[] = 'Feedback: '.ucwords(str_replace('_', ' ', $feedback));
        }
        if ($reasonLabel !== null) {
            $segments[] = $reasonLabel;
        }
        $text = implode("\n\n", array_filter($segments));

        return $text !== '' ? $text : null;
    }

    private static function stripeCancellationReasonLabel(string $reason): ?string
    {
        if ($reason === '') {
            return null;
        }

        return match ($reason) {
            'cancellation_requested' => 'Customer requested cancellation',
            default => '('.ucwords(str_replace('_', ' ', $reason)).')',
        };
    }

    private static function productPrice(SubscriptionProductUser $row): string
    {
        return self::money((float) ($row->product?->price ?? 0));
    }

    private static function accessPeriod(SubscriptionProductUser $row): string
    {
        $start = self::fmt($row->started_at);
        $end   = $row->expires_at ? self::fmt($row->expires_at) : 'Ongoing';

        return "{$start} – {$end}";
    }
}

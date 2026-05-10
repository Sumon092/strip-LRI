<?php

declare(strict_types=1);

namespace StripeLri\Http\Controllers\Workspace;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Stripe\StripeClient;
use StripeLri\Models\Coupon;
use StripeLri\Models\Invoice;
use StripeLri\Models\Package;
use StripeLri\Models\Payment;
use StripeLri\Models\SubscriptionProductUser;

class WorkspaceBillingController extends Controller
{
    public function validateCoupon(Request $request): \Illuminate\Http\JsonResponse
    {
        $code = trim((string) $request->input('coupon_code', ''));

        if ($code === '') {
            return response()->json(['valid' => false, 'message' => 'Please enter a coupon code.']);
        }

        $coupon = $this->findValidCoupon($code);

        if ($coupon === null) {
            return response()->json(['valid' => false, 'message' => 'This code is invalid or has expired.']);
        }

        return response()->json([
            'valid'    => true,
            'message'  => 'Coupon applied: '.$this->couponDiscountLabel($coupon),
            'discount' => $this->couponDiscountLabel($coupon),
        ]);
    }

    public function checkout(Request $request): RedirectResponse|HttpResponse
    {
        $priceId    = (string) $request->input('price_id', '');
        $planType   = (string) $request->input('plan_type', 'monthly');
        $couponCode = trim((string) $request->input('coupon_code', ''));

        if ($priceId === '') {
            return back()->with('error', 'No price selected.');
        }

        $secret = trim((string) config('stripe-lri.stripe.secret', ''));
        if ($secret === '') {
            return back()->with('error', 'Stripe is not configured. Set STRIPE_SECRET in .env.');
        }

        // Coupon validation is handled entirely by our app — never expose Stripe's
        // native promo-code input (allow_promotion_codes) because our coupon codes
        // live only in the local DB and are not registered as Stripe Promotion Code
        // objects, so Stripe would reject them with 400 if entered on its checkout page.
        $discounts = [];

        if ($couponCode !== '') {
            $coupon = $this->findValidCoupon($couponCode);

            if ($coupon === null) {
                return back()->with('error', 'Coupon code "'.$couponCode.'" is invalid or has expired.');
            }

            if ($coupon->stripe_coupon_id) {
                $stripeId = (string) $coupon->stripe_coupon_id;
                // Stripe distinguishes coupon IDs from promotion code IDs (prefix: promo_)
                $discounts = str_starts_with($stripeId, 'promo_')
                    ? [['promotion_code' => $stripeId]]
                    : [['coupon'         => $stripeId]];
            }
        }

        $mode = $planType === 'lifetime' ? 'payment' : 'subscription';

        $params = [
            'payment_method_types' => ['card'],
            'line_items'           => [['price' => $priceId, 'quantity' => 1]],
            'mode'                 => $mode,
            'success_url'          => route('subscription.index', ['checkout' => 'success']),
            'cancel_url'           => route('pricing-plans.index'),
            'customer_email'       => $request->user()?->email,
            'metadata'             => ['stripe_price_id' => $priceId],
        ];

        if ($discounts !== []) {
            $params['discounts'] = $discounts;
        }
        // allow_promotion_codes is intentionally omitted (defaults to false in Stripe)

        try {
            $session = (new StripeClient($secret))->checkout->sessions->create($params);

            return Inertia::location((string) $session->url);
        } catch (\Throwable $e) {
            logger()->error('stripe-lri.checkout_failed', ['error' => $e->getMessage()]);

            return back()->with('error', 'Checkout could not be started: '.$e->getMessage());
        }
    }

    public function billingHistory(Request $request): Response
    {
        $user     = $request->user();
        $userId   = $user?->getKey();
        $creditBased = (bool) config('stripe-lri.credit_based');

        $paymentsPage  = (int) ($request->query('payments_page', 1));
        $invoicesPage  = (int) ($request->query('invoices_page', 1));
        $perPage       = 8;

        $paymentsQ = Payment::query()
            ->with('product')
            ->where('user_id', $userId)
            ->orderByDesc('created_at');

        $invoicesQ = Invoice::query()
            ->with('product')
            ->where('user_id', $userId)
            ->orderByDesc('created_at');

        $payments = $paymentsQ->paginate($perPage, ['*'], 'payments_page', $paymentsPage)->withQueryString();
        $invoices = $invoicesQ->paginate($perPage, ['*'], 'invoices_page', $invoicesPage)->withQueryString();

        $payments->setCollection(
            $payments->getCollection()->map(fn (Payment $p): array => [
                'id'          => (int) $p->getKey(),
                'reference'   => (string) ($p->stripe_payment_intent_id ?? $p->stripe_charge_id ?? '#'.$p->getKey()),
                'planName'    => (string) ($p->product?->plan_name ?? '—'),
                'amount'      => self::money((float) $p->amount, (string) ($p->currency ?? 'usd')),
                'status'      => ucfirst((string) $p->status),
                'statusVariant' => self::paymentVariant((string) $p->status),
                'method'      => self::paymentMethod($p->payment_method_details),
                'paidAt'      => self::fmt($p->paid_at ?? $p->created_at),
            ]),
        );

        $invoices->setCollection(
            $invoices->getCollection()->map(fn (Invoice $inv): array => [
                'id'               => (int) $inv->getKey(),
                'number'           => (string) $inv->invoice_number,
                'planName'         => (string) ($inv->product?->plan_name ?? '—'),
                'status'           => ucfirst((string) $inv->status),
                'statusVariant'    => self::invoiceVariant((string) $inv->status),
                'subtotal'         => self::money((float) $inv->amount, (string) ($inv->currency ?? 'usd')),
                'amountPaid'       => self::money((float) $inv->total_amount, (string) ($inv->currency ?? 'usd')),
                'discountAmount'   => self::money(max(0.0, (float) $inv->amount - (float) $inv->total_amount), (string) ($inv->currency ?? 'usd')),
                'promotionCode'    => '',
                'billingReason'    => '',
                'period'           => self::fmt($inv->created_at),
                'paidAt'           => self::fmt($inv->paid_at ?? $inv->created_at),
                'hostedInvoiceUrl' => $inv->stripe_invoice_url,
                'invoicePdfUrl'    => $inv->stripe_invoice_pdf,
            ]),
        );

        $paymentsCount = Payment::where('user_id', $userId)->count();
        $invoicesCount = Invoice::where('user_id', $userId)->count();
        $paidTotal     = (float) Payment::where('user_id', $userId)->where('status', 'completed')->sum('amount');
        $latestPaidAt  = Payment::where('user_id', $userId)->where('status', 'completed')->max('paid_at');

        return Inertia::render('Workspace/BillingHistory', [
            'creditBased'    => $creditBased,
            'siteLimited'    => (bool) config('stripe-lri.site_limit'),
            'billingHistory' => [
                'summary' => [
                    'paymentsCount' => $paymentsCount,
                    'invoicesCount' => $invoicesCount,
                    'paidTotal'     => self::money($paidTotal),
                    'latestPaidAt'  => $latestPaidAt ? self::fmt(Carbon::parse($latestPaidAt)) : '—',
                ],
                'payments' => $payments,
                'invoices' => $invoices,
            ],
        ]);
    }

    public function pricingPlans(Request $request): Response
    {
        $user        = $request->user();
        $userId      = $user?->getKey();
        $creditBased = (bool) config('stripe-lri.credit_based');
        $siteLimited = (bool) config('stripe-lri.site_limit');

        // Load active packages with their prices
        $packages = Package::query()
            ->with(['items', 'prices' => fn ($q) => $q->whereNotNull('stripe_price_id')])
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->get();

        $plans = ['monthly' => [], 'yearly' => [], 'lifetime' => []];

        foreach ($packages as $pkg) {
            foreach ($pkg->prices as $price) {
                $planType = $price->plan_type;
                if (! array_key_exists($planType, $plans)) {
                    continue;
                }

                $features = $pkg->items->map(fn ($item): array => [
                    'name'        => (string) $item->name,
                    'description' => null,
                ])->values()->all();

                $isRecurring = in_array($planType, ['monthly', 'yearly'], true);

                $plans[$planType][] = [
                    'product_id'          => (int) $pkg->getKey(),
                    'product_name'        => (string) $pkg->plan_name,
                    'product_description' => $pkg->description,
                    'credit_limit'        => $creditBased ? (int) ($pkg->credits_limit ?? $pkg->getAttribute('credits_limit') ?? 0) : null,
                    'site_limit'          => $siteLimited ? (int) ($pkg->site_limit ?? $pkg->getAttribute('site_limit') ?? 0) : null,
                    'is_popular'          => (bool) $pkg->is_popular,
                    'is_featured'         => (bool) $pkg->is_featured,
                    'stripe_price_id'     => (string) $price->stripe_price_id,
                    'plan_type'           => $planType,
                    'nickname'            => $price->nickname ?: null,
                    'currency'            => (string) ($price->currency ?? 'usd'),
                    'amount'              => (int) round((float) $price->amount * 100),
                    'type'                => $isRecurring ? 'subscription' : 'one_time',
                    'interval'            => $planType === 'monthly' ? 'month' : ($planType === 'yearly' ? 'year' : null),
                    'interval_count'      => $isRecurring ? 1 : null,
                    'features'            => $features,
                ];
            }
        }

        // Current subscriptions for the auth user
        $currentSubscriptions = [];
        if ($userId !== null) {
            $activeSubs = SubscriptionProductUser::query()
                ->with('product.prices')
                ->where('user_id', $userId)
                ->where('is_active', true)
                ->get();

            foreach ($activeSubs as $sub) {
                foreach ($sub->product?->prices ?? [] as $price) {
                    $planType = $price->plan_type;
                    $isRecurring = in_array($planType, ['monthly', 'yearly'], true);
                    $currentSubscriptions[] = [
                        'id'                     => (int) $sub->getKey(),
                        'type'                   => $isRecurring ? 'subscription' : 'one_time',
                        'status'                 => 'active',
                        'plan_type'              => $planType,
                        'stripe_price_id'        => $price->stripe_price_id,
                        'cancel_at_period_end'   => false,
                        'current_period_end_at'  => $sub->expires_at?->toIso8601String(),
                        'current_period_end_at_human' => $sub->expires_at ? self::fmt($sub->expires_at) : null,
                    ];
                }
            }
        }

        // Yearly discount: take max yearly_discount_percent from any price metadata
        $yearlyDiscountPercent = 0;
        foreach ($packages as $pkg) {
            foreach ($pkg->prices as $price) {
                if ($price->plan_type === 'yearly') {
                    $meta     = is_array($price->metadata) ? $price->metadata : [];
                    $discount = (int) ($meta['yearly_discount_percent'] ?? 0);
                    if ($discount > $yearlyDiscountPercent) {
                        $yearlyDiscountPercent = $discount;
                    }
                }
            }
        }

        return Inertia::render('Workspace/PricingPlans', [
            'creditBased'          => $creditBased,
            'siteLimited'          => $siteLimited,
            'plans'                => $plans,
            'currentSubscriptions' => $currentSubscriptions,
            'yearlyDiscountPercent' => $yearlyDiscountPercent,
        ]);
    }

    public function subscription(Request $request): Response
    {
        $user        = $request->user();
        $userId      = $user?->getKey();
        $creditBased = (bool) config('stripe-lri.credit_based');
        $siteLimited = (bool) config('stripe-lri.site_limit');
        $hasCreditsBalance = $creditBased && Schema::hasColumn('subscription_product_user', 'credits_balance');
        $hasSiteCount = $siteLimited && Schema::hasColumn('subscription_product_user', 'site_count');

        $perPage = 8;

        $active = SubscriptionProductUser::query()
            ->with('product.prices')
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->get()
            ->map(fn (SubscriptionProductUser $row): array => $this->toActiveSubscription($row, $hasCreditsBalance, $creditBased, $hasSiteCount, $siteLimited))
            ->values()
            ->all();

        $history = SubscriptionProductUser::query()
            ->with('product.prices')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();

        $history->setCollection(
            $history->getCollection()->map(
                fn (SubscriptionProductUser $row): array => $this->toHistoryRow($row, $hasCreditsBalance, $creditBased, $hasSiteCount, $siteLimited),
            ),
        );

        $cancelScheduled = collect($active)->contains('cancelScheduled', true);
        $accessUntil     = collect($active)
            ->where('cancelScheduled', true)
            ->first()['accessUntil'] ?? '';

        return Inertia::render('Workspace/Subscription', [
            'creditBased'        => $creditBased,
            'siteLimited'        => $siteLimited,
            'subscriptionCenter' => [
                'active'       => $active,
                'cancelNotice' => [
                    'show'        => $cancelScheduled,
                    'accessUntil' => $accessUntil,
                ],
                'history'      => $history,
            ],
        ]);
    }

    // ── Row builders ──────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function toActiveSubscription(SubscriptionProductUser $row, bool $hasCreditsBalance, bool $creditBased, bool $hasSiteCount, bool $siteLimited): array
    {
        $product  = $row->product;
        $price    = $product?->prices->first();
        $planType = $price?->plan_type ?? ($product?->billing_cycle ?? 'monthly');

        return [
            'id'             => (int) $row->getKey(),
            'planName'       => (string) ($product?->plan_name ?? '—'),
            'price'          => self::money((float) ($price?->amount ?? $product?->price ?? 0)),
            'paidAmount'     => self::money((float) ($price?->amount ?? $product?->price ?? 0)),
            'credits'        => ($creditBased && $hasCreditsBalance) ? number_format((int) ($row->credits_balance ?? 0)) : '—',
            'siteCount'      => ($siteLimited && $hasSiteCount) ? (int) ($row->site_count ?? 0) : null,
            'siteLimit'      => ($siteLimited && $hasSiteCount) ? (int) ($product?->getAttribute('site_limit') ?? 0) : null,
            'period'         => self::accessPeriodLabel($row),
            'status'         => 'Active',
            'statusVariant'  => 'success',
            'accessUntil'    => self::fmt($row->expires_at, 'Ongoing'),
            'canCancel'      => in_array($planType, ['monthly', 'yearly'], true),
            'cancelScheduled' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function toHistoryRow(SubscriptionProductUser $row, bool $hasCreditsBalance, bool $creditBased, bool $hasSiteCount, bool $siteLimited): array
    {
        $product  = $row->product;
        $price    = $product?->prices->first();
        $planType = $price?->plan_type ?? ($product?->billing_cycle ?? 'monthly');

        return [
            'id'           => (int) $row->getKey(),
            'planName'     => (string) ($product?->plan_name ?? '—'),
            'billingCycle' => ucfirst($planType),
            'type'         => in_array($planType, ['monthly', 'yearly'], true) ? 'Subscription' : 'One-time',
            'price'        => self::money((float) ($price?->amount ?? $product?->price ?? 0)),
            'paidAmount'   => self::money((float) ($price?->amount ?? $product?->price ?? 0)),
            'credits'      => ($creditBased && $hasCreditsBalance) ? number_format((int) ($row->credits_balance ?? 0)) : '—',
            'siteCount'    => ($siteLimited && $hasSiteCount) ? (int) ($row->site_count ?? 0) : null,
            'siteLimit'    => ($siteLimited && $hasSiteCount) ? (int) ($product?->getAttribute('site_limit') ?? 0) : null,
            'status'       => $row->is_active ? 'Active' : 'Expired',
            'statusVariant' => $row->is_active ? 'success' : 'neutral',
            'accessUntil'  => self::fmt($row->expires_at, 'Ongoing'),
            'startDate'    => self::fmt($row->started_at),
            'endDate'      => self::fmt($row->expires_at, '—'),
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

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

    private static function paymentVariant(string $status): string
    {
        return match ($status) {
            'completed'                         => 'success',
            'pending', 'processing'             => 'warning',
            'failed', 'cancelled', 'refunded'   => 'danger',
            default                             => 'neutral',
        };
    }

    private static function invoiceVariant(string $status): string
    {
        return match ($status) {
            'paid'                            => 'success',
            'open', 'draft', 'unpaid'         => 'warning',
            'void', 'uncollectible'           => 'danger',
            default                           => 'neutral',
        };
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

    private static function accessPeriodLabel(SubscriptionProductUser $row): string
    {
        $start = self::fmt($row->started_at);
        $end   = $row->expires_at ? self::fmt($row->expires_at) : 'Ongoing';

        return "{$start} – {$end}";
    }

    private function findValidCoupon(string $code): ?Coupon
    {
        $coupon = Coupon::where('code', $code)
            ->where('active', true)
            ->whereNull('deleted_at')
            ->first();

        if ($coupon === null) {
            return null;
        }

        // Check expiry date
        if ($coupon->redeem_by !== null && $coupon->redeem_by->isPast()) {
            return null;
        }

        // Check redemption limit exhaustion
        if ($coupon->max_redemptions !== null && $coupon->times_redeemed >= $coupon->max_redemptions) {
            return null;
        }

        return $coupon;
    }

    private function couponDiscountLabel(Coupon $coupon): string
    {
        if ($coupon->percent_off !== null) {
            return rtrim(rtrim((string) $coupon->percent_off, '0'), '.').'% off';
        }
        if ($coupon->amount_off !== null) {
            $symbol = match (strtolower((string) ($coupon->currency ?? 'usd'))) {
                'eur'   => '€',
                'gbp'   => '£',
                default => '$',
            };

            return $symbol.number_format($coupon->amount_off / 100, 2).' off';
        }

        return 'Discount applied';
    }
}

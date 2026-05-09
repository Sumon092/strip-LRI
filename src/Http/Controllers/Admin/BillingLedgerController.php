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
                'discount'      => self::money(max(0.0, (float) $inv->amount - (float) $inv->total_amount), (string) ($inv->currency ?? 'usd')),
                'amountPaid'    => self::money((float) $inv->total_amount, (string) ($inv->currency ?? 'usd')),
                'promoCode'     => null,
                'status'        => ucfirst((string) $inv->status),
                'statusVariant' => self::invoiceVariant((string) $inv->status),
                'period'        => self::fmt($inv->created_at),
                'date'          => self::fmt($inv->paid_at ?? $inv->created_at),
                'credits'       => $creditBased ? self::creditsLabel($inv) : null,
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

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (SubscriptionProductUser $row): array => [
                'customerName'             => (string) ($row->user?->name ?? '—'),
                'customerEmail'            => (string) ($row->user?->email ?? '—'),
                'customerHandle'           => $row->user?->username ?? $row->user?->handle ?? null,
                'product'                  => (string) ($row->product?->plan_name ?? '—'),
                'subscriptionStatus'       => $row->is_active ? 'Active' : 'Canceled',
                'subscriptionStatusVariant' => $row->is_active ? 'success' : 'neutral',
                'subscriptionScheduleLine' => self::scheduleLine($row),
                'subscriptionScheduleSub'  => null,
                'subtotal'                 => self::productPrice($row),
                'discount'                 => '$0.00',
                'paid'                     => self::productPrice($row),
                'promoCode'                => '',
                'period'                   => self::accessPeriod($row),
                'cancellationReason'       => null,
                'userId'                   => $row->user_id,
            ]),
        );

        $plans = Package::query()
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->get(['id', 'plan_name']);

        $planOptions = array_merge(
            [['value' => 'all', 'label' => 'All products']],
            $plans->map(fn (Package $p): array => ['value' => (string) $p->getKey(), 'label' => (string) $p->plan_name])->all(),
        );

        $stats = [
            'total'                    => SubscriptionProductUser::count(),
            'active'                   => SubscriptionProductUser::where('is_active', true)->count(),
            'paidTotal'                => '$0.00',
            'monthlyRenewalsThisMonth' => (string) SubscriptionProductUser::where('is_active', true)->whereHas('product', fn ($q) => $q->where('billing_cycle', 'monthly'))->count(),
            'yearlyRenewalsThisMonth'  => (string) SubscriptionProductUser::where('is_active', true)->whereHas('product', fn ($q) => $q->where('billing_cycle', 'yearly'))->count(),
            'lifetimeRenewalsThisMonth' => (string) SubscriptionProductUser::where('is_active', true)->whereHas('product', fn ($q) => $q->where('plan_type', 'custom')->orWhere('billing_cycle', null))->count(),
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
        $credits = (int) ($inv->getAttribute('credits_purchased') ?? 0);

        return $credits > 0 ? number_format($credits) : '—';
    }

    private static function scheduleLine(SubscriptionProductUser $row): string
    {
        $cycle = (string) ($row->product?->billing_cycle ?? '');
        $price = (float) ($row->product?->price ?? 0);
        $currency = 'usd';

        $label = match ($cycle) {
            'monthly' => 'Monthly, '.self::money($price, $currency).'/mo',
            'yearly'  => 'Yearly, '.self::money($price, $currency).'/yr',
            default   => 'Lifetime',
        };

        return $label;
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

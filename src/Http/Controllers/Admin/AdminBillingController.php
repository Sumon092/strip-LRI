<?php

namespace StripeLri\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use StripeLri\Http\Controllers\Controller;
use StripeLri\Support\DemoCatalog;

class AdminBillingController extends Controller
{
    public function transactions(Request $request): Response
    {
        $rows = DemoCatalog::transactions();
        $paginated = DemoCatalog::paginate($rows, $request, 'admin.transactions.index', 10, [10, 12, 25, 50]);
        $successful = collect($rows)->where('statusVariant', 'success')->count();

        return Inertia::render('Admin/Transactions', [
            'creditBased' => (bool) config('stripe-lri.credit_based'),
            'transactions' => $paginated,
            'stats' => [
                'total' => count($rows),
                'successful' => $successful,
                'totalReceived' => '$12,450.00',
            ],
        ]);
    }

    public function invoices(Request $request): Response
    {
        $rows = DemoCatalog::invoices();
        $paginated = DemoCatalog::paginate($rows, $request, 'admin.invoices.index', 10, [10, 12, 25, 50]);
        $paid = collect($rows)->where('statusVariant', 'success')->count();

        return Inertia::render('Admin/Invoices', [
            'creditBased' => (bool) config('stripe-lri.credit_based'),
            'invoices' => $paginated,
            'stats' => [
                'total' => count($rows),
                'paid' => $paid,
                'totalPaid' => '$8,910.00',
            ],
        ]);
    }

    public function premiumCustomers(Request $request): Response
    {
        $q = (string) $request->query('q', '');
        $plan = (string) $request->query('plan', 'all');
        $subStatus = (string) $request->query('sub_status', 'all');
        $billingRaw = (string) $request->query('billing', 'all');
        $billing = in_array($billingRaw, ['all', 'monthly', 'yearly', 'lifetime'], true) ? $billingRaw : 'all';

        $rows = collect(DemoCatalog::premiumInvoices())->filter(function (array $row) use ($q, $plan, $subStatus, $billing): bool {
            if ($billing !== 'all' && ($row['planBilling'] ?? '') !== $billing) {
                return false;
            }
            if ($plan !== 'all' && ($row['product'] ?? '') !== $plan) {
                return false;
            }
            if ($subStatus === 'active' && ($row['subscriptionStatus'] ?? '') !== 'Active') {
                return false;
            }
            if ($subStatus === 'canceled' && ($row['subscriptionStatus'] ?? '') === 'Active') {
                return false;
            }
            if ($q === '') {
                return true;
            }
            $needle = mb_strtolower($q);
            $hay = mb_strtolower(
                ($row['customerName'] ?? '').' '.($row['customerEmail'] ?? '').' '.($row['customerHandle'] ?? '')
            );

            return str_contains($hay, $needle);
        })->values()->all();

        $invoices = DemoCatalog::paginate($rows, $request, 'admin.premium-customers.index', 10, [10, 12, 25, 50]);

        $allProducts = collect(DemoCatalog::premiumInvoices())->pluck('product')->unique()->values()->all();

        return Inertia::render('Admin/PremiumCustomers', [
            'creditBased' => (bool) config('stripe-lri.credit_based'),
            'invoices' => $invoices,
            'filters' => [
                'q' => $q,
                'plan' => $plan,
                'sub_status' => $subStatus,
                'billing' => $billing,
            ],
            'filterOptions' => [
                'plans' => array_merge(
                    [['value' => 'all', 'label' => 'All products']],
                    array_map(fn (string $p): array => ['value' => $p, 'label' => $p], $allProducts)
                ),
                'statuses' => [
                    ['value' => 'all', 'label' => 'All subscriptions'],
                    ['value' => 'active', 'label' => 'Active'],
                    ['value' => 'canceled', 'label' => 'Canceled'],
                ],
            ],
        ]);
    }

    public function premiumRevenueMonth(Request $request): JsonResponse
    {
        $month = (string) $request->query('month', now()->format('Y-m'));

        return response()->json([
            'creditBased' => (bool) config('stripe-lri.credit_based'),
            'month' => $month,
            'currency' => 'usd',
            'amount_cents' => 128400,
            'label' => 'Demo aggregate (wire Stripe in your app).',
        ]);
    }
}

<?php

namespace StripeLri\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use StripeLri\Http\Controllers\Concerns\EmptyPagination;

class BillingLedgerController extends Controller
{
    use EmptyPagination;

    public function transactions(Request $request): Response
    {
        $paginated = $this->emptyPaginator($request, 'admin.transactions.index', 10, [10, 12, 25, 50]);

        return Inertia::render('Admin/Transactions', [
            'creditBased' => (bool) config('stripe-lri.credit_based'),
            'transactions' => $paginated,
            'stats' => [
                'total' => 0,
                'successful' => 0,
                'totalReceived' => '$0.00',
            ],
        ]);
    }

    public function invoices(Request $request): Response
    {
        $paginated = $this->emptyPaginator($request, 'admin.invoices.index', 10, [10, 12, 25, 50]);

        return Inertia::render('Admin/Invoices', [
            'creditBased' => (bool) config('stripe-lri.credit_based'),
            'invoices' => $paginated,
            'stats' => [
                'total' => 0,
                'paid' => 0,
                'totalPaid' => '$0.00',
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

        $invoices = $this->emptyPaginator($request, 'admin.premium-customers.index', 10, [10, 12, 25, 50]);

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
                'plans' => [
                    ['value' => 'all', 'label' => 'All products'],
                ],
                'statuses' => [
                    ['value' => 'all', 'label' => 'All subscriptions'],
                    ['value' => 'active', 'label' => 'Active'],
                    ['value' => 'canceled', 'label' => 'Canceled'],
                ],
            ],
            'stats' => [
                'total' => 0,
                'active' => 0,
                'paidTotal' => '$0.00',
                'monthlyRenewalsThisMonth' => '0',
                'yearlyRenewalsThisMonth' => '0',
                'lifetimeRenewalsThisMonth' => '0',
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
            'amount_cents' => 0,
            'label' => 'No revenue data until billing is connected.',
        ]);
    }
}

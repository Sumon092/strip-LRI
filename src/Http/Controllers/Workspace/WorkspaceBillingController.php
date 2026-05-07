<?php

namespace StripeLri\Http\Controllers\Workspace;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use StripeLri\Http\Controllers\Concerns\EmptyPagination;

class WorkspaceBillingController extends Controller
{
    use EmptyPagination;

    public function billingHistory(Request $request): Response
    {
        $paymentsPaginated = $this->emptyPaginator(
            $request,
            'billing-history.index',
            8,
            [8, 12, 25, 50],
            'payments_page',
        );

        $invoicesPaginated = $this->emptyPaginator(
            $request,
            'billing-history.index',
            8,
            [8, 12, 25, 50],
            'invoices_page',
        );

        return Inertia::render('Workspace/BillingHistory', [
            'creditBased' => (bool) config('stripe-lri.credit_based'),
            'billingHistory' => [
                'summary' => [
                    'paymentsCount' => 0,
                    'invoicesCount' => 0,
                    'paidTotal' => '$0.00',
                    'latestPaidAt' => '—',
                ],
                'payments' => $paymentsPaginated,
                'invoices' => $invoicesPaginated,
            ],
        ]);
    }

    public function pricingPlans(): Response
    {
        return Inertia::render('Workspace/PricingPlans', [
            'creditBased' => (bool) config('stripe-lri.credit_based'),
            'plans' => [
                'monthly' => [],
                'yearly' => [],
                'lifetime' => [],
            ],
            'currentSubscriptions' => [],
            'yearlyDiscountPercent' => 0,
        ]);
    }

    public function subscription(Request $request): Response
    {
        $history = $this->emptyPaginator($request, 'subscription.index', 8, [8, 12, 25, 50]);

        return Inertia::render('Workspace/Subscription', [
            'creditBased' => (bool) config('stripe-lri.credit_based'),
            'subscriptionCenter' => [
                'active' => [],
                'cancelNotice' => [
                    'show' => false,
                    'accessUntil' => '',
                ],
                'history' => $history,
            ],
        ]);
    }
}

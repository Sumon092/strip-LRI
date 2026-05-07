<?php

namespace StripeLri\Http\Controllers\Workspace;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use StripeLri\Http\Controllers\Controller;
use StripeLri\Support\DemoCatalog;

class WorkspaceBillingController extends Controller
{
    public function billingHistory(Request $request): Response
    {
        $payments = DemoCatalog::billingPayments();
        $invoices = DemoCatalog::billingCustomerInvoices();

        $paymentsPaginated = DemoCatalog::paginate(
            $payments,
            $request,
            'billing-history.index',
            8,
            [8, 12, 25, 50],
            'payments_page',
        );

        $invoicesPaginated = DemoCatalog::paginate(
            $invoices,
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
                    'paymentsCount' => count($payments),
                    'invoicesCount' => count($invoices),
                    'paidTotal' => '$4,182.00',
                    'latestPaidAt' => now()->subDay()->toFormattedDateString(),
                ],
                'payments' => $paymentsPaginated,
                'invoices' => $invoicesPaginated,
            ],
        ]);
    }

    public function pricingPlans(): Response
    {
        return Inertia::render('Workspace/PricingPlans', array_merge(
            DemoCatalog::workspacePricingPlans(),
            ['creditBased' => (bool) config('stripe-lri.credit_based')],
        ));
    }

    public function subscription(Request $request): Response
    {
        $center = DemoCatalog::subscriptionCenter();
        $history = DemoCatalog::paginate(
            DemoCatalog::subscriptionHistory(),
            $request,
            'subscription.index',
            8,
            [8, 12, 25, 50],
        );

        return Inertia::render('Workspace/Subscription', [
            'creditBased' => (bool) config('stripe-lri.credit_based'),
            'subscriptionCenter' => array_merge($center, [
                'history' => $history,
            ]),
        ]);
    }
}

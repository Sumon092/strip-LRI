<?php

namespace StripeLri\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use StripeLri\Http\Controllers\Controller;
use StripeLri\Support\DemoCatalog;

class AdminCouponsController extends Controller
{
    public function index(Request $request): Response
    {
        $coupons = DemoCatalog::paginate(
            DemoCatalog::coupons(),
            $request,
            'admin.coupons.index',
            10,
            [10, 12, 25, 50]
        );

        return Inertia::render('Admin/Coupons/Index', [
            'creditBased' => (bool) config('stripe-lri.credit_based'),
            'coupons' => $coupons,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Coupons/Create', [
            'creditBased' => (bool) config('stripe-lri.credit_based'),
            'currencies' => DemoCatalog::currencies(),
            'initialForm' => DemoCatalog::emptyCouponForm(),
        ]);
    }

    public function edit(int $coupon): Response
    {
        $row = DemoCatalog::findCoupon($coupon);
        if ($row === null) {
            abort(404);
        }

        return Inertia::render('Admin/Coupons/Edit', [
            'creditBased' => (bool) config('stripe-lri.credit_based'),
            'couponId' => $coupon,
            'currencies' => DemoCatalog::currencies(),
            'initialForm' => DemoCatalog::couponFormFromRow($row),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return redirect()
            ->route('admin.coupons.index')
            ->with('success', 'Stripe-LRI demo: coupon was not created.');
    }

    public function update(Request $request, int $coupon): RedirectResponse
    {
        if (DemoCatalog::findCoupon($coupon) === null) {
            abort(404);
        }

        return redirect()
            ->route('admin.coupons.index')
            ->with('success', 'Stripe-LRI demo: coupon was not saved.');
    }

    public function destroy(int $coupon): RedirectResponse
    {
        if (DemoCatalog::findCoupon($coupon) === null) {
            abort(404);
        }

        return redirect()
            ->route('admin.coupons.index')
            ->with('success', 'Stripe-LRI demo: delete is a no-op until you connect Stripe + database.');
    }
}

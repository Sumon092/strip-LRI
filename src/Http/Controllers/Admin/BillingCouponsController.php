<?php

namespace StripeLri\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use StripeLri\Http\Controllers\Concerns\EmptyPagination;

class BillingCouponsController extends Controller
{
    use EmptyPagination;

    public function index(Request $request): Response
    {
        $coupons = $this->emptyPaginator($request, 'admin.coupons.index', 10, [10, 12, 25, 50]);

        return Inertia::render('Admin/Coupons/Index', [
            'creditBased' => (bool) config('stripe-lri.credit_based'),
            'coupons' => $coupons,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Coupons/Create', [
            'creditBased' => (bool) config('stripe-lri.credit_based'),
            'currencies' => ['usd', 'eur', 'gbp'],
            'initialForm' => $this->emptyCouponForm(),
        ]);
    }

    public function edit(int $coupon): never
    {
        abort(404);
    }

    public function store(Request $request): RedirectResponse
    {
        return redirect()
            ->route('admin.coupons.index')
            ->with('info', 'Coupon persistence is not configured. Create coupons in Stripe or add a database layer.');
    }

    public function update(Request $request, int $coupon): never
    {
        abort(404);
    }

    public function destroy(int $coupon): never
    {
        abort(404);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyCouponForm(): array
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
}

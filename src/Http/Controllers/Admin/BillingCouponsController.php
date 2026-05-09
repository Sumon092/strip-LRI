<?php

declare(strict_types=1);

namespace StripeLri\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Stripe\StripeClient;
use StripeLri\Http\Requests\StoreCouponRequest;
use StripeLri\Http\Requests\UpdateCouponRequest;
use StripeLri\Models\Coupon;

class BillingCouponsController extends Controller
{
    public function index(): Response
    {
        $coupons = Coupon::query()
            ->orderByDesc('created_at')
            ->paginate(10)
            ->withQueryString();

        $coupons->setCollection(
            $coupons->getCollection()->map(fn (Coupon $c): array => self::toRow($c)),
        );

        return Inertia::render('Admin/Coupons/Index', [
            'creditBased' => (bool) config('stripe-lri.credit_based'),
            'coupons'     => $coupons,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Coupons/Create', [
            'creditBased' => (bool) config('stripe-lri.credit_based'),
            'currencies'  => ['usd', 'eur', 'gbp'],
            'initialForm' => self::emptyForm(),
        ]);
    }

    public function edit(int $coupon): Response
    {
        $model = Coupon::query()->withTrashed()->findOrFail($coupon);

        return Inertia::render('Admin/Coupons/Edit', [
            'creditBased' => (bool) config('stripe-lri.credit_based'),
            'couponId'    => (int) $model->getKey(),
            'currencies'  => ['usd', 'eur', 'gbp'],
            'initialForm' => self::toForm($model),
        ]);
    }

    public function store(StoreCouponRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $attrs = self::buildAttributes($validated);

        // Sync to Stripe first so we get stripe_coupon_id
        $stripeId = $this->pushToStripe($attrs);
        if ($stripeId !== null) {
            $attrs['stripe_coupon_id'] = $stripeId;
        }

        Coupon::query()->create($attrs);

        return redirect()
            ->route('admin.coupons.index')
            ->with('success', 'Coupon created.');
    }

    public function update(UpdateCouponRequest $request, int $coupon): RedirectResponse
    {
        $model     = Coupon::query()->findOrFail($coupon);
        $validated = $request->validated();

        $model->name   = (string) $validated['name'];
        $model->active = (bool) ($validated['is_active'] ?? true);

        $redeemBy = $validated['valid_until'] ?? null;
        $model->redeem_by = $redeemBy ? \Carbon\Carbon::parse($redeemBy) : null;

        if (! is_array($model->metadata)) {
            $model->metadata = [];
        }
        $model->metadata = array_merge($model->metadata, [
            'description' => (string) ($validated['description'] ?? ''),
            'valid_from'  => $validated['valid_from'] ?? null,
        ]);

        $model->save();

        // Update Stripe (only name is editable on existing Stripe coupons)
        $this->updateInStripe($model);

        return redirect()
            ->route('admin.coupons.index')
            ->with('success', 'Coupon updated.');
    }

    public function destroy(int $coupon): RedirectResponse
    {
        $model = Coupon::query()->findOrFail($coupon);

        $this->deleteFromStripe($model);

        $model->delete();

        return redirect()
            ->route('admin.coupons.index')
            ->with('success', 'Coupon deleted.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $validated */
    private static function buildAttributes(array $validated): array
    {
        $type     = (string) ($validated['coupon_type'] ?? 'percent');
        $value    = (float) ($validated['value'] ?? 0);
        $currency = strtolower((string) ($validated['currency'] ?? 'usd'));
        $metadata = [
            'description' => (string) ($validated['description'] ?? ''),
            'valid_from'  => $validated['valid_from'] ?? null,
        ];

        $attrs = [
            'code'             => strtoupper((string) $validated['code']),
            'name'             => (string) $validated['name'],
            'duration'         => 'once',
            'active'           => (bool) ($validated['is_active'] ?? true),
            'times_redeemed'   => 0,
            'metadata'         => $metadata,
        ];

        if ($type === 'percent') {
            $attrs['percent_off'] = $value;
        } else {
            $attrs['amount_off'] = (int) round($value * 100);
            $attrs['currency']   = $currency;
        }

        $max = $validated['max_redemptions'] ?? null;
        if ($max !== null && $max !== '') {
            $attrs['max_redemptions'] = (int) $max;
        }

        $until = $validated['valid_until'] ?? null;
        if ($until !== null && $until !== '') {
            $attrs['redeem_by'] = \Carbon\Carbon::parse($until);
        }

        return $attrs;
    }

    /** @param array<string, mixed> $attrs */
    private function pushToStripe(array $attrs): ?string
    {
        $secret = trim((string) config('stripe-lri.stripe.secret', ''));
        if ($secret === '') {
            return null;
        }

        try {
            $client  = new StripeClient($secret);
            $payload = [
                'id'       => $attrs['code'],
                'name'     => $attrs['name'],
                'duration' => $attrs['duration'],
            ];

            if (isset($attrs['percent_off'])) {
                $payload['percent_off'] = $attrs['percent_off'];
            } else {
                $payload['amount_off'] = $attrs['amount_off'];
                $payload['currency']   = $attrs['currency'] ?? 'usd';
            }

            if (isset($attrs['max_redemptions'])) {
                $payload['max_redemptions'] = $attrs['max_redemptions'];
            }

            if (isset($attrs['redeem_by'])) {
                $payload['redeem_by'] = $attrs['redeem_by'] instanceof \DateTimeInterface
                    ? $attrs['redeem_by']->getTimestamp()
                    : (int) \Carbon\Carbon::parse($attrs['redeem_by'])->timestamp;
            }

            $stripeCoupon = $client->coupons->create($payload);

            return $stripeCoupon->id;
        } catch (\Throwable $e) {
            logger()->error('stripe-lri.coupon_create_failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function updateInStripe(Coupon $coupon): void
    {
        $secret = trim((string) config('stripe-lri.stripe.secret', ''));
        if ($secret === '' || empty($coupon->stripe_coupon_id)) {
            return;
        }

        try {
            (new StripeClient($secret))->coupons->update(
                $coupon->stripe_coupon_id,
                ['name' => $coupon->name],
            );
        } catch (\Throwable $e) {
            logger()->error('stripe-lri.coupon_update_failed', ['error' => $e->getMessage()]);
        }
    }

    private function deleteFromStripe(Coupon $coupon): void
    {
        $secret = trim((string) config('stripe-lri.stripe.secret', ''));
        if ($secret === '' || empty($coupon->stripe_coupon_id)) {
            return;
        }

        try {
            (new StripeClient($secret))->coupons->delete($coupon->stripe_coupon_id);
        } catch (\Throwable $e) {
            logger()->error('stripe-lri.coupon_delete_failed', ['error' => $e->getMessage()]);
        }
    }

    /** @return array<string, mixed> */
    private static function toRow(Coupon $c): array
    {
        return [
            'id'              => (int) $c->getKey(),
            'code'            => (string) $c->code,
            'name'            => (string) $c->name,
            'coupon_type'     => $c->coupon_type,
            'percent_off'     => $c->percent_off,
            'amount_off'      => $c->amount_off,
            'currency'        => $c->currency,
            'max_redemptions' => $c->max_redemptions,
            'is_active'       => (bool) $c->active,
            'times_redeemed'  => (int) ($c->times_redeemed ?? 0),
            'valid_until'     => $c->redeem_by?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private static function toForm(Coupon $c): array
    {
        $meta = is_array($c->metadata) ? $c->metadata : [];

        return [
            'name'                    => (string) $c->name,
            'code'                    => (string) $c->code,
            'description'             => (string) ($meta['description'] ?? ''),
            'coupon_type'             => $c->coupon_type,
            'value'                   => $c->percent_off !== null
                ? (string) $c->percent_off
                : (string) round(($c->amount_off ?? 0) / 100, 2),
            'currency'                => (string) ($c->currency ?? 'usd'),
            'max_redemptions'         => $c->max_redemptions !== null ? (string) $c->max_redemptions : '',
            'is_active'               => (bool) $c->active,
            'custom_validation_rules' => false,
            'valid_from'              => (string) ($meta['valid_from'] ?? ''),
            'valid_until'             => $c->redeem_by ? $c->redeem_by->format('Y-m-d') : '',
        ];
    }

    /** @return array<string, mixed> */
    private static function emptyForm(): array
    {
        return [
            'name'                    => '',
            'code'                    => '',
            'description'             => '',
            'coupon_type'             => '',
            'value'                   => '',
            'currency'                => 'usd',
            'max_redemptions'         => '',
            'is_active'               => true,
            'custom_validation_rules' => false,
            'valid_from'              => '',
            'valid_until'             => '',
        ];
    }
}

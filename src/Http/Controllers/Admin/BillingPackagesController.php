<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use App\Http\Requests\Billing\StorePackageRequest;
use App\Http\Requests\Billing\UpdatePackageRequest;
use App\Models\Billing\Package;
use App\Services\Billing\StripeProductPushService;
use App\Support\Billing\PackagePresenter;
use App\Support\Billing\StripeWebhookCatalogGate;

class BillingPackagesController extends Controller
{
    public function index(Request $request): Response
    {
        $perPage = (int) $request->query('per_page', 10);
        if (! in_array($perPage, [10, 12, 25, 50], true)) {
            $perPage = 10;
        }

        $query = Package::query()
            ->with(['items', 'prices'])
            ->orderBy('sort_order')
            ->orderByDesc('id');

        if (config('stripe-lri.premium_features')) {
            $query->with('premiumFeatures');
        }

        $paginator = $query->paginate($perPage)->withQueryString();

        $paginator->setCollection(
            $paginator->getCollection()->map(
                static fn (Package $p): array => PackagePresenter::toIndexRow($p),
            ),
        );

        $canCreatePackages = StripeWebhookCatalogGate::allowsPackageWrites();

        return Inertia::render('Admin/Packages/Index', [
            'creditBased' => (bool) config('stripe-lri.credit_based'),
            'siteLimited' => (bool) config('stripe-lri.site_limit'),
            'premiumFeaturesEnabled' => (bool) config('stripe-lri.premium_features'),
            'canCreatePackages' => $canCreatePackages,
            'packagesCreateBlockReason' => $canCreatePackages ? null : StripeWebhookCatalogGate::denyMessage(),
            'products' => $paginator,
        ]);
    }

    public function create(): Response|RedirectResponse
    {
        if (! StripeWebhookCatalogGate::allowsPackageWrites()) {
            return redirect()
                ->route('admin.packages.index')
                ->with('error', StripeWebhookCatalogGate::denyMessage());
        }

        return Inertia::render('Admin/Packages/Create', [
            'creditBased' => (bool) config('stripe-lri.credit_based'),
            'siteLimited' => (bool) config('stripe-lri.site_limit'),
            'premiumFeaturesEnabled' => (bool) config('stripe-lri.premium_features'),
            'form' => PackagePresenter::emptyForm(),
        ]);
    }

    public function edit(int $package): Response
    {
        $with = ['items', 'prices'];
        if (config('stripe-lri.premium_features')) {
            $with[] = 'premiumFeatures';
        }

        $model = Package::query()
            ->with($with)
            ->whereKey($package)
            ->firstOrFail();

        return Inertia::render('Admin/Packages/Edit', [
            'creditBased' => (bool) config('stripe-lri.credit_based'),
            'siteLimited' => (bool) config('stripe-lri.site_limit'),
            'premiumFeaturesEnabled' => (bool) config('stripe-lri.premium_features'),
            'productId' => (int) $model->getKey(),
            'form' => PackagePresenter::toForm($model),
        ]);
    }

    public function store(StorePackageRequest $request): RedirectResponse
    {
        if (! StripeWebhookCatalogGate::allowsPackageWrites()) {
            return redirect()
                ->route('admin.packages.index')
                ->with('error', StripeWebhookCatalogGate::denyMessage());
        }

        $validated = $request->validated();
        $package = Package::query()->create(PackagePresenter::validatedToAttributes($validated));
        PackagePresenter::syncChildTables($package, $validated);

        if ($package->plan_type === 'stripe_plan' && trim((string) config('stripe-lri.stripe.secret', '')) !== '') {
            try {
                (new StripeProductPushService)->pushNewPackage($package);
            } catch (\Throwable $e) {
                logger()->error('stripe-lri.push_product_failed', ['error' => $e->getMessage(), 'package_id' => $package->getKey()]);
            }
        }

        return redirect()
            ->route('admin.packages.index')
            ->with('success', 'Package created.');
    }

    public function update(UpdatePackageRequest $request, int $package): RedirectResponse
    {
        $model = Package::query()->with('prices')->whereKey($package)->firstOrFail();
        $validated = $request->validated();

        $wasStripePlan = $model->plan_type === 'stripe_plan';
        $previousStripeProductId = $model->stripe_product_id !== null && trim((string) $model->stripe_product_id) !== ''
            ? (string) $model->stripe_product_id
            : null;

        /** @var list<array{stripe_price_id:?string,amount:float|int|string,currency:?string,plan_type:?string}> $previousPrices */
        $previousPrices = $model->prices
            ->map(static fn ($p): array => [
                'stripe_price_id' => $p->stripe_price_id !== null ? (string) $p->stripe_price_id : null,
                'amount' => $p->amount,
                'currency' => $p->currency !== null ? (string) $p->currency : 'usd',
                'plan_type' => (string) $p->plan_type,
            ])
            ->values()
            ->all();

        $attrs = PackagePresenter::validatedToAttributes($validated);

        // Form may omit stripe_product_id — never wipe an existing Stripe product link.
        if (
            ($attrs['stripe_product_id'] ?? null) === null
            && $previousStripeProductId !== null
        ) {
            $attrs['stripe_product_id'] = $previousStripeProductId;
        }

        $model->fill($attrs);
        $model->save();
        PackagePresenter::syncChildTables($model, $validated);

        $secretOk = trim((string) config('stripe-lri.stripe.secret', '')) !== '';
        $isStripePlan = $model->plan_type === 'stripe_plan';

        if ($secretOk && ($isStripePlan || $wasStripePlan)) {
            try {
                $fresh = $model->fresh(['prices']);
                if ($fresh === null) {
                    throw new \RuntimeException('Package missing after save.');
                }

                $push = new StripeProductPushService;

                if ($isStripePlan) {
                    $push->pushUpdatedPackage($fresh, $previousPrices);
                } elseif ($wasStripePlan && $previousStripeProductId !== null) {
                    // Switched away from stripe_plan — archive the old Stripe catalog objects.
                    $fresh->stripe_product_id = $previousStripeProductId;
                    $push->archivePackage($fresh);
                }
            } catch (\Throwable $e) {
                logger()->error('stripe-lri.push_product_failed', ['error' => $e->getMessage(), 'package_id' => $model->getKey()]);

                return redirect()
                    ->route('admin.packages.index')
                    ->with('error', 'Package saved locally, but Stripe sync failed: '.$e->getMessage());
            }

            return redirect()
                ->route('admin.packages.index')
                ->with('success', 'Package updated. Stripe product updated; changed/removed prices archived.');
        }

        return redirect()
            ->route('admin.packages.index')
            ->with('success', 'Package updated.');
    }

    public function destroy(int $package): RedirectResponse
    {
        $model = Package::query()->with('prices')->whereKey($package)->firstOrFail();
        $archivedOnStripe = false;

        if ($model->plan_type === 'stripe_plan' && trim((string) config('stripe-lri.stripe.secret', '')) !== '') {
            try {
                (new StripeProductPushService)->archivePackage($model);
                $archivedOnStripe = true;
            } catch (\Throwable $e) {
                logger()->error('stripe-lri.archive_product_failed', [
                    'error' => $e->getMessage(),
                    'package_id' => $model->getKey(),
                ]);
            }
        }

        $model->delete();

        return redirect()
            ->route('admin.packages.index')
            ->with('success', $archivedOnStripe
                ? 'Package deleted and archived on Stripe.'
                : 'Package deleted.');
    }
}

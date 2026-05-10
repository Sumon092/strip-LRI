<?php

namespace StripeLri\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use StripeLri\Http\Requests\StorePackageRequest;
use StripeLri\Http\Requests\UpdatePackageRequest;
use StripeLri\Models\Package;
use StripeLri\Services\StripeProductPushService;
use StripeLri\Support\PackagePresenter;
use StripeLri\Support\StripeWebhookCatalogGate;

class BillingPackagesController extends Controller
{
    public function index(Request $request): Response
    {
        $perPage = (int) $request->query('per_page', 10);
        if (! in_array($perPage, [10, 12, 25, 50], true)) {
            $perPage = 10;
        }

        $paginator = Package::query()
            ->with(['items', 'prices'])
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $paginator->setCollection(
            $paginator->getCollection()->map(
                static fn (Package $p): array => PackagePresenter::toIndexRow($p),
            ),
        );

        $canCreatePackages = StripeWebhookCatalogGate::allowsPackageWrites();

        return Inertia::render('Admin/Packages/Index', [
            'creditBased' => (bool) config('stripe-lri.credit_based'),
            'siteLimited' => (bool) config('stripe-lri.site_limit'),
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
            'form' => PackagePresenter::emptyForm(),
        ]);
    }

    public function edit(int $package): Response
    {
        $model = Package::query()
            ->with(['items', 'prices'])
            ->whereKey($package)
            ->firstOrFail();

        return Inertia::render('Admin/Packages/Edit', [
            'creditBased' => (bool) config('stripe-lri.credit_based'),
            'siteLimited' => (bool) config('stripe-lri.site_limit'),
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
        $model = Package::query()->whereKey($package)->firstOrFail();
        $validated = $request->validated();
        $model->fill(PackagePresenter::validatedToAttributes($validated));
        $model->save();
        PackagePresenter::syncChildTables($model, $validated);

        if ($model->plan_type === 'stripe_plan' && trim((string) config('stripe-lri.stripe.secret', '')) !== '') {
            try {
                (new StripeProductPushService)->pushUpdatedPackage($model);
            } catch (\Throwable $e) {
                logger()->error('stripe-lri.push_product_failed', ['error' => $e->getMessage(), 'package_id' => $model->getKey()]);
            }
        }

        return redirect()
            ->route('admin.packages.index')
            ->with('success', 'Package updated.');
    }

    public function destroy(int $package): RedirectResponse
    {
        Package::query()->whereKey($package)->firstOrFail()->delete();

        return redirect()
            ->route('admin.packages.index')
            ->with('success', 'Package deleted.');
    }
}

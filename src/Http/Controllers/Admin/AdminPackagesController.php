<?php

namespace StripeLri\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use StripeLri\Http\Controllers\Controller;
use StripeLri\Http\Requests\StorePackageRequest;
use StripeLri\Http\Requests\UpdatePackageRequest;
use StripeLri\Models\Package;
use StripeLri\Support\PackagePresenter;

class AdminPackagesController extends Controller
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

        return Inertia::render('Admin/Packages/Index', [
            'creditBased' => (bool) config('stripe-lri.credit_based'),
            'products' => $paginator,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Packages/Create', [
            'creditBased' => (bool) config('stripe-lri.credit_based'),
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
            'productId' => (int) $model->getKey(),
            'form' => PackagePresenter::toForm($model),
        ]);
    }

    public function store(StorePackageRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $package = Package::query()->create(PackagePresenter::validatedToAttributes($validated));
        PackagePresenter::syncChildTables($package, $validated);

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

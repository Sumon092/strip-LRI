<?php

namespace StripeLri\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use StripeLri\Http\Controllers\Controller;
use StripeLri\Support\DemoCatalog;

class AdminPackagesController extends Controller
{
    public function index(Request $request): Response
    {
        $products = DemoCatalog::paginate(
            DemoCatalog::products(),
            $request,
            'admin.packages.index',
            10,
            [10, 12, 25, 50]
        );

        return Inertia::render('Admin/Packages/Index', [
            'creditBased' => (bool) config('stripe-lri.credit_based'),
            'products' => $products,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Packages/Create', [
            'creditBased' => (bool) config('stripe-lri.credit_based'),
            'form' => DemoCatalog::emptyPackageForm(),
        ]);
    }

    public function edit(int $package): Response
    {
        $product = DemoCatalog::findProduct($package);
        if ($product === null) {
            abort(404);
        }

        return Inertia::render('Admin/Packages/Edit', [
            'creditBased' => (bool) config('stripe-lri.credit_based'),
            'productId' => $package,
            'form' => DemoCatalog::packageFormFromProduct($product),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return redirect()
            ->route('admin.packages.index')
            ->with('success', 'Stripe-LRI demo: package was not persisted. Implement persistence in your app or extend the package.');
    }

    public function update(Request $request, int $package): RedirectResponse
    {
        if (DemoCatalog::findProduct($package) === null) {
            abort(404);
        }

        return redirect()
            ->route('admin.packages.index')
            ->with('success', 'Stripe-LRI demo: package was not saved.');
    }

    public function destroy(int $package): RedirectResponse
    {
        if (DemoCatalog::findProduct($package) === null) {
            abort(404);
        }

        return redirect()
            ->route('admin.packages.index')
            ->with('success', 'Stripe-LRI demo: delete is a no-op until you connect Stripe + database.');
    }
}

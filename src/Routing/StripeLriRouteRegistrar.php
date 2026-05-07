<?php

namespace StripeLri\Routing;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

/**
 * Registers Stripe-LRI HTTP routes for a given controller root namespace
 * (package: {@see \StripeLri\Http\Controllers} or published: {@see \App\StripeLri\Http\Controllers}).
 */
final class StripeLriRouteRegistrar
{
    public static function register(string $controllersNamespace): void
    {
        $c = rtrim($controllersNamespace, '\\');

        if (config('stripe-lri.register_webhook', true)) {
            self::registerWebhookRoutes($c);
        }

        if (config('stripe-lri.register_routes', false)) {
            self::registerBillingRoutes($c);
        }
    }

    private static function registerWebhookRoutes(string $c): void
    {
        Route::middleware('web')->group(function () use ($c): void {
            Route::get('/stripe/webhook', ["{$c}\\StripeWebhookInfoController", '__invoke'])
                ->name('stripe.webhook.info');
            Route::post('/stripe/webhook', ["{$c}\\StripeWebhookController", 'handle'])
                ->name('stripe.webhook')
                ->withoutMiddleware([VerifyCsrfToken::class]);
        });
    }

    private static function registerBillingRoutes(string $c): void
    {
        $workspaceMw = config('stripe-lri.middleware.workspace', ['web', 'auth', 'verified']);
        $adminMw = config('stripe-lri.middleware.admin', ['web', 'auth', 'verified', 'admin']);

        Route::middleware($workspaceMw)->group(function () use ($c): void {
            Route::get('/billing-history', ["{$c}\\Workspace\\WorkspaceBillingController", 'billingHistory'])
                ->name('billing-history.index');
            Route::get('/dashboard/pricing-plans', ["{$c}\\Workspace\\WorkspaceBillingController", 'pricingPlans'])
                ->name('pricing-plans.index');
            Route::get('/subscription', ["{$c}\\Workspace\\WorkspaceBillingController", 'subscription'])
                ->name('subscription.index');
        });

        Route::middleware($adminMw)->prefix('admin')->name('admin.')->group(function () use ($c): void {
            Route::get('/users', ["{$c}\\Admin\\AdminUsersController", 'index'])->name('users.index');
            Route::get('/users/{user}', ["{$c}\\Admin\\AdminUsersController", 'show'])
                ->whereNumber('user')->name('users.show');
            Route::get('/users/{user}/edit', ["{$c}\\Admin\\AdminUsersController", 'edit'])
                ->whereNumber('user')->name('users.edit');
            Route::patch('/users/{user}', ["{$c}\\Admin\\AdminUsersController", 'update'])
                ->whereNumber('user')->name('users.update');
            Route::post('/users/{user}/credits', ["{$c}\\Admin\\AdminUsersController", 'adjustCredits'])
                ->whereNumber('user')->name('users.credits.adjust');
            Route::post('/users/{user}/impersonate', ["{$c}\\Admin\\AdminUsersController", 'impersonate'])
                ->whereNumber('user')->name('users.impersonate');
            Route::delete('/users/{user}', ["{$c}\\Admin\\AdminUsersController", 'destroy'])
                ->whereNumber('user')->name('users.destroy');

            Route::get('/packages', ["{$c}\\Admin\\AdminPackagesController", 'index'])->name('packages.index');
            Route::get('/packages/create', ["{$c}\\Admin\\AdminPackagesController", 'create'])->name('packages.create');
            Route::post('/packages', ["{$c}\\Admin\\AdminPackagesController", 'store'])->name('packages.store');
            Route::get('/packages/{package}/edit', ["{$c}\\Admin\\AdminPackagesController", 'edit'])
                ->whereNumber('package')->name('packages.edit');
            Route::put('/packages/{package}', ["{$c}\\Admin\\AdminPackagesController", 'update'])
                ->whereNumber('package')->name('packages.update');
            Route::delete('/packages/{package}', ["{$c}\\Admin\\AdminPackagesController", 'destroy'])
                ->whereNumber('package')->name('packages.destroy');

            Route::get('/coupons', ["{$c}\\Admin\\AdminCouponsController", 'index'])->name('coupons.index');
            Route::get('/coupons/create', ["{$c}\\Admin\\AdminCouponsController", 'create'])->name('coupons.create');
            Route::post('/coupons', ["{$c}\\Admin\\AdminCouponsController", 'store'])->name('coupons.store');
            Route::get('/coupons/{coupon}/edit', ["{$c}\\Admin\\AdminCouponsController", 'edit'])
                ->whereNumber('coupon')->name('coupons.edit');
            Route::put('/coupons/{coupon}', ["{$c}\\Admin\\AdminCouponsController", 'update'])
                ->whereNumber('coupon')->name('coupons.update');
            Route::delete('/coupons/{coupon}', ["{$c}\\Admin\\AdminCouponsController", 'destroy'])
                ->whereNumber('coupon')->name('coupons.destroy');

            Route::get('/transactions', ["{$c}\\Admin\\AdminBillingController", 'transactions'])->name('transactions.index');
            Route::get('/invoices', ["{$c}\\Admin\\AdminBillingController", 'invoices'])->name('invoices.index');
            Route::get('/premium-customers', ["{$c}\\Admin\\AdminBillingController", 'premiumCustomers'])->name('premium-customers.index');
            Route::get('/premium-customers/revenue-month', ["{$c}\\Admin\\AdminBillingController", 'premiumRevenueMonth'])
                ->name('premium-customers.revenue-month');
        });
    }
}

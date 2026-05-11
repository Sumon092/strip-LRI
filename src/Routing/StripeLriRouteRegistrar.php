<?php

namespace StripeLri\Routing;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

/**
 * Registers Stripe-LRI HTTP routes for a given controller root namespace
 * (package: {@see \StripeLri\Http\Controllers} or published: {@see \App\Http\Controllers}).
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
            Route::get('/stripe/webhook', ["{$c}\\Webhooks\\StripeWebhookInfoController", '__invoke'])
                ->name('stripe.webhook.info');
            Route::post('/stripe/webhook', ["{$c}\\Webhooks\\StripeWebhookController", 'handle'])
                ->name('stripe.webhook')
                ->withoutMiddleware([PreventRequestForgery::class]);
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
            Route::post('/checkout', ["{$c}\\Workspace\\WorkspaceBillingController", 'checkout'])
                ->name('checkout.create');
            Route::post('/coupon/validate', ["{$c}\\Workspace\\WorkspaceBillingController", 'validateCoupon'])
                ->name('coupon.validate');
            Route::post('/subscription/{subscriptionProductUser}/cancel', ["{$c}\\Workspace\\WorkspaceBillingController", 'cancel'])
                ->whereNumber('subscriptionProductUser')
                ->name('subscription.cancel');
            Route::post('/subscription/{subscriptionProductUser}/resume', ["{$c}\\Workspace\\WorkspaceBillingController", 'resume'])
                ->whereNumber('subscriptionProductUser')
                ->name('subscription.resume');
        });

        Route::middleware($adminMw)->prefix('admin')->name('admin.')->group(function () use ($c): void {
            Route::get('/users', ["{$c}\\Admin\\BillingUsersController", 'index'])->name('users.index');
            Route::get('/users/{user}', ["{$c}\\Admin\\BillingUsersController", 'show'])
                ->whereNumber('user')->name('users.show');
            Route::get('/users/{user}/edit', ["{$c}\\Admin\\BillingUsersController", 'edit'])
                ->whereNumber('user')->name('users.edit');
            Route::patch('/users/{user}', ["{$c}\\Admin\\BillingUsersController", 'update'])
                ->whereNumber('user')->name('users.update');
            Route::post('/users/{user}/credits', ["{$c}\\Admin\\BillingUsersController", 'adjustCredits'])
                ->whereNumber('user')->name('users.credits.adjust');
            Route::post('/users/{user}/impersonate', ["{$c}\\Admin\\BillingUsersController", 'impersonate'])
                ->whereNumber('user')->name('users.impersonate');
            Route::delete('/users/{user}', ["{$c}\\Admin\\BillingUsersController", 'destroy'])
                ->whereNumber('user')->name('users.destroy');

            Route::get('/packages', ["{$c}\\Admin\\BillingPackagesController", 'index'])->name('packages.index');
            Route::get('/packages/create', ["{$c}\\Admin\\BillingPackagesController", 'create'])->name('packages.create');
            Route::post('/packages', ["{$c}\\Admin\\BillingPackagesController", 'store'])->name('packages.store');
            Route::get('/packages/{package}/edit', ["{$c}\\Admin\\BillingPackagesController", 'edit'])
                ->whereNumber('package')->name('packages.edit');
            Route::put('/packages/{package}', ["{$c}\\Admin\\BillingPackagesController", 'update'])
                ->whereNumber('package')->name('packages.update');
            Route::delete('/packages/{package}', ["{$c}\\Admin\\BillingPackagesController", 'destroy'])
                ->whereNumber('package')->name('packages.destroy');

            Route::get('/coupons', ["{$c}\\Admin\\BillingCouponsController", 'index'])->name('coupons.index');
            Route::get('/coupons/create', ["{$c}\\Admin\\BillingCouponsController", 'create'])->name('coupons.create');
            Route::post('/coupons', ["{$c}\\Admin\\BillingCouponsController", 'store'])->name('coupons.store');
            Route::get('/coupons/{coupon}/edit', ["{$c}\\Admin\\BillingCouponsController", 'edit'])
                ->whereNumber('coupon')->name('coupons.edit');
            Route::put('/coupons/{coupon}', ["{$c}\\Admin\\BillingCouponsController", 'update'])
                ->whereNumber('coupon')->name('coupons.update');
            Route::delete('/coupons/{coupon}', ["{$c}\\Admin\\BillingCouponsController", 'destroy'])
                ->whereNumber('coupon')->name('coupons.destroy');

            Route::get('/transactions', ["{$c}\\Admin\\BillingLedgerController", 'transactions'])->name('transactions.index');
            Route::get('/invoices', ["{$c}\\Admin\\BillingLedgerController", 'invoices'])->name('invoices.index');
            Route::get('/premium-customers', ["{$c}\\Admin\\BillingLedgerController", 'premiumCustomers'])->name('premium-customers.index');
            Route::get('/premium-customers/revenue-month', ["{$c}\\Admin\\BillingLedgerController", 'premiumRevenueMonth'])
                ->name('premium-customers.revenue-month');
        });
    }
}

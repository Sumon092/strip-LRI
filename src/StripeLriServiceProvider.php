<?php

namespace StripeLri;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use StripeLri\Console\AddMonthlyCreditsForYearlyCommand;
use StripeLri\Console\InstallStripeLriCommand;
use StripeLri\Console\ProcessCreditsHistoryCommand;
use StripeLri\Contracts\CreditLedger;
use StripeLri\Services\NullCreditLedger;

class StripeLriServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/stripe-lri.php', 'stripe-lri');

        $this->app->singletonIf(CreditLedger::class, fn (): CreditLedger => new NullCreditLedger);

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations/core');

        if ((bool) $this->app->make('config')->get('stripe-lri.credit_based')) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations/credits');
        }
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/stripe-lri.php' => config_path('stripe-lri.php'),
        ], 'stripe-lri-config');

        if ($this->app->runningInConsole()) {
            $this->commands([InstallStripeLriCommand::class]);

            if (config('stripe-lri.credit_based')) {
                $this->commands([
                    AddMonthlyCreditsForYearlyCommand::class,
                    ProcessCreditsHistoryCommand::class,
                ]);
            }
        }

        $this->registerScheduledTasks();

        if (config('stripe-lri.register_webhook', true)) {
            $this->registerWebhookRoutes();
        }

        if (config('stripe-lri.register_routes', false)) {
            $this->registerBillingRoutes();
        }
    }

    /**
     * GET (browser help) + POST (Stripe) — no host routes/web.php changes required.
     */
    protected function registerWebhookRoutes(): void
    {
        Route::middleware('web')->group(function (): void {
            Route::get('/stripe/webhook', Http\Controllers\StripeWebhookInfoController::class)
                ->name('stripe.webhook.info');
            Route::post('/stripe/webhook', [Http\Controllers\StripeWebhookController::class, 'handle'])
                ->name('stripe.webhook')
                ->withoutMiddleware([VerifyCsrfToken::class]);
        });
    }

    protected function registerScheduledTasks(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (! config('stripe-lri.credit_based')) {
                return;
            }

            if (! config('stripe-lri.schedule.enabled', false)) {
                return;
            }

            if (config('stripe-lri.schedule.process_history_hourly', true)) {
                $schedule->command('stripe-lri:credits:process-history')
                    ->hourly()
                    ->withoutOverlapping()
                    ->runInBackground();
            }

            if (config('stripe-lri.schedule.monthly_credits_daily', true)) {
                $schedule->command('stripe-lri:credits:add-monthly-for-yearly')
                    ->daily()
                    ->withoutOverlapping()
                    ->runInBackground();
            }
        });
    }

    protected function registerBillingRoutes(): void
    {
        $workspaceMw = config('stripe-lri.middleware.workspace', ['web', 'auth', 'verified']);
        $adminMw = config('stripe-lri.middleware.admin', ['web', 'auth', 'verified', 'admin']);

        Route::middleware($workspaceMw)->group(function (): void {
            Route::get('/billing-history', [Http\Controllers\Workspace\WorkspaceBillingController::class, 'billingHistory'])
                ->name('billing-history.index');
            Route::get('/dashboard/pricing-plans', [Http\Controllers\Workspace\WorkspaceBillingController::class, 'pricingPlans'])
                ->name('pricing-plans.index');
            Route::get('/subscription', [Http\Controllers\Workspace\WorkspaceBillingController::class, 'subscription'])
                ->name('subscription.index');
        });

        Route::middleware($adminMw)->prefix('admin')->name('admin.')->group(function (): void {
            Route::get('/users', [Http\Controllers\Admin\AdminUsersController::class, 'index'])->name('users.index');
            Route::get('/users/{user}', [Http\Controllers\Admin\AdminUsersController::class, 'show'])
                ->whereNumber('user')->name('users.show');
            Route::get('/users/{user}/edit', [Http\Controllers\Admin\AdminUsersController::class, 'edit'])
                ->whereNumber('user')->name('users.edit');
            Route::patch('/users/{user}', [Http\Controllers\Admin\AdminUsersController::class, 'update'])
                ->whereNumber('user')->name('users.update');
            Route::post('/users/{user}/credits', [Http\Controllers\Admin\AdminUsersController::class, 'adjustCredits'])
                ->whereNumber('user')->name('users.credits.adjust');
            Route::post('/users/{user}/impersonate', [Http\Controllers\Admin\AdminUsersController::class, 'impersonate'])
                ->whereNumber('user')->name('users.impersonate');
            Route::delete('/users/{user}', [Http\Controllers\Admin\AdminUsersController::class, 'destroy'])
                ->whereNumber('user')->name('users.destroy');

            Route::get('/packages', [Http\Controllers\Admin\AdminPackagesController::class, 'index'])->name('packages.index');
            Route::get('/packages/create', [Http\Controllers\Admin\AdminPackagesController::class, 'create'])->name('packages.create');
            Route::post('/packages', [Http\Controllers\Admin\AdminPackagesController::class, 'store'])->name('packages.store');
            Route::get('/packages/{package}/edit', [Http\Controllers\Admin\AdminPackagesController::class, 'edit'])
                ->whereNumber('package')->name('packages.edit');
            Route::put('/packages/{package}', [Http\Controllers\Admin\AdminPackagesController::class, 'update'])
                ->whereNumber('package')->name('packages.update');
            Route::delete('/packages/{package}', [Http\Controllers\Admin\AdminPackagesController::class, 'destroy'])
                ->whereNumber('package')->name('packages.destroy');

            Route::get('/coupons', [Http\Controllers\Admin\AdminCouponsController::class, 'index'])->name('coupons.index');
            Route::get('/coupons/create', [Http\Controllers\Admin\AdminCouponsController::class, 'create'])->name('coupons.create');
            Route::post('/coupons', [Http\Controllers\Admin\AdminCouponsController::class, 'store'])->name('coupons.store');
            Route::get('/coupons/{coupon}/edit', [Http\Controllers\Admin\AdminCouponsController::class, 'edit'])
                ->whereNumber('coupon')->name('coupons.edit');
            Route::put('/coupons/{coupon}', [Http\Controllers\Admin\AdminCouponsController::class, 'update'])
                ->whereNumber('coupon')->name('coupons.update');
            Route::delete('/coupons/{coupon}', [Http\Controllers\Admin\AdminCouponsController::class, 'destroy'])
                ->whereNumber('coupon')->name('coupons.destroy');

            Route::get('/transactions', [Http\Controllers\Admin\AdminBillingController::class, 'transactions'])->name('transactions.index');
            Route::get('/invoices', [Http\Controllers\Admin\AdminBillingController::class, 'invoices'])->name('invoices.index');
            Route::get('/premium-customers', [Http\Controllers\Admin\AdminBillingController::class, 'premiumCustomers'])->name('premium-customers.index');
            Route::get('/premium-customers/revenue-month', [Http\Controllers\Admin\AdminBillingController::class, 'premiumRevenueMonth'])
                ->name('premium-customers.revenue-month');
        });
    }
}

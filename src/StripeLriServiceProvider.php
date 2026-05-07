<?php

namespace StripeLri;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use StripeLri\Console\AddMonthlyCreditsForYearlyCommand;
use StripeLri\Console\InstallStripeLriCommand;
use StripeLri\Console\ProcessCreditsHistoryCommand;
use StripeLri\Contracts\CreditLedger;
use StripeLri\Routing\StripeLriRouteRegistrar;
use StripeLri\Services\NullCreditLedger;

class StripeLriServiceProvider extends ServiceProvider
{
    /**
     * When {@see \App\Providers\StripeLriServiceProvider} exists (after install), this package
     * does not register config, routes, migrations, or bindings — the app is standalone and
     * you may remove {@code stripe-lri/laravel} from Composer.
     */
    private function hostBillingIsStandalone(): bool
    {
        return is_file(app_path('Providers/StripeLriServiceProvider.php'));
    }

    public function register(): void
    {
        if ($this->hostBillingIsStandalone()) {
            return;
        }

        $this->mergeConfigFrom(__DIR__.'/../config/stripe-lri.php', 'stripe-lri');

        $this->app->singletonIf(CreditLedger::class, fn (): CreditLedger => new NullCreditLedger);

        if (! (bool) $this->app->make('config')->get('stripe-lri.published_to_app', false)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations/core');

            if ((bool) $this->app->make('config')->get('stripe-lri.credit_based')) {
                $this->loadMigrationsFrom(__DIR__.'/../database/migrations/credits');
            }
        }
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/stripe-lri.php' => config_path('stripe-lri.php'),
        ], 'stripe-lri-config');

        if ($this->app->runningInConsole()) {
            $this->commands([InstallStripeLriCommand::class]);
        }

        if ($this->hostBillingIsStandalone()) {
            return;
        }

        if (config('stripe-lri.credit_based')) {
            $this->commands([
                AddMonthlyCreditsForYearlyCommand::class,
                ProcessCreditsHistoryCommand::class,
            ]);
        }

        $this->registerScheduledTasks();

        $published = (bool) config('stripe-lri.published_to_app', false);
        $publishedRoutes = base_path('routes/stripe-lri.php');

        if ($published && file_exists($publishedRoutes)) {
            $this->loadRoutesFrom($publishedRoutes);
        } else {
            StripeLriRouteRegistrar::register('StripeLri\Http\Controllers');
        }
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
}

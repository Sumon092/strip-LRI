<?php

namespace StripeLri\Support;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

/**
 * Copies Stripe-LRI sources into conventional Laravel app paths so the host app
 * runs without resolving controllers/models from vendor.
 *
 * @see \App\Providers\StripeLriServiceProvider Generated on install; when present the
 *      package {@see \StripeLri\StripeLriServiceProvider} becomes a no-op so you may
 *      remove {@code stripe-lri/laravel} from Composer after install.
 */
final class ApplicationCodePublisher
{
    private const MARKER_RELATIVE = 'Http/Controllers/Webhooks/StripeWebhookInfoController.php';

    public function __construct(
        private readonly Filesystem $files = new Filesystem,
    ) {}

    /**
     * @return list<string> Human-readable summary lines
     */
    public function publishAll(
        bool $force,
        bool $includeCreditMigrations,
        ?OutputInterface $output = null,
    ): array {
        $lines = [];
        $packageRoot = dirname(__DIR__, 2);
        $appBase = base_path('app');
        $marker = $appBase.'/'.self::MARKER_RELATIVE;

        $fullCodePublish = ! $this->files->exists($marker) || $force;

        $this->ensureConfigModelKeys($packageRoot, $output);

        if ($fullCodePublish) {
            $this->removeLegacyAppStripeLriDirectory();
            $this->removeLegacyPublishedBillingControllerTree();
            $this->removeObsoletePublishedSupportFiles();
            $this->publishMappedPhpTree($packageRoot.'/src', $appBase, $output);
            $this->publishContractAndServices($packageRoot);
            $this->publishConsoleCommands();
            $this->files->put(base_path('routes/stripe-lri.php'), $this->standaloneRoutesFileContents());
            $this->files->put(app_path('Providers/StripeLriServiceProvider.php'), $this->hostStripeLriServiceProviderContents());
            $this->ensureHostProviderRegistered($output);
            $patched = $this->patchWebRoutes($output);
            $lines[] = 'Published Stripe-LRI code under app/Http (Admin, Workspace, Webhooks, Concerns), app/Models/Billing, app/Support/Billing, app/Contracts, app/Services, app/Console.';
            $lines[] = 'Published app/Providers/StripeLriServiceProvider.php and routes/stripe-lri.php (no vendor route classes).';
            if ($patched) {
                $lines[] = 'Patched routes/web.php: removed stub billing route registrations that conflict with stripe-lri.php.';
            }
        } else {
            $lines[] = 'Skipped copying billing PHP (marker present). Use --force to overwrite all published app files.';
        }

        $migDest = database_path('migrations');
        $this->files->ensureDirectoryExists($migDest);
        $this->copyMigrationDirectory($packageRoot.'/database/migrations/core', $migDest, $force, $output);
        $lines[] = 'Published core migrations to database/migrations.';

        if ($includeCreditMigrations) {
            $this->copyMigrationDirectory($packageRoot.'/database/migrations/credits', $migDest, $force, $output);
            $lines[] = 'Published credit migrations to database/migrations.';
        }

        return $lines;
    }

    private function removeLegacyAppStripeLriDirectory(): void
    {
        $legacy = base_path('app/StripeLri');
        if ($this->files->isDirectory($legacy)) {
            $this->files->deleteDirectory($legacy);
        }
    }

    /** Removes pre-refactor tree {@code app/Http/Controllers/Billing/} (Admin, Workspace, webhooks). */
    private function removeLegacyPublishedBillingControllerTree(): void
    {
        $legacy = base_path('app/Http/Controllers/Billing');
        if ($this->files->isDirectory($legacy)) {
            $this->files->deleteDirectory($legacy);
        }
    }

    private function removeObsoletePublishedSupportFiles(): void
    {
        $obsolete = base_path('app/Support/Billing/DemoCatalog.php');
        if ($this->files->exists($obsolete)) {
            $this->files->delete($obsolete);
        }
    }

    private function publishMappedPhpTree(string $srcRoot, string $appBase, ?OutputInterface $output): void
    {
        $finder = Finder::create()
            ->files()
            ->name('*.php')
            ->in($srcRoot)
            ->notPath('Contracts')
            ->notPath('Services')
            ->notPath('Console')
            ->notPath('Routing')
            ->notPath('Support/ApplicationCodePublisher.php');

        foreach ($finder as $file) {
            $relative = $file->getRelativePathname();
            if ($relative === 'StripeLriServiceProvider.php') {
                continue;
            }

            $mapped = $this->mapSourceRelativeToAppPath($relative);
            if ($mapped === null) {
                continue;
            }

            $target = $appBase.'/'.$mapped;
            $this->files->ensureDirectoryExists(dirname($target));
            $newNs = $this->namespaceForAppPath($mapped);
            $transformed = $this->transformPhpSource($file->getContents(), $newNs);
            $this->files->put($target, $transformed);
            $output?->writeln(' <fg=gray>copy</> '.$relative.' → '.$mapped, OutputInterface::VERBOSITY_VERBOSE);
        }
    }

    private function mapSourceRelativeToAppPath(string $relative): ?string
    {
        if (str_starts_with($relative, 'Http/Controllers/')) {
            return $relative;
        }
        if (str_starts_with($relative, 'Http/Requests/')) {
            return 'Http/Requests/Billing/'.substr($relative, strlen('Http/Requests/'));
        }
        if (str_starts_with($relative, 'Models/')) {
            return 'Models/Billing/'.substr($relative, strlen('Models/'));
        }
        if (str_starts_with($relative, 'Support/')) {
            return 'Support/Billing/'.substr($relative, strlen('Support/'));
        }

        return null;
    }

    private function namespaceForAppPath(string $pathUnderApp): string
    {
        $dir = dirname($pathUnderApp);
        if ($dir === '.' || $dir === '') {
            return 'App';
        }

        return 'App\\'.str_replace('/', '\\', $dir);
    }

    private function transformPhpSource(string $content, string $newNamespace): string
    {
        $content = preg_replace('/^namespace\s+[^;]+;/m', 'namespace '.$newNamespace.';', $content, 1) ?? $content;

        $map = $this->classAliasMap();
        uksort($map, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($map as $from => $to) {
            $content = str_replace($from, $to, $content);
        }

        return $content;
    }

    /**
     * @return array<string, string>
     */
    private function classAliasMap(): array
    {
        return [
            'App\\StripeLri\\Http\\Controllers\\Admin\\' => 'App\\Http\\Controllers\\Admin\\',
            'App\\StripeLri\\Http\\Controllers\\Workspace\\' => 'App\\Http\\Controllers\\Workspace\\',
            'App\\StripeLri\\Http\\Controllers\\Webhooks\\' => 'App\\Http\\Controllers\\Webhooks\\',
            'App\\StripeLri\\Http\\Controllers\\Concerns\\' => 'App\\Http\\Controllers\\Concerns\\',
            'App\\StripeLri\\Http\\Requests\\' => 'App\\Http\\Requests\\Billing\\',
            'App\\StripeLri\\Models\\' => 'App\\Models\\Billing\\',
            'App\\StripeLri\\Support\\' => 'App\\Support\\Billing\\',
            'StripeLri\\Http\\Controllers\\Admin\\' => 'App\\Http\\Controllers\\Admin\\',
            'StripeLri\\Http\\Controllers\\Workspace\\' => 'App\\Http\\Controllers\\Workspace\\',
            'StripeLri\\Http\\Controllers\\Webhooks\\' => 'App\\Http\\Controllers\\Webhooks\\',
            'StripeLri\\Http\\Controllers\\Concerns\\' => 'App\\Http\\Controllers\\Concerns\\',
            'StripeLri\\Http\\Requests\\' => 'App\\Http\\Requests\\Billing\\',
            'StripeLri\\Models\\' => 'App\\Models\\Billing\\',
            'StripeLri\\Support\\' => 'App\\Support\\Billing\\',
            'StripeLri\\Contracts\\CreditLedger' => 'App\\Contracts\\CreditLedger',
            'StripeLri\\Services\\DatabaseCreditLedger' => 'App\\Services\\Billing\\DatabaseCreditLedger',
            'StripeLri\\Services\\NullCreditLedger' => 'App\\Services\\Billing\\NullCreditLedger',
            'StripeLri\\Services\\StripeProductPushService' => 'App\\Services\\Billing\\StripeProductPushService',
            'StripeLri\\Services\\StripeWebhookProcessor' => 'App\\Services\\Billing\\StripeWebhookProcessor',
            'StripeLri\\Models\\SubscriptionItem' => 'App\\Models\\Billing\\SubscriptionItem',
        ];
    }

    private function publishContractAndServices(string $packageRoot): void
    {
        $ledger = $this->transformPhpSource(
            $this->files->get($packageRoot.'/src/Contracts/CreditLedger.php'),
            'App\\Contracts',
        );
        $this->files->ensureDirectoryExists(app_path('Contracts'));
        $this->files->put(app_path('Contracts/CreditLedger.php'), $ledger);

        $null = $this->transformPhpSource(
            $this->files->get($packageRoot.'/src/Services/NullCreditLedger.php'),
            'App\\Services\\Billing',
        );
        $this->files->ensureDirectoryExists(app_path('Services/Billing'));
        $this->files->put(app_path('Services/Billing/NullCreditLedger.php'), $null);

        $push = $this->transformPhpSource(
            $this->files->get($packageRoot.'/src/Services/StripeProductPushService.php'),
            'App\\Services\\Billing',
        );
        $this->files->put(app_path('Services/Billing/StripeProductPushService.php'), $push);

        $processor = $this->transformPhpSource(
            $this->files->get($packageRoot.'/src/Services/StripeWebhookProcessor.php'),
            'App\\Services\\Billing',
        );
        $this->files->put(app_path('Services/Billing/StripeWebhookProcessor.php'), $processor);

        $ledgerDb = $this->transformPhpSource(
            $this->files->get($packageRoot.'/src/Services/DatabaseCreditLedger.php'),
            'App\\Services\\Billing',
        );
        $this->files->put(app_path('Services/Billing/DatabaseCreditLedger.php'), $ledgerDb);
    }

    private function publishConsoleCommands(): void
    {
        $hist = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\CreditLedger;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'stripe-lri:credits:process-history')]
final class StripeLriCreditsProcessHistory extends Command
{
    protected $signature = 'stripe-lri:credits:process-history';

    protected $description = 'Archive credit rows / expire handling. Bind CreditLedger in a service provider.';

    public function handle(CreditLedger $ledger): int
    {
        $this->info('Stripe-LRI: processing credits history...');
        $ledger->processCreditsHistory();
        $this->info('Done.');

        return self::SUCCESS;
    }
}

PHP;

        $monthly = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\CreditLedger;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'stripe-lri:credits:add-monthly-for-yearly')]
final class StripeLriCreditsAddMonthlyForYearly extends Command
{
    protected $signature = 'stripe-lri:credits:add-monthly-for-yearly';

    protected $description = 'Monthly credit reset for yearly and lifetime plans. Bind CreditLedger in a service provider.';

    public function handle(CreditLedger $ledger): int
    {
        $this->info('Stripe-LRI: monthly credit refill for yearly + lifetime (delegating to CreditLedger)...');

        $count = $ledger->addMonthlyCreditsForYearlyAndLifetime();
        $this->info("Processed {$count} credit row(s).");

        return self::SUCCESS;
    }
}

PHP;

        $this->files->ensureDirectoryExists(app_path('Console/Commands'));
        $this->files->put(app_path('Console/Commands/StripeLriCreditsProcessHistory.php'), $hist);
        $this->files->put(app_path('Console/Commands/StripeLriCreditsAddMonthlyForYearly.php'), $monthly);

        // Publish the seed command (always, not just when credit_based)
        $seed = $this->transformPhpSource(
            $this->files->get(dirname(__DIR__, 2).'/src/Console/Commands/StripeLriSeed.php'),
            'App\\Console\\Commands',
        );
        $this->files->put(app_path('Console/Commands/StripeLriSeed.php'), $seed);
    }

    private function standaloneRoutesFileContents(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

/**
 * Stripe-LRI billing routes (standalone). No vendor Stripe-LRI classes required.
 */
use App\Http\Controllers\Admin\BillingCouponsController;
use App\Http\Controllers\Admin\BillingLedgerController;
use App\Http\Controllers\Admin\BillingPackagesController;
use App\Http\Controllers\Admin\BillingUsersController;
use App\Http\Controllers\Webhooks\StripeWebhookController;
use App\Http\Controllers\Webhooks\StripeWebhookInfoController;
use App\Http\Controllers\Workspace\WorkspaceBillingController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

if (config('stripe-lri.register_webhook', true)) {
    Route::middleware('web')->group(function (): void {
        Route::get('/stripe/webhook', [StripeWebhookInfoController::class, '__invoke'])
            ->name('stripe.webhook.info');
        Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle'])
            ->name('stripe.webhook')
            ->withoutMiddleware([PreventRequestForgery::class]);
    });
}

if (config('stripe-lri.register_routes', false)) {
    $workspaceMw = config('stripe-lri.middleware.workspace', ['web', 'auth', 'verified']);
    $adminMw = config('stripe-lri.middleware.admin', ['web', 'auth', 'verified', 'admin']);

    Route::middleware($workspaceMw)->group(function (): void {
        Route::get('/billing-history', [WorkspaceBillingController::class, 'billingHistory'])
            ->name('billing-history.index');
        Route::get('/dashboard/pricing-plans', [WorkspaceBillingController::class, 'pricingPlans'])
            ->name('pricing-plans.index');
        Route::get('/subscription', [WorkspaceBillingController::class, 'subscription'])
            ->name('subscription.index');
        Route::post('/checkout', [WorkspaceBillingController::class, 'checkout'])
            ->name('checkout.create');
    });

    Route::middleware($adminMw)->prefix('admin')->name('admin.')->group(function (): void {
        Route::get('/users', [BillingUsersController::class, 'index'])->name('users.index');
        Route::get('/users/{user}', [BillingUsersController::class, 'show'])
            ->whereNumber('user')->name('users.show');
        Route::get('/users/{user}/edit', [BillingUsersController::class, 'edit'])
            ->whereNumber('user')->name('users.edit');
        Route::patch('/users/{user}', [BillingUsersController::class, 'update'])
            ->whereNumber('user')->name('users.update');
        Route::post('/users/{user}/credits', [BillingUsersController::class, 'adjustCredits'])
            ->whereNumber('user')->name('users.credits.adjust');
        Route::post('/users/{user}/impersonate', [BillingUsersController::class, 'impersonate'])
            ->whereNumber('user')->name('users.impersonate');
        Route::delete('/users/{user}', [BillingUsersController::class, 'destroy'])
            ->whereNumber('user')->name('users.destroy');

        Route::get('/packages', [BillingPackagesController::class, 'index'])->name('packages.index');
        Route::get('/packages/create', [BillingPackagesController::class, 'create'])->name('packages.create');
        Route::post('/packages', [BillingPackagesController::class, 'store'])->name('packages.store');
        Route::get('/packages/{package}/edit', [BillingPackagesController::class, 'edit'])
            ->whereNumber('package')->name('packages.edit');
        Route::put('/packages/{package}', [BillingPackagesController::class, 'update'])
            ->whereNumber('package')->name('packages.update');
        Route::delete('/packages/{package}', [BillingPackagesController::class, 'destroy'])
            ->whereNumber('package')->name('packages.destroy');

        Route::get('/coupons', [BillingCouponsController::class, 'index'])->name('coupons.index');
        Route::get('/coupons/create', [BillingCouponsController::class, 'create'])->name('coupons.create');
        Route::post('/coupons', [BillingCouponsController::class, 'store'])->name('coupons.store');
        Route::get('/coupons/{coupon}/edit', [BillingCouponsController::class, 'edit'])
            ->whereNumber('coupon')->name('coupons.edit');
        Route::put('/coupons/{coupon}', [BillingCouponsController::class, 'update'])
            ->whereNumber('coupon')->name('coupons.update');
        Route::delete('/coupons/{coupon}', [BillingCouponsController::class, 'destroy'])
            ->whereNumber('coupon')->name('coupons.destroy');

        Route::get('/transactions', [BillingLedgerController::class, 'transactions'])->name('transactions.index');
        Route::get('/invoices', [BillingLedgerController::class, 'invoices'])->name('invoices.index');
        Route::get('/premium-customers', [BillingLedgerController::class, 'premiumCustomers'])->name('premium-customers.index');
        Route::get('/premium-customers/revenue-month', [BillingLedgerController::class, 'premiumRevenueMonth'])
            ->name('premium-customers.revenue-month');
    });
}

PHP;
    }

    private function hostStripeLriServiceProviderContents(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class StripeLriServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singletonIf(
            \App\Contracts\CreditLedger::class,
            function (): \App\Contracts\CreditLedger {
                if (config('stripe-lri.credit_based')) {
                    return new \App\Services\Billing\DatabaseCreditLedger;
                }
                return new \App\Services\Billing\NullCreditLedger;
            }
        );
    }

    public function boot(): void
    {
        if (file_exists(base_path('routes/stripe-lri.php'))) {
            $this->loadRoutesFrom(base_path('routes/stripe-lri.php'));
        }

        $this->registerScheduledTasks();

        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Console\Commands\StripeLriSeed::class,
            ]);

            if (config('stripe-lri.credit_based')) {
                $this->commands([
                    \App\Console\Commands\StripeLriCreditsProcessHistory::class,
                    \App\Console\Commands\StripeLriCreditsAddMonthlyForYearly::class,
                ]);
            }
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

PHP;
    }

    private function ensureHostProviderRegistered(?OutputInterface $output): void
    {
        $path = base_path('bootstrap/providers.php');
        if (! $this->files->exists($path)) {
            $output?->writeln(' <fg=yellow>bootstrap/providers.php not found; register App\\Providers\\StripeLriServiceProvider manually.</>', OutputInterface::VERBOSITY_NORMAL);

            return;
        }

        $contents = $this->files->get($path);
        if (str_contains($contents, 'App\\Providers\\StripeLriServiceProvider')) {
            return;
        }

        if (str_contains($contents, 'AppServiceProvider::class,')) {
            $contents = preg_replace(
                '/AppServiceProvider::class,/',
                "AppServiceProvider::class,\n    \\App\\Providers\\StripeLriServiceProvider::class,",
                $contents,
                1,
            ) ?? $contents;
        } elseif (preg_match('/AppServiceProvider::class\s*\n\s*\]/', $contents)) {
            $contents = preg_replace(
                '/AppServiceProvider::class(\s*\n\s*\])/',
                "AppServiceProvider::class,\n    \\App\\Providers\\StripeLriServiceProvider::class\n]",
                $contents,
                1,
            ) ?? $contents;
        }

        $this->files->put($path, $contents);
        $output?->writeln(' <fg=gray>registered</> App\\Providers\\StripeLriServiceProvider in bootstrap/providers.php', OutputInterface::VERBOSITY_VERBOSE);
    }

    /**
     * Ensure the published config/stripe-lri.php has all required model keys.
     * Laravel's mergeConfigFrom() does not deep-merge nested arrays, so model keys
     * added to the package config after initial publish are never seen by the app.
     */
    private function ensureConfigModelKeys(string $packageRoot, ?OutputInterface $output): void
    {
        $configPath = config_path('stripe-lri.php');
        if (! $this->files->exists($configPath)) {
            // Config not yet published — copy from package so the app has a full file.
            $this->files->copy($packageRoot.'/config/stripe-lri.php', $configPath);
            $output?->writeln(' <fg=gray>published</> config/stripe-lri.php', OutputInterface::VERBOSITY_VERBOSE);

            return;
        }

        $content = $this->files->get($configPath);

        $required = [
            'subscription_product_user' => "env('STRIPE_LRI_SPU_MODEL', 'App\\\\Models\\\\Billing\\\\SubscriptionProductUser')",
            'payment'                   => "env('STRIPE_LRI_PAYMENT_MODEL', 'App\\\\Models\\\\Billing\\\\Payment')",
            'account_deletion_log'      => "env('STRIPE_LRI_DELETION_LOG_MODEL', 'App\\\\Models\\\\AccountDeletionLog')",
        ];

        $additions = '';
        foreach ($required as $key => $default) {
            if (! str_contains($content, "'$key'") && ! str_contains($content, '"'.$key.'"')) {
                $additions .= "\n        '$key' => $default,";
                $output?->writeln(" <fg=gray>patch config</> added models.$key", OutputInterface::VERBOSITY_VERBOSE);
            }
        }

        if ($additions === '') {
            return;
        }

        // Insert the new keys right after the existing 'user' model line.
        $content = preg_replace(
            "/'user'\s*=>\s*env\([^)]+\),/",
            "$0$additions",
            $content,
            1,
        ) ?? $content;

        $this->files->put($configPath, $content);
    }

    /**
     * Patch routes/web.php so that billing pages are served by the real published controllers
     * rather than stub/demo controllers. Handles the admin-template starter kit pattern where
     * stub controllers (AdminUsersController, AdminPackagesController, etc.) are wired to billing
     * routes inside a prefix('admin') group with relative paths. Returns true if changed.
     *
     * Strategy:
     *  1. Replace stub controller class references with real billing controller references in Route:: calls.
     *  2. Swap use-imports accordingly (remove stubs, inject real billing controller imports).
     *  3. Inject missing route verbs (DELETE for packages, DELETE for coupons, revenue-month endpoint).
     *  4. Append workspace billing routes (billing-history, pricing-plans, subscription, checkout) if absent.
     *  5. Append admin billing routes (users/packages/coupons/transactions/invoices) if absent.
     *
     * Note: Stripe webhook routes are NOT injected here — they are exclusively registered
     * by routes/stripe-lri.php (loaded by StripeLriServiceProvider), which is always loaded
     * before web.php could conflict.
     */
    private function patchWebRoutes(?OutputInterface $output): bool
    {
        $webRoutes = base_path('routes/web.php');
        if (! $this->files->exists($webRoutes)) {
            return false;
        }

        $content  = $this->files->get($webRoutes);
        $original = $content;

        // ── 1. Replace stub Route:: controller class references ───────────────
        // AdminSectionController billing-method redirects (only billing methods, not accountLogs/credentials)
        $billingMethodsOnSectionController = ['transactions', 'invoices', 'premiumCustomers'];
        foreach ($billingMethodsOnSectionController as $method) {
            $content = str_replace(
                "[AdminSectionController::class, '{$method}']",
                "[BillingLedgerController::class, '{$method}']",
                $content,
            );
        }

        // Full stub-controller → real-controller swaps.
        // Skip each swap when the billing class is already referenced (e.g. inside a
        // class_exists() if/else block in the template) — swapping would corrupt the else branch.
        $classSwaps = [
            'AdminUsersController::class'    => 'BillingUsersController::class',
            'AdminPackagesController::class' => 'BillingPackagesController::class',
            'AdminCouponsController::class'  => 'BillingCouponsController::class',
        ];
        foreach ($classSwaps as $from => $to) {
            if (! str_contains($content, $to)) {
                $content = str_replace($from, $to, $content);
            }
        }

        // ── 2. Fix use-imports ────────────────────────────────────────────────
        // Remove stub imports only when the stub class is no longer referenced in Route calls.
        $stubImportPatterns = [
            'AdminUsersController'    => '/^use\s+\S+\\\\AdminUsersController;\n?/m',
            'AdminPackagesController' => '/^use\s+\S+\\\\AdminPackagesController;\n?/m',
            'AdminCouponsController'  => '/^use\s+\S+\\\\AdminCouponsController;\n?/m',
        ];
        foreach ($stubImportPatterns as $stub => $pattern) {
            // Keep the import when the stub is still used (e.g. in an else fallback branch).
            if (! str_contains($content, $stub.'::class')) {
                $content = preg_replace($pattern, '', $content) ?? $content;
            }
        }

        // Inject real billing controller imports if not already present.
        $requiredImports = [
            'App\\Http\\Controllers\\Admin\\BillingUsersController',
            'App\\Http\\Controllers\\Admin\\BillingPackagesController',
            'App\\Http\\Controllers\\Admin\\BillingCouponsController',
            'App\\Http\\Controllers\\Admin\\BillingLedgerController',
            'App\\Http\\Controllers\\Workspace\\WorkspaceBillingController',
        ];
        $importBlock = '';
        foreach ($requiredImports as $fqcn) {
            if (! str_contains($content, $fqcn)) {
                $importBlock .= "use {$fqcn};\n";
            }
        }
        if ($importBlock !== '') {
            // Insert after the opening <?php line.
            $content = preg_replace('/^<\?php\s*\n/m', "<?php\n\n{$importBlock}", $content, 1) ?? $content;
        }

        // ── 3. Inject missing admin route verbs ───────────────────────────────
        // DELETE /packages/{package}
        if (str_contains($content, 'BillingPackagesController') && ! str_contains($content, "->name('packages.destroy')")) {
            $content = str_replace(
                "Route::get('/packages/{package}/edit', [BillingPackagesController::class, 'edit'])->whereNumber('package')->name('packages.edit');",
                "Route::get('/packages/{package}/edit', [BillingPackagesController::class, 'edit'])->whereNumber('package')->name('packages.edit');\n    Route::delete('/packages/{package}', [BillingPackagesController::class, 'destroy'])->whereNumber('package')->name('packages.destroy');",
                $content,
            );
        }
        // DELETE /coupons/{coupon}
        if (str_contains($content, 'BillingCouponsController') && ! str_contains($content, "->name('coupons.destroy')")) {
            $content = str_replace(
                "Route::get('/coupons/{coupon}/edit', [BillingCouponsController::class, 'edit'])->whereNumber('coupon')->name('coupons.edit');",
                "Route::get('/coupons/{coupon}/edit', [BillingCouponsController::class, 'edit'])->whereNumber('coupon')->name('coupons.edit');\n    Route::delete('/coupons/{coupon}', [BillingCouponsController::class, 'destroy'])->whereNumber('coupon')->name('coupons.destroy');",
                $content,
            );
        }
        // GET /premium-customers/revenue-month
        if (str_contains($content, 'BillingLedgerController') && ! str_contains($content, 'revenue-month')) {
            $content = str_replace(
                "Route::get('/premium-customers', [BillingLedgerController::class, 'premiumCustomers'])->name('premium-customers.index');",
                "Route::get('/premium-customers', [BillingLedgerController::class, 'premiumCustomers'])->name('premium-customers.index');\n    Route::get('/premium-customers/revenue-month', [BillingLedgerController::class, 'premiumRevenueMonth'])->name('premium-customers.revenue-month');",
                $content,
            );
        }

        // ── 4. Add workspace billing routes if missing ─────────────────────────
        if (! str_contains($content, 'billing-history')) {
            $workspaceBlock = <<<'PHP'

// Workspace billing routes (stripe-lri) — guarded so removing the published
// controllers reverts gracefully: routes vanish from Ziggy, sidebar hides them.
Route::middleware(['auth', 'verified'])->group(function (): void {
    if (class_exists(\App\Http\Controllers\Workspace\WorkspaceBillingController::class)) {
        Route::get('/billing-history', [\App\Http\Controllers\Workspace\WorkspaceBillingController::class, 'billingHistory'])->name('billing-history.index');
        Route::get('/dashboard/pricing-plans', [\App\Http\Controllers\Workspace\WorkspaceBillingController::class, 'pricingPlans'])->name('pricing-plans.index');
        Route::get('/subscription', [\App\Http\Controllers\Workspace\WorkspaceBillingController::class, 'subscription'])->name('subscription.index');
        Route::post('/checkout', [\App\Http\Controllers\Workspace\WorkspaceBillingController::class, 'checkout'])->name('checkout.create');
    }
});

PHP;
            $content = str_replace("require __DIR__.'/auth.php';", $workspaceBlock."require __DIR__.'/auth.php';", $content);
        }

        // ── 5. Add admin billing routes if missing ────────────────────────────
        // Check for ::class usage (Route calls), NOT just import lines, so the import
        // injected in section 2 does not falsely satisfy this check on fresh templates.
        if (! str_contains($content, 'BillingUsersController::class')) {
            $adminBillingBlock = <<<'PHP'

// Admin billing routes (stripe-lri) — guarded so removing published controllers
// reverts gracefully: routes vanish from Ziggy, sidebar hides them automatically.
Route::middleware(['auth', 'verified', 'admin'])->prefix('admin')->name('admin.')->group(function (): void {
    if (class_exists(\App\Http\Controllers\Admin\BillingUsersController::class)) {
        Route::get('/users', [\App\Http\Controllers\Admin\BillingUsersController::class, 'index'])->name('users.index');
        Route::get('/users/{user}', [\App\Http\Controllers\Admin\BillingUsersController::class, 'show'])->whereNumber('user')->name('users.show');
        Route::get('/users/{user}/edit', [\App\Http\Controllers\Admin\BillingUsersController::class, 'edit'])->whereNumber('user')->name('users.edit');
        Route::patch('/users/{user}', [\App\Http\Controllers\Admin\BillingUsersController::class, 'update'])->whereNumber('user')->name('users.update');
        Route::post('/users/{user}/credits', [\App\Http\Controllers\Admin\BillingUsersController::class, 'adjustCredits'])->whereNumber('user')->name('users.credits.adjust');
        Route::post('/users/{user}/impersonate', [\App\Http\Controllers\Admin\BillingUsersController::class, 'impersonate'])->whereNumber('user')->name('users.impersonate');
        Route::delete('/users/{user}', [\App\Http\Controllers\Admin\BillingUsersController::class, 'destroy'])->whereNumber('user')->name('users.destroy');
    }

    if (class_exists(\App\Http\Controllers\Admin\BillingPackagesController::class)) {
        Route::get('/packages', [\App\Http\Controllers\Admin\BillingPackagesController::class, 'index'])->name('packages.index');
        Route::get('/packages/create', [\App\Http\Controllers\Admin\BillingPackagesController::class, 'create'])->name('packages.create');
        Route::post('/packages', [\App\Http\Controllers\Admin\BillingPackagesController::class, 'store'])->name('packages.store');
        Route::get('/packages/{package}/edit', [\App\Http\Controllers\Admin\BillingPackagesController::class, 'edit'])->whereNumber('package')->name('packages.edit');
        Route::put('/packages/{package}', [\App\Http\Controllers\Admin\BillingPackagesController::class, 'update'])->whereNumber('package')->name('packages.update');
        Route::delete('/packages/{package}', [\App\Http\Controllers\Admin\BillingPackagesController::class, 'destroy'])->whereNumber('package')->name('packages.destroy');
    }

    if (class_exists(\App\Http\Controllers\Admin\BillingCouponsController::class)) {
        Route::get('/coupons', [\App\Http\Controllers\Admin\BillingCouponsController::class, 'index'])->name('coupons.index');
        Route::get('/coupons/create', [\App\Http\Controllers\Admin\BillingCouponsController::class, 'create'])->name('coupons.create');
        Route::post('/coupons', [\App\Http\Controllers\Admin\BillingCouponsController::class, 'store'])->name('coupons.store');
        Route::get('/coupons/{coupon}/edit', [\App\Http\Controllers\Admin\BillingCouponsController::class, 'edit'])->whereNumber('coupon')->name('coupons.edit');
        Route::put('/coupons/{coupon}', [\App\Http\Controllers\Admin\BillingCouponsController::class, 'update'])->whereNumber('coupon')->name('coupons.update');
        Route::delete('/coupons/{coupon}', [\App\Http\Controllers\Admin\BillingCouponsController::class, 'destroy'])->whereNumber('coupon')->name('coupons.destroy');
    }

    if (class_exists(\App\Http\Controllers\Admin\BillingLedgerController::class)) {
        Route::get('/transactions', [\App\Http\Controllers\Admin\BillingLedgerController::class, 'transactions'])->name('transactions.index');
        Route::get('/invoices', [\App\Http\Controllers\Admin\BillingLedgerController::class, 'invoices'])->name('invoices.index');
        Route::get('/premium-customers', [\App\Http\Controllers\Admin\BillingLedgerController::class, 'premiumCustomers'])->name('premium-customers.index');
        Route::get('/premium-customers/revenue-month', [\App\Http\Controllers\Admin\BillingLedgerController::class, 'premiumRevenueMonth'])->name('premium-customers.revenue-month');
    }
});

PHP;
            $content = str_replace("require __DIR__.'/auth.php';", $adminBillingBlock."require __DIR__.'/auth.php';", $content);
        }

        if ($content === $original) {
            return false;
        }

        $this->files->put($webRoutes, $content);
        $output?->writeln(' <fg=gray>patched</> routes/web.php — billing routes now use real controllers', OutputInterface::VERBOSITY_NORMAL);

        return true;
    }

    private function copyMigrationDirectory(string $sourceDir, string $destDir, bool $force, ?OutputInterface $output): void
    {
        if (! $this->files->isDirectory($sourceDir)) {
            return;
        }

        foreach ($this->files->files($sourceDir) as $file) {
            $name = $file->getFilename();
            $target = $destDir.'/'.$name;
            if ($this->files->exists($target) && ! $force) {
                $output?->writeln(' <fg=gray>skip migration</> '.$name.' (exists)', OutputInterface::VERBOSITY_VERBOSE);

                continue;
            }
            $this->files->copy($file->getPathname(), $target);
            $output?->writeln(' <fg=gray>migration</> '.$name, OutputInterface::VERBOSITY_VERBOSE);
        }
    }
}

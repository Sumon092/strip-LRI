<?php

namespace StripeLri\Support;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

/**
 * Copies Stripe-LRI sources into conventional Laravel app paths (Billing segment)
 * so the host app runs without resolving controllers/models from vendor.
 *
 * @see \App\Providers\StripeLriServiceProvider Generated on install; when present the
 *      package {@see \StripeLri\StripeLriServiceProvider} becomes a no-op so you may
 *      remove {@code stripe-lri/laravel} from Composer after install.
 */
final class ApplicationCodePublisher
{
    private const MARKER_RELATIVE = 'Http/Controllers/Billing/StripeWebhookInfoController.php';

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

        if ($fullCodePublish) {
            $this->removeLegacyAppStripeLriDirectory();
            $this->publishMappedPhpTree($packageRoot.'/src', $appBase, $output);
            $this->publishContractAndServices($packageRoot);
            $this->publishConsoleCommands();
            $this->files->put(base_path('routes/stripe-lri.php'), $this->standaloneRoutesFileContents());
            $this->files->put(app_path('Providers/StripeLriServiceProvider.php'), $this->hostStripeLriServiceProviderContents());
            $this->ensureHostProviderRegistered($output);
            $lines[] = 'Published billing code under app/Http, app/Models, app/Support, app/Contracts, app/Services, app/Console.';
            $lines[] = 'Published app/Providers/StripeLriServiceProvider.php and routes/stripe-lri.php (no vendor route classes).';
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
        if (str_starts_with($relative, 'Http/Controllers/Admin/')) {
            return 'Http/Controllers/Billing/'.substr($relative, strlen('Http/Controllers/'));
        }
        if (str_starts_with($relative, 'Http/Controllers/Workspace/')) {
            return 'Http/Controllers/Billing/'.substr($relative, strlen('Http/Controllers/'));
        }
        if (str_starts_with($relative, 'Http/Controllers/StripeWebhook')) {
            return 'Http/Controllers/Billing/'.basename($relative);
        }
        if ($relative === 'Http/Controllers/Controller.php') {
            return 'Http/Controllers/Billing/Controller.php';
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
            'App\\StripeLri\\Http\\Controllers\\Admin\\' => 'App\\Http\\Controllers\\Billing\\Admin\\',
            'App\\StripeLri\\Http\\Controllers\\Workspace\\' => 'App\\Http\\Controllers\\Billing\\Workspace\\',
            'App\\StripeLri\\Http\\Controllers\\StripeWebhook' => 'App\\Http\\Controllers\\Billing\\StripeWebhook',
            'App\\StripeLri\\Http\\Controllers\\Controller' => 'App\\Http\\Controllers\\Billing\\Controller',
            'App\\StripeLri\\Http\\Requests\\' => 'App\\Http\\Requests\\Billing\\',
            'App\\StripeLri\\Models\\' => 'App\\Models\\Billing\\',
            'App\\StripeLri\\Support\\' => 'App\\Support\\Billing\\',
            'StripeLri\\Http\\Controllers\\Admin\\' => 'App\\Http\\Controllers\\Billing\\Admin\\',
            'StripeLri\\Http\\Controllers\\Workspace\\' => 'App\\Http\\Controllers\\Billing\\Workspace\\',
            'StripeLri\\Http\\Controllers\\StripeWebhookInfoController' => 'App\\Http\\Controllers\\Billing\\StripeWebhookInfoController',
            'StripeLri\\Http\\Controllers\\StripeWebhookController' => 'App\\Http\\Controllers\\Billing\\StripeWebhookController',
            'StripeLri\\Http\\Controllers\\Controller' => 'App\\Http\\Controllers\\Billing\\Controller',
            'StripeLri\\Http\\Requests\\' => 'App\\Http\\Requests\\Billing\\',
            'StripeLri\\Models\\' => 'App\\Models\\Billing\\',
            'StripeLri\\Support\\' => 'App\\Support\\Billing\\',
            'StripeLri\\Contracts\\CreditLedger' => 'App\\Contracts\\CreditLedger',
            'StripeLri\\Services\\NullCreditLedger' => 'App\\Services\\Billing\\NullCreditLedger',
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
    }

    private function standaloneRoutesFileContents(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

/**
 * Stripe-LRI billing routes (standalone). No vendor Stripe-LRI classes required.
 */
use App\Http\Controllers\Billing\Admin\AdminBillingController;
use App\Http\Controllers\Billing\Admin\AdminCouponsController;
use App\Http\Controllers\Billing\Admin\AdminPackagesController;
use App\Http\Controllers\Billing\Admin\AdminUsersController;
use App\Http\Controllers\Billing\StripeWebhookController;
use App\Http\Controllers\Billing\StripeWebhookInfoController;
use App\Http\Controllers\Billing\Workspace\WorkspaceBillingController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

if (config('stripe-lri.register_webhook', true)) {
    Route::middleware('web')->group(function (): void {
        Route::get('/stripe/webhook', [StripeWebhookInfoController::class, '__invoke'])
            ->name('stripe.webhook.info');
        Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle'])
            ->name('stripe.webhook')
            ->withoutMiddleware([VerifyCsrfToken::class]);
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
    });

    Route::middleware($adminMw)->prefix('admin')->name('admin.')->group(function (): void {
        Route::get('/users', [AdminUsersController::class, 'index'])->name('users.index');
        Route::get('/users/{user}', [AdminUsersController::class, 'show'])
            ->whereNumber('user')->name('users.show');
        Route::get('/users/{user}/edit', [AdminUsersController::class, 'edit'])
            ->whereNumber('user')->name('users.edit');
        Route::patch('/users/{user}', [AdminUsersController::class, 'update'])
            ->whereNumber('user')->name('users.update');
        Route::post('/users/{user}/credits', [AdminUsersController::class, 'adjustCredits'])
            ->whereNumber('user')->name('users.credits.adjust');
        Route::post('/users/{user}/impersonate', [AdminUsersController::class, 'impersonate'])
            ->whereNumber('user')->name('users.impersonate');
        Route::delete('/users/{user}', [AdminUsersController::class, 'destroy'])
            ->whereNumber('user')->name('users.destroy');

        Route::get('/packages', [AdminPackagesController::class, 'index'])->name('packages.index');
        Route::get('/packages/create', [AdminPackagesController::class, 'create'])->name('packages.create');
        Route::post('/packages', [AdminPackagesController::class, 'store'])->name('packages.store');
        Route::get('/packages/{package}/edit', [AdminPackagesController::class, 'edit'])
            ->whereNumber('package')->name('packages.edit');
        Route::put('/packages/{package}', [AdminPackagesController::class, 'update'])
            ->whereNumber('package')->name('packages.update');
        Route::delete('/packages/{package}', [AdminPackagesController::class, 'destroy'])
            ->whereNumber('package')->name('packages.destroy');

        Route::get('/coupons', [AdminCouponsController::class, 'index'])->name('coupons.index');
        Route::get('/coupons/create', [AdminCouponsController::class, 'create'])->name('coupons.create');
        Route::post('/coupons', [AdminCouponsController::class, 'store'])->name('coupons.store');
        Route::get('/coupons/{coupon}/edit', [AdminCouponsController::class, 'edit'])
            ->whereNumber('coupon')->name('coupons.edit');
        Route::put('/coupons/{coupon}', [AdminCouponsController::class, 'update'])
            ->whereNumber('coupon')->name('coupons.update');
        Route::delete('/coupons/{coupon}', [AdminCouponsController::class, 'destroy'])
            ->whereNumber('coupon')->name('coupons.destroy');

        Route::get('/transactions', [AdminBillingController::class, 'transactions'])->name('transactions.index');
        Route::get('/invoices', [AdminBillingController::class, 'invoices'])->name('invoices.index');
        Route::get('/premium-customers', [AdminBillingController::class, 'premiumCustomers'])->name('premium-customers.index');
        Route::get('/premium-customers/revenue-month', [AdminBillingController::class, 'premiumRevenueMonth'])
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
            fn (): \App\Contracts\CreditLedger => new \App\Services\Billing\NullCreditLedger
        );
    }

    public function boot(): void
    {
        if (file_exists(base_path('routes/stripe-lri.php'))) {
            $this->loadRoutesFrom(base_path('routes/stripe-lri.php'));
        }

        $this->registerScheduledTasks();

        if ($this->app->runningInConsole() && config('stripe-lri.credit_based')) {
            $this->commands([
                \App\Console\Commands\StripeLriCreditsProcessHistory::class,
                \App\Console\Commands\StripeLriCreditsAddMonthlyForYearly::class,
            ]);
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

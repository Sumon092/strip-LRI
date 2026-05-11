<?php

namespace StripeLri\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use StripeLri\Support\ApplicationCodePublisher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'stripe-lri:install')]
class InstallStripeLriCommand extends Command
{
    protected $signature = 'stripe-lri:install
                            {--force : Overwrite published config, app sources, routes, and migrations}
                            {--no-migrate : Skip running database migrations}
                            {--credit-based : Mark app as credit-based (non-interactive)}
                            {--no-credit-based : Mark app as not credit-based (non-interactive)}
                            {--site-limit : Mark app as site-limit based (non-interactive)}
                            {--no-site-limit : Mark app as not site-limit based (non-interactive)}
                            {--premium-features : Install premium-features schema (non-interactive)}
                            {--no-premium-features : Skip premium-features schema (non-interactive)}
                            {--skip-app-publish : Keep controllers/migrations in vendor only (no app/StripeLri copy)}';

    protected $description = 'Publish config, copy workable PHP + migrations into the host app, set .env, and run migrations unless skipped.';

    public function handle(): int
    {
        $this->call('vendor:publish', [
            '--tag' => 'stripe-lri-config',
            '--force' => (bool) $this->option('force'),
        ]);

        if ($this->option('credit-based') && $this->option('no-credit-based')) {
            $this->components->error('Use only one of --credit-based or --no-credit-based.');

            return self::FAILURE;
        }

        if ($this->option('credit-based')) {
            $creditBased = true;
        } elseif ($this->option('no-credit-based')) {
            $creditBased = false;
        } else {
            $creditBased = $this->confirm('Is this application credit-based? (Credits packages, usage meters, etc.)', true);
        }

        if ($this->option('site-limit') && $this->option('no-site-limit')) {
            $this->components->error('Use only one of --site-limit or --no-site-limit.');

            return self::FAILURE;
        }

        if ($this->option('site-limit')) {
            $siteLimited = true;
        } elseif ($this->option('no-site-limit')) {
            $siteLimited = false;
        } else {
            $siteLimited = $this->confirm('Is this application site-limit based? (Sites/domains per subscription, etc.)', false);
        }

        if ($this->option('premium-features') && $this->option('no-premium-features')) {
            $this->components->error('Use only one of --premium-features or --no-premium-features.');

            return self::FAILURE;
        }

        if ($this->option('premium-features')) {
            $premiumFeatures = true;
        } elseif ($this->option('no-premium-features')) {
            $premiumFeatures = false;
        } else {
            $premiumFeatures = $this->confirm('Include premium features? (Adds premium_features catalog + per-package inclusion toggles)', false);
        }

        $this->writeEnvBool('STRIPE_LRI_CREDIT_BASED', $creditBased);
        $this->writeEnvBool('STRIPE_LRI_SITE_LIMIT', $siteLimited);
        $this->writeEnvBool('STRIPE_LRI_PREMIUM_FEATURES', $premiumFeatures);
        $this->writeEnvBool('STRIPE_LRI_REGISTER_ROUTES', true);
        $this->writeEnvBool('STRIPE_LRI_REGISTER_WEBHOOK', true);

        $skipPublish = (bool) $this->option('skip-app-publish');
        $this->writeEnvBool('STRIPE_LRI_PUBLISHED_TO_APP', ! $skipPublish);

        if (! $skipPublish) {
            $this->newLine();
            $this->components->info('Publishing Stripe-LRI into your application (app/Http, app/Models, migrations, routes/stripe-lri.php, app/Providers/StripeLriServiceProvider.php)...');
            $publisher = new ApplicationCodePublisher;
            foreach ($publisher->publishAll((bool) $this->option('force'), $creditBased, $siteLimited, $premiumFeatures, $this->output) as $line) {
                $this->line(' • '.$line);
            }

            // Prevent the package service provider from being auto-discovered so that
            // vendor/stripe-lri/laravel can be deleted after publish without causing a
            // "class not found" fatal. The published App\Providers\StripeLriServiceProvider
            // (registered in bootstrap/providers.php) handles everything independently.
            $this->addDontDiscover();
            $this->call('package:discover', ['--quiet' => true]);
        } else {
            $this->components->warn('Skipped publishing app sources (--skip-app-publish). Routes and migrations load from the package.');
        }

        if (! $this->option('no-migrate')) {
            $this->newLine();
            $this->components->info('Running migrations...');
            try {
                // Subprocess so a fresh bootstrap reads STRIPE_LRI_CREDIT_BASED from .env (same-process migrate would not).
                $process = new Process([PHP_BINARY, 'artisan', 'migrate', '--force'], base_path());
                $process->setTimeout(null);
                $process->run(function (string $type, string $buffer): void {
                    $this->output->write($buffer);
                });
                if (! $process->isSuccessful()) {
                    $this->components->warn('migrate exited with code '.$process->getExitCode().'. Check output above.');

                    return self::FAILURE;
                }
            } catch (\Throwable $e) {
                $this->components->error('Migration failed: '.$e->getMessage());
                $this->line('Re-run manually: php artisan migrate');

                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->components->info('Stripe-LRI configured.');
        $this->line(' • STRIPE_LRI_CREDIT_BASED='.($creditBased ? 'true' : 'false'));
        $this->line(' • STRIPE_LRI_SITE_LIMIT='.($siteLimited ? 'true' : 'false'));
        $this->line(' • STRIPE_LRI_PREMIUM_FEATURES='.($premiumFeatures ? 'true' : 'false'));
        $this->line(' • STRIPE_LRI_PUBLISHED_TO_APP='.($skipPublish ? 'false' : 'true'));
        $this->line(' • STRIPE_LRI_REGISTER_ROUTES=true (set false in .env if your app already defines the same URLs)');
        $this->line(' • STRIPE_LRI_REGISTER_WEBHOOK=true — POST /stripe/webhook (mandatory for production billing; set STRIPE_WEBHOOK_SECRET before going live)');
        if (! $skipPublish) {
            $this->line(' • Published controllers: app/Http/Controllers/Admin (Billing*), Workspace, Webhooks, Concerns; models/support: app/Models/Billing, app/Support/Billing; app/Contracts, app/Services/Billing, app/Console/Commands.');
            $this->line(' • App provider: app/Providers/StripeLriServiceProvider.php (registered in bootstrap/providers.php).');
            $this->line(' • After you verify the app, you may run: composer remove stripe-lri/laravel — the package becomes optional; keep it only if you want stripe-lri:install updates.');
            if (! $creditBased) {
                $this->line(' • Later, to add credit migrations: php artisan stripe-lri:install --credit-based --force');
            }
            if (! $siteLimited) {
                $this->line(' • Later, to add site-limit migrations: php artisan stripe-lri:install --site-limit --force');
            }
            if (! $premiumFeatures) {
                $this->line(' • Later, to add premium-features migrations: php artisan stripe-lri:install --premium-features --force');
            }
        }
        $this->newLine();
        $this->line('Run `php artisan config:clear` if config is cached.');

        return self::SUCCESS;
    }

    private function addDontDiscover(): void
    {
        $path = base_path('composer.json');
        if (! File::exists($path)) {
            return;
        }

        $json = json_decode(File::get($path), true);
        if (! is_array($json)) {
            return;
        }

        $dontDiscover = $json['extra']['laravel']['dont-discover'] ?? [];
        if (in_array('stripe-lri/laravel', $dontDiscover, true)) {
            return;
        }

        $dontDiscover[] = 'stripe-lri/laravel';
        $json['extra']['laravel']['dont-discover'] = $dontDiscover;
        File::put($path, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    private function writeEnvBool(string $key, bool $value): void
    {
        $path = base_path('.env');
        if (! File::exists($path)) {
            $this->components->warn('.env not found; set '.$key.' manually.');

            return;
        }

        $line = $key.'='.($value ? 'true' : 'false');
        $contents = File::get($path);

        if (preg_match('/^'.preg_quote($key, '/').'=.*/m', $contents)) {
            $contents = preg_replace('/^'.preg_quote($key, '/').'=.*/m', $line, $contents);
        } else {
            $contents = rtrim($contents)."\n".$line."\n";
        }

        File::put($path, $contents);
    }
}

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

        $this->writeEnvBool('STRIPE_LRI_CREDIT_BASED', $creditBased);
        $this->writeEnvBool('STRIPE_LRI_REGISTER_ROUTES', true);
        $this->writeEnvBool('STRIPE_LRI_REGISTER_WEBHOOK', true);

        $skipPublish = (bool) $this->option('skip-app-publish');
        $this->writeEnvBool('STRIPE_LRI_PUBLISHED_TO_APP', ! $skipPublish);

        if (! $skipPublish) {
            $this->newLine();
            $this->components->info('Publishing Stripe-LRI code into your application (app/StripeLri, database/migrations, routes/stripe-lri.php)...');
            $publisher = new ApplicationCodePublisher;
            foreach ($publisher->publishAll((bool) $this->option('force'), $creditBased, $this->output) as $line) {
                $this->line(' • '.$line);
            }
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
        $this->line(' • STRIPE_LRI_PUBLISHED_TO_APP='.($skipPublish ? 'false' : 'true'));
        $this->line(' • STRIPE_LRI_REGISTER_ROUTES=true (set false in .env if your app already defines the same URLs)');
        $this->line(' • STRIPE_LRI_REGISTER_WEBHOOK=true — POST /stripe/webhook (no web.php edits)');
        if (! $skipPublish) {
            $this->line(' • Controllers & domain code: app/StripeLri — edit there; re-run install with --force to refresh from the package.');
            if (! $creditBased) {
                $this->line(' • Later, to add credit migrations into this app: php artisan stripe-lri:install --credit-based --force');
            }
        }
        $this->newLine();
        $this->line('Run `php artisan config:clear` if config is cached.');

        return self::SUCCESS;
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

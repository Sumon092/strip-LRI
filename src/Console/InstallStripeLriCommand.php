<?php

namespace StripeLri\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'stripe-lri:install')]
class InstallStripeLriCommand extends Command
{
    protected $signature = 'stripe-lri:install
                            {--force : Overwrite published config}
                            {--no-migrate : Skip running database migrations}
                            {--credit-based : Mark app as credit-based (non-interactive)}
                            {--no-credit-based : Mark app as not credit-based (non-interactive)}';

    protected $description = 'Publish Stripe-LRI config, set .env flags, register webhooks (no web.php edits), and run migrations unless skipped.';

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

        if (! $this->option('no-migrate')) {
            $this->newLine();
            $this->components->info('Running migrations (Stripe-LRI tables from package)...');
            try {
                $code = Artisan::call('migrate', ['--force' => true]);
                if ($code !== 0) {
                    $this->components->warn('migrate exited with code '.$code.'. Check output above.');

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
        $this->line(' • STRIPE_LRI_REGISTER_ROUTES=true (set false in .env if your app already defines the same URLs)');
        $this->line(' • STRIPE_LRI_REGISTER_WEBHOOK=true — POST /stripe/webhook is registered by the package (no web.php edits)');
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

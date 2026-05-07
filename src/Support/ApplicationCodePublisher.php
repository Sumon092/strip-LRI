<?php

namespace StripeLri\Support;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

/**
 * Copies Stripe-LRI PHP sources into the host app under {@see \App\StripeLri} so
 * controllers, models, requests, and support run from {@code app/StripeLri}, not vendor.
 */
final class ApplicationCodePublisher
{
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
        $destRoot = base_path('app/StripeLri');

        if ($this->files->isDirectory($destRoot) && ! $force) {
            $lines[] = 'Skipped copying app/StripeLri (already exists). Use --force to overwrite.';
        } else {
            if ($this->files->isDirectory($destRoot)) {
                $this->files->deleteDirectory($destRoot);
            }
            $this->publishPhpTree($packageRoot.'/src', $destRoot, $output);
            $lines[] = 'Published PHP sources to app/StripeLri.';
        }

        $migDest = database_path('migrations');
        $this->files->ensureDirectoryExists($migDest);
        $this->copyMigrationDirectory($packageRoot.'/database/migrations/core', $migDest, $force, $output);
        $lines[] = 'Published core migrations to database/migrations.';

        if ($includeCreditMigrations) {
            $this->copyMigrationDirectory($packageRoot.'/database/migrations/credits', $migDest, $force, $output);
            $lines[] = 'Published credit migrations to database/migrations.';
        }

        $routesPath = base_path('routes/stripe-lri.php');
        if ($this->files->exists($routesPath) && ! $force) {
            $lines[] = 'Skipped routes/stripe-lri.php (exists). Use --force to overwrite.';
        } else {
            $this->files->put($routesPath, $this->publishedRoutesFileContents());
            $lines[] = 'Published routes/stripe-lri.php.';
        }

        return $lines;
    }

    private function publishPhpTree(string $srcRoot, string $destRoot, ?OutputInterface $output): void
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

            $target = $destRoot.'/'.$relative;
            $this->files->ensureDirectoryExists(dirname($target));
            $transformed = $this->transformPhpSource($file->getContents());
            $this->files->put($target, $transformed);
            $output?->writeln(' <fg=gray>copy</> '.$relative, OutputInterface::VERBOSITY_VERBOSE);
        }
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

    private function transformPhpSource(string $content): string
    {
        $content = preg_replace('/^namespace\s+StripeLri\\\\/m', 'namespace App\\StripeLri\\', $content) ?? $content;
        $content = preg_replace('/^namespace\s+StripeLri\s*;/m', 'namespace App\\StripeLri;', $content) ?? $content;
        $content = str_replace('use StripeLri\\', 'use App\\StripeLri\\', $content);

        return $content;
    }

    private function publishedRoutesFileContents(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

/**
 * Stripe-LRI routes (published). Controllers live in app/StripeLri/Http/Controllers.
 * Route wiring uses the package {@see \StripeLri\Routing\StripeLriRouteRegistrar}.
 */
use StripeLri\Routing\StripeLriRouteRegistrar;

StripeLriRouteRegistrar::register('App\StripeLri\Http\Controllers');

PHP;
    }
}

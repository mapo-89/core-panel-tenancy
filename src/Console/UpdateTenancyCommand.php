<?php

declare(strict_types=1);

namespace CorePanelTenancy\Console;

use CorePanel\Support\Migrations\HostMigrationRunner;
use CorePanel\Support\PublishesCorePanelAssets;
use CorePanel\Support\Publishing\CorePanelPublisher;
use CorePanelTenancy\CorePanelTenancyServiceProvider;
use CorePanelTenancy\Support\Install\HandleInertiaRequestsTenancyMerger;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class UpdateTenancyCommand extends Command
{
    use PublishesCorePanelAssets;

    /**
     * @var list<string>
     */
    private const UPDATE_TAGS = [
        'core-panel-tenancy-core',
        'core-panel-tenancy-config',
        'core-panel-tenancy-migrations',
        'core-panel-tenancy-lang',
        'core-panel-tenancy-lang-vendor',
        'core-panel-tenancy-ui',
    ];

    /**
     * @var list<string>
     */
    private const REQUIRED_UPDATE_PATHS = [
        'database/migrations/tenancy/2026_01_01_000024_create_tenant_user_impersonation_tokens_table.php',
    ];

    protected $signature = 'core-panel:tenancy:update
        {--dry-run : Show planned changes without writing files}
        {--force : Overwrite published files after creating a backup}
        {--base-path= : Override the target base path}
        {--breaking-changes : Also refresh optional config files if package internals changed}';

    protected $description = 'Republish optional Tenancy addon assets after addon updates.';

    public function __construct(
        private readonly Filesystem $files,
        private readonly HostMigrationRunner $migrations,
        private readonly HandleInertiaRequestsTenancyMerger $handleInertiaRequestsTenancyMerger,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $basePath = is_string($this->option('base-path')) && $this->option('base-path') !== ''
            ? (string) $this->option('base-path')
            : null;
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $result = app(CorePanelPublisher::class)->updateForProvider(
            CorePanelTenancyServiceProvider::class,
            self::UPDATE_TAGS,
            $force,
            $dryRun,
            $basePath,
            adoptUnmanagedExisting: true,
            managedMissingPaths: $this->resolveRequiredUpdatePaths($basePath),
            recreateManagedMissing: false,
        );

        $this->table(
            ['Tag', 'Status', 'Reason', 'Destination'],
            array_map(
                static fn (array $change): array => [
                    'tag' => $change['tag'],
                    'status' => $change['status'],
                    'reason' => $change['reason'],
                    'destination' => $change['destination'],
                ],
                $result['changes'],
            ),
        );

        $this->components->info('Manifest: '.$result['manifestPath']);

        if ((bool) $this->option('breaking-changes')) {
            $this->warn('Breaking-change mode acknowledged for tenancy update. Review model/migration changes separately before applying.');
        }

        if ($dryRun) {
            return $this->containsConflicts($result['changes'])
                ? self::FAILURE
                : self::SUCCESS;
        }

        if ($this->containsConflicts($result['changes'])) {
            return self::FAILURE;
        }

        $this->ensureTenancyProviderRegistered($basePath);
        $this->handleInertiaRequestsTenancyMerger->merge($basePath);

        if ($basePath === null) {
            $this->migrations->run($this);
        } else {
            $this->components->warn('Skipping automatic migrations for external base-path tenancy updates. Run php artisan migrate in the target application manually.');
        }

        $this->generateWayfinderRoutes();

        return $this->containsConflicts($result['changes'])
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @param  list<array{tag:string,status:string,source:string,destination:string,reason:string}>  $changes
     */
    private function containsConflicts(array $changes): bool
    {
        foreach ($changes as $change) {
            if ($change['status'] === 'conflict') {
                return true;
            }
        }

        return false;
    }

    private function ensureTenancyProviderRegistered(?string $basePath): void
    {
        $root = $basePath ?? base_path();
        $providersPath = $root.'/bootstrap/providers.php';

        if (! $this->files->exists($providersPath)) {
            return;
        }

        $contents = (string) $this->files->get($providersPath);
        $import = "use App\\Providers\\TenancyServiceProvider;\n";
        $shortReference = 'TenancyServiceProvider::class';
        $qualifiedReference = 'App\\Providers\\TenancyServiceProvider::class';

        $updatedContents = $contents;

        if (str_contains($updatedContents, $qualifiedReference)) {
            if (! str_contains($updatedContents, $import)) {
                $updatedContents = $this->prependProviderImport($updatedContents, $import);
            }

            $updatedContents = str_replace($qualifiedReference, $shortReference, $updatedContents);
        } elseif (str_contains($contents, $shortReference)) {
            if (! str_contains($contents, $import)) {
                $updatedContents = $this->prependProviderImport($contents, $import);
            } else {
                return;
            }
        } else {
            if (! str_contains($updatedContents, $import)) {
                $updatedContents = $this->prependProviderImport($updatedContents, $import);
            }

            $updatedContents = str_replace(
                '];',
                "    {$shortReference},\n];",
                $updatedContents,
            );
        }

        if ($updatedContents !== $contents) {
            $this->files->put($providersPath, $updatedContents);
        }
    }

    private function prependProviderImport(string $contents, string $import): string
    {
        if (str_contains($contents, $import)) {
            return $contents;
        }

        if (preg_match('/^use [^;]+;\n/m', $contents) === 1) {
            return preg_replace('/^((?:use [^;]+;\n)+)/m', "$1{$import}", $contents, 1) ?? $contents;
        }

        return preg_replace(
            "/^(<\\?php\\n(?:\\ndeclare\\(strict_types=1\\);)?\\n)/",
            "$1{$import}\n",
            $contents,
            1,
        ) ?? $contents;
    }

    /**
     * @return list<string>
     */
    private function resolveRequiredUpdatePaths(?string $basePath): array
    {
        $root = $basePath ?? base_path();
        $migrationsRoot = $root.'/database/migrations';

        if (! $this->files->isDirectory($migrationsRoot)) {
            return self::REQUIRED_UPDATE_PATHS;
        }

        $existingBasenames = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($migrationsRoot, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $existingBasenames[$file->getFilename()] = true;
        }

        return array_filter(
            self::REQUIRED_UPDATE_PATHS,
            static fn (string $path): bool => ! isset($existingBasenames[basename($path)]),
        );
    }
}

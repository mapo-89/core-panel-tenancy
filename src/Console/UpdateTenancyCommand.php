<?php

declare(strict_types=1);

namespace CorePanelTenancy\Console;

use CorePanel\Support\Migrations\HostMigrationRunner;
use CorePanel\Support\Migrations\ManagedMigrationScaffoldMigrator;
use CorePanel\Support\PublishesCorePanelAssets;
use CorePanel\Support\Publishing\CorePanelPublisher;
use CorePanelTenancy\CorePanelTenancyServiceProvider;
use CorePanelTenancy\Support\Install\HandleInertiaRequestsTenancyMerger;
use CorePanelTenancy\Support\Install\InertiaPageResolverTenancyMerger;
use CorePanelTenancy\Support\Install\TenancyAdministrationPageMigrator;
use CorePanelTenancy\Support\Install\TenancyTsConfigMerger;
use CorePanelTenancy\Support\Install\ViteConfigTenancyMerger;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

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
    ];

    protected $signature = 'core-panel:tenancy:update
        {--dry-run : Show planned changes without writing files}
        {--force : Overwrite published files after creating a backup}
        {--base-path= : Override the target base path}
        {--breaking-changes : Apply documented breaking update paths, including managed migration relocation}';

    protected $description = 'Republish optional Tenancy addon assets after addon updates.';

    public function __construct(
        private readonly Filesystem $files,
        private readonly HostMigrationRunner $migrations,
        private readonly ManagedMigrationScaffoldMigrator $migrationScaffolds,
        private readonly HandleInertiaRequestsTenancyMerger $handleInertiaRequestsTenancyMerger,
        private readonly InertiaPageResolverTenancyMerger $inertiaPageResolverTenancyMerger,
        private readonly TenancyAdministrationPageMigrator $administrationPageMigrator,
        private readonly TenancyTsConfigMerger $tsConfigMerger,
        private readonly ViteConfigTenancyMerger $viteConfigTenancyMerger,
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
            recreateManagedMissing: false,
        );

        $administrationPageChange = $this->administrationPageMigrator->migrate($basePath, $dryRun);
        $result['changes'][] = $administrationPageChange;

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

        $withBreakingChanges = (bool) $this->option('breaking-changes');
        $migrationChanges = $this->migrationScaffolds->migrate(
            [
                ...ManagedMigrationScaffoldMigrator::coreTenantScaffolds(),
                ...ManagedMigrationScaffoldMigrator::tenancyHostScaffolds(),
            ],
            dryRun: $dryRun || ! $withBreakingChanges,
            basePath: $basePath,
        );

        if ($migrationChanges !== []) {
            $this->table(['Migration', 'Status', 'Reason'], $migrationChanges);
        }

        if (collect($migrationChanges)->contains('status', 'conflict')) {
            $this->components->error('CorePanel Tenancy cannot move customized unmanaged migrations into the packages automatically. Resolve the reported files and rerun the update.');

            return self::FAILURE;
        }

        if (! $withBreakingChanges && $migrationChanges !== [] && ! $dryRun) {
            $this->components->error('CorePanel 1.6 moves tenancy domain migrations into packages. Rerun core-panel:tenancy:update with --breaking-changes to back up and remove the managed host copies safely.');

            return self::FAILURE;
        }

        if (! $dryRun && $administrationPageChange['status'] === 'kept') {
            $this->components->warn('Keeping the host Administration page. It overrides the tenancy backup UI until it is removed or updated by the host application.');
        }

        if ($withBreakingChanges) {
            $this->warn('Breaking-change mode enabled: managed domain migration copies are moved from the host into their packages.');
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
        $this->inertiaPageResolverTenancyMerger->merge($basePath);
        $this->tsConfigMerger->merge($basePath);
        $this->viteConfigTenancyMerger->merge($basePath);

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
        $lineEnding = $this->detectLineEnding($contents);
        $import = "use App\\Providers\\TenancyServiceProvider;{$lineEnding}";
        $shortReference = 'TenancyServiceProvider::class';
        $qualifiedReference = 'App\\Providers\\TenancyServiceProvider::class';
        $hasTenancyProviderImport = $this->hasTenancyProviderImport($contents);
        $hasQualifiedTenancyProviderRegistration = $this->hasTenancyProviderRegistration($contents, true);
        $hasShortTenancyProviderRegistration = $this->hasTenancyProviderRegistration($contents, false);

        $updatedContents = $contents;

        if ($hasQualifiedTenancyProviderRegistration) {
            if (! $hasTenancyProviderImport) {
                $updatedContents = $this->prependProviderImport($updatedContents, $import);
            }

            $updatedContents = preg_replace(
                '/\\\\?App\\\\Providers\\\\TenancyServiceProvider::class/',
                $shortReference,
                $updatedContents,
            ) ?? $updatedContents;
        } elseif ($hasShortTenancyProviderRegistration) {
            if (! $hasTenancyProviderImport) {
                $updatedContents = $this->prependProviderImport($contents, $import);
            } else {
                return;
            }
        } else {
            if (! $hasTenancyProviderImport) {
                $updatedContents = $this->prependProviderImport($updatedContents, $import);
            }

            $updatedContents = str_replace(
                '];',
                "    {$shortReference},{$lineEnding}];",
                $updatedContents,
            );
        }

        if ($updatedContents !== $contents) {
            $this->files->put($providersPath, $updatedContents);
        }
    }

    private function prependProviderImport(string $contents, string $import): string
    {
        if ($this->hasTenancyProviderImport($contents)) {
            return $contents;
        }

        $lineEnding = $this->detectLineEnding($contents);

        if (preg_match('/^use [^;]+;\R/m', $contents) === 1) {
            return preg_replace('/^((?:use [^;]+;\R)+)/m', "$1{$import}", $contents, 1) ?? $contents;
        }

        if (preg_match('/^return\s+\[/m', $contents) === 1) {
            return preg_replace(
                '/^return\s+\[/m',
                "{$import}{$lineEnding}return [",
                $contents,
                1,
            ) ?? $contents;
        }

        return preg_replace('/^<\\?php\R?/', "<?php{$lineEnding}{$lineEnding}{$import}{$lineEnding}", $contents, 1) ?? $contents;
    }

    private function hasTenancyProviderImport(string $contents): bool
    {
        return preg_match('/^\s*use\s+App\\\\Providers\\\\TenancyServiceProvider\s*;\s*(?:(?:\/\/|#).*)?$/m', $contents) === 1;
    }

    private function hasTenancyProviderRegistration(string $contents, bool $qualified): bool
    {
        $provider = $qualified
            ? '\\\\?App\\\\Providers\\\\TenancyServiceProvider::class'
            : 'TenancyServiceProvider::class';
        $commentStrippedContents = $this->stripPhpComments($contents);

        return preg_match(
            '/(?:^|[\[,]\s*)'.$provider.'(?=\s*(?:,|\]))/m',
            $commentStrippedContents,
        ) === 1;
    }

    private function stripPhpComments(string $contents): string
    {
        $tokens = token_get_all($contents);
        $stripped = '';

        foreach ($tokens as $token) {
            if (! is_array($token)) {
                $stripped .= $token;

                continue;
            }

            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $stripped .= $token[1];
        }

        return $stripped;
    }

    private function detectLineEnding(string $contents): string
    {
        if (str_contains($contents, "\r\n")) {
            return "\r\n";
        }

        if (str_contains($contents, "\r")) {
            return "\r";
        }

        return "\n";
    }
}

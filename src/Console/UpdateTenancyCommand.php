<?php

declare(strict_types=1);

namespace CorePanelTenancy\Console;

use CorePanel\Support\Migrations\CorePanelHostMigrationRunner;
use CorePanel\Support\PublishesCorePanelAssets;
use CorePanel\Support\Publishing\CorePanelPublisher;
use CorePanelTenancy\CorePanelTenancyServiceProvider;
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
        'core-panel-tenancy-ui',
    ];

    protected $signature = 'core-panel:tenancy:update
        {--dry-run : Show planned changes without writing files}
        {--force : Overwrite published files after creating a backup}
        {--base-path= : Override the target base path}
        {--breaking-changes : Also refresh optional config files if package internals changed}';

    protected $description = 'Republish optional Tenancy addon assets after addon updates.';

    public function __construct(
        private readonly Filesystem $files,
        private readonly CorePanelHostMigrationRunner $migrations,
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

        if (str_contains($contents, 'App\\Providers\\TenancyServiceProvider::class')) {
            return;
        }

        $updatedContents = str_replace(
            '];',
            "    App\\Providers\\TenancyServiceProvider::class,\n];",
            $contents,
        );

        if ($updatedContents !== $contents) {
            $this->files->put($providersPath, $updatedContents);
        }
    }
}

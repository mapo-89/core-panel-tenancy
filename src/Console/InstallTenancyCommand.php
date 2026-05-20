<?php

declare(strict_types=1);

namespace CorePanelTenancy\Console;

use CorePanel\Support\SynchronizesEnvironmentFile;
use CorePanelTenancy\Support\Install\AppServiceProviderTenancyMerger;
use CorePanelTenancy\Support\Install\CorePanelTypesTenancyMerger;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

final class InstallTenancyCommand extends Command
{
    protected $signature = 'core-panel:tenancy:install
        {--force : Overwrite published tenancy resources}
        {--migrate : Run database migrations after publishing}';

    protected $description = 'Install the stancl tenancy baseline for the optional CorePanel tenancy addon.';

    public function __construct(
        private readonly Filesystem $files,
        private readonly SynchronizesEnvironmentFile $environment,
        private readonly AppServiceProviderTenancyMerger $appServiceProviderTenancyMerger,
        private readonly CorePanelTypesTenancyMerger $corePanelTypesTenancyMerger,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        foreach ([
            'core-panel-tenancy-core',
            'core-panel-tenancy-config',
            'core-panel-tenancy-migrations',
            'core-panel-tenancy-lang',
            'core-panel-tenancy-ui',
        ] as $tag) {
            $this->call('vendor:publish', [
                '--provider' => 'CorePanelTenancy\\CorePanelTenancyServiceProvider',
                '--tag' => $tag,
                '--force' => (bool) $this->option('force'),
            ]);
        }

        $this->removeObsoletePublishedFiles();
        $this->ensureTenancyProviderRegistered();
        $this->appServiceProviderTenancyMerger->merge();
        $this->corePanelTypesTenancyMerger->merge();
        $this->synchronizeTenancyEnvironment();

        if ((bool) $this->option('migrate')) {
            $this->call('migrate', ['--force' => true]);
        }

        $this->components->info('CorePanel tenancy addon resources published.');

        return self::SUCCESS;
    }

    private function removeObsoletePublishedFiles(): void
    {
        foreach ([
            database_path('migrations/2026_01_01_000021_add_data_column_to_existing_tenants_table.php'),
            resource_path('js/routes/core-panel/tenants.ts'),
            app_path('Http/Middleware/InitializeTenancyFromRequestDomain.php'),
        ] as $path) {
            if ($this->files->exists($path)) {
                $this->files->delete($path);
            }
        }
    }

    private function ensureTenancyProviderRegistered(): void
    {
        $providersPath = base_path('bootstrap/providers.php');

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

    private function synchronizeTenancyEnvironment(): void
    {
        $appUrl = (string) config('app.url', 'http://localhost');
        $defaultCentralDomain = (string) (parse_url($appUrl, PHP_URL_HOST) ?: 'localhost');
        $environmentCentralDomains = $this->normalizeDomains(
            explode(',', (string) config('tenancy.central_domains_env', '')),
        );
        $configuredCentralDomains = $this->normalizeDomains(
            (array) config('tenancy.central_domains', []),
        );
        $centralDomains = $this->hasStanclDefaultCentralDomains($configuredCentralDomains)
            ? []
            : $configuredCentralDomains;

        if ($centralDomains === []) {
            $centralDomains = $this->hasStanclDefaultCentralDomains($environmentCentralDomains)
                ? []
                : $environmentCentralDomains;
        }

        if ($centralDomains === []) {
            $centralDomains = [$defaultCentralDomain];
        }

        $configuredCentralConnection = trim((string) config('tenancy.database.central_connection_env', ''));
        $configuredDatabaseConnection = trim((string) config('database.default_env', ''));
        $defaultConnection = $configuredCentralConnection !== ''
            ? $configuredCentralConnection
            : ($configuredDatabaseConnection !== ''
                ? $configuredDatabaseConnection
                : (string) config('database.default', 'pgsql'));

        $this->environment->sync(overrides: [
            'CENTRAL_DOMAINS' => implode(',', $centralDomains),
            'TENANCY_CENTRAL_CONNECTION' => $defaultConnection,
            'TENANCY_TEMPLATE_TENANT_CONNECTION' => '',
            'TENANCY_DATABASE_PREFIX' => 'tenant_',
            'TENANCY_DATABASE_SUFFIX' => '',
        ]);
    }

    /**
     * @param  array<int, mixed>  $domains
     * @return list<string>
     */
    private function normalizeDomains(array $domains): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (mixed $domain): string => $this->normalizeDomainValue((string) $domain),
            $domains,
        ))));
    }

    private function normalizeDomainValue(string $domain): string
    {
        $normalized = trim($domain);

        if ($normalized === '') {
            return '';
        }

        $candidate = str_contains($normalized, '://') || str_starts_with($normalized, '//')
            ? $normalized
            : 'https://'.$normalized;
        $host = parse_url($candidate, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : $normalized;
    }

    /**
     * @param  list<string>  $domains
     */
    private function hasStanclDefaultCentralDomains(array $domains): bool
    {
        sort($domains);

        return $domains === ['127.0.0.1', 'localhost'];
    }
}

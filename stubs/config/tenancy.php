<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Support\Str;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\Features\UniversalRoutes;
use Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager;
use Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLDatabaseManager;
use Stancl\Tenancy\TenantDatabaseManagers\SQLiteDatabaseManager;
use Stancl\Tenancy\UUIDGenerator;

$defaultCentralDomain = parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost';
$configuredCentralDomains = array_values(array_filter(array_map(
    static fn (string $domain): string => trim($domain),
    explode(',', (string) env('CENTRAL_DOMAINS', $defaultCentralDomain)),
)));
$templateTenantConnection = trim((string) env('TENANCY_TEMPLATE_TENANT_CONNECTION', ''));

return [
    'central_domains_env' => env('CENTRAL_DOMAINS', ''),
    'tenant_model' => Tenant::class,
    'id_generator' => UUIDGenerator::class,
    'domain_model' => Domain::class,
    'central_domains' => [
        ...$configuredCentralDomains,
    ],
    'bootstrappers' => [
        DatabaseTenancyBootstrapper::class,
        FilesystemTenancyBootstrapper::class,
        QueueTenancyBootstrapper::class,
    ],
    'database' => [
        'central_connection_env' => env('TENANCY_CENTRAL_CONNECTION', ''),
        'central_connection' => env('TENANCY_CENTRAL_CONNECTION', env('DB_CONNECTION', 'pgsql')),
        'template_tenant_connection' => $templateTenantConnection !== ''
            ? $templateTenantConnection
            : env('TENANCY_CENTRAL_CONNECTION', env('DB_CONNECTION', 'pgsql')),
        'prefix' => env('TENANCY_DATABASE_PREFIX', 'tenant_'),
        'suffix' => env('TENANCY_DATABASE_SUFFIX', ''),
        'managers' => [
            'sqlite' => SQLiteDatabaseManager::class,
            'mysql' => MySQLDatabaseManager::class,
            'mariadb' => MySQLDatabaseManager::class,
            'pgsql' => PostgreSQLDatabaseManager::class,
        ],
    ],
    'cache' => [
        'tag_base' => Str::slug((string) env('APP_NAME', 'core-panel')).'-tenant',
    ],
    'filesystem' => [
        'suffix_base' => 'tenant',
        'disks' => [
            'local',
            'public',
        ],
        'root_override' => [
            'local' => '%storage_path%/app/',
            'public' => '%storage_path%/app/public/',
        ],
        'suffix_storage_path' => true,
        'asset_helper_tenancy' => false,
    ],
    'redis' => [
        'prefix_base' => 'tenant',
        'prefixed_connections' => [],
    ],
    'features' => [
        UniversalRoutes::class,
    ],
    'routes' => true,
    'migration_parameters' => [
        '--force' => true,
        '--path' => [database_path('migrations/tenant')],
        '--realpath' => true,
    ],
    'seeder_parameters' => [
        '--class' => 'Database\\Seeders\\Tenant\\TenantDatabaseSeeder',
    ],
];

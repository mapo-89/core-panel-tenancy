<?php

declare(strict_types=1);

namespace CorePanelTenancy\Support\Administration\DatabaseBackups;

use CorePanel\Support\Administration\DatabaseBackups\DatabaseBackupRestoreService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final readonly class TenancyDatabaseBackupRestoreService
{
    public function __construct(
        private DatabaseBackupRestoreService $coreRestoreService,
        private TenancyDatabaseBackupService $backups,
        private TenancyDatabaseBackupTenancySupport $tenancy,
    ) {}

    /**
     * @param  list<string>  $tables
     * @return list<string>
     */
    public function expandTablesWithDependencies(array $tables): array
    {
        return $this->coreRestoreService->expandTablesWithDependencies($tables);
    }

    /**
     * @return list<string>
     */
    public function supportedModes(): array
    {
        return $this->coreRestoreService->supportedModes();
    }

    public function supportsRestore(): bool
    {
        return $this->coreRestoreService->supportsRestore();
    }

    public function supportsSelectiveRestore(): bool
    {
        return $this->coreRestoreService->supportsSelectiveRestore();
    }

    /**
     * @return list<array{dependencies: list<string>, label: string, value: string}>
     */
    public function tableOptions(): array
    {
        return $this->coreRestoreService->tableOptions();
    }

    /**
     * @return list<array{dependencies: list<string>, label: string, value: string}>
     */
    public function tenantTableOptions(): array
    {
        if (! $this->supportsSelectiveRestore()) {
            return [];
        }

        $tenant = $this->tenancy->tenants()->first();

        if (! is_object($tenant)) {
            return [];
        }

        return $this->tenancy->runForTenant(
            $tenant,
            fn (): array => $this->coreRestoreService->tableOptions(),
        );
    }

    /**
     * @param  list<string>  $tables
     */
    public function restore(string $backup, string $mode, array $tables = []): void
    {
        $descriptor = $this->backups->find($backup);

        if (! $descriptor instanceof TenancyDatabaseBackupFile || $descriptor->kind !== 'set') {
            $this->coreRestoreService->restore($backup, $mode, $tables);

            return;
        }

        $archive = $this->backups->extractArchive($backup);

        try {
            if ($mode === 'central_only') {
                $this->restoreConnection($archive->directory.'/central.dump', $this->tenancy->centralConnectionName());

                return;
            }

            if ($mode === 'central_tables') {
                $this->restoreCentralTablesFromArchive($archive, $tables);

                return;
            }

            if ($mode === 'single_tenant') {
                $tenantId = $tables[0] ?? null;

                if (! is_string($tenantId) || $tenantId === '') {
                    throw new RuntimeException('A tenant must be selected for tenant restore.');
                }

                $this->restoreTenantFromArchive($archive, $tenantId);

                return;
            }

            if ($mode === 'tenant_tables') {
                $tenantId = $tables[0] ?? null;

                if (! is_string($tenantId) || $tenantId === '') {
                    throw new RuntimeException('A tenant must be selected for partial tenant restore.');
                }

                $this->restoreTenantTablesFromArchive($archive, $tenantId, array_slice($tables, 1));

                return;
            }

            if ($mode === 'full_set') {
                $this->restoreConnection($archive->directory.'/central.dump', $this->tenancy->centralConnectionName());

                foreach ((array) ($archive->manifest['tenants'] ?? []) as $tenantEntry) {
                    $tenantId = (string) ($tenantEntry['id'] ?? '');

                    if ($tenantId === '') {
                        continue;
                    }

                    $this->restoreTenantFromArchive($archive, $tenantId);
                }

                return;
            }

            throw new RuntimeException('Unsupported restore mode.');
        } finally {
            $this->backups->cleanupExtractedArchive($archive);
        }
    }

    private function restoreTenantFromArchive(TenancyDatabaseBackupArchive $archive, string $tenantId): void
    {
        $tenant = $this->tenancy->findTenant($tenantId);

        if (! is_object($tenant)) {
            throw new RuntimeException("Tenant [{$tenantId}] could not be found for restore.");
        }

        $tenantEntry = collect((array) ($archive->manifest['tenants'] ?? []))
            ->first(fn (array $entry): bool => (string) ($entry['id'] ?? '') === $tenantId);

        if (! is_array($tenantEntry) || ! is_string($tenantEntry['file'] ?? null)) {
            throw new RuntimeException("Tenant backup payload for [{$tenantId}] is missing.");
        }

        $this->tenancy->runForTenant($tenant, function () use ($archive, $tenantEntry): void {
            $this->restoreConnection($archive->directory.'/'.$tenantEntry['file'], 'tenant');
        });
    }

    /**
     * @param  list<string>  $tables
     */
    private function restoreTenantTablesFromArchive(TenancyDatabaseBackupArchive $archive, string $tenantId, array $tables): void
    {
        if (! $this->supportsSelectiveRestore()) {
            throw new RuntimeException('Partial restore is currently only available for PostgreSQL backups.');
        }

        if ($tables === []) {
            throw new RuntimeException('At least one table must be selected for partial tenant restore.');
        }

        $tenant = $this->tenancy->findTenant($tenantId);

        if (! is_object($tenant)) {
            throw new RuntimeException("Tenant [{$tenantId}] could not be found for restore.");
        }

        $tenantEntry = collect((array) ($archive->manifest['tenants'] ?? []))
            ->first(fn (array $entry): bool => (string) ($entry['id'] ?? '') === $tenantId);

        if (! is_array($tenantEntry) || ! is_string($tenantEntry['file'] ?? null)) {
            throw new RuntimeException("Tenant backup payload for [{$tenantId}] is missing.");
        }

        $temporaryBackupName = 'core-panel-tenant-set-'.bin2hex(random_bytes(8)).'.dump';
        $temporaryBackupPath = $this->backups->ensureDirectoryExists().DIRECTORY_SEPARATOR.$temporaryBackupName;

        File::copy($archive->directory.'/'.$tenantEntry['file'], $temporaryBackupPath);

        try {
            $this->tenancy->runForTenant($tenant, function () use ($temporaryBackupName, $tables): void {
                $this->coreRestoreService->restore($temporaryBackupName, 'tables', $tables);
            });
        } finally {
            File::delete($temporaryBackupPath);
        }
    }

    /**
     * @param  list<string>  $tables
     */
    private function restoreCentralTablesFromArchive(TenancyDatabaseBackupArchive $archive, array $tables): void
    {
        if (! $this->supportsSelectiveRestore()) {
            throw new RuntimeException('Partial restore is currently only available for PostgreSQL backups.');
        }

        if ($tables === []) {
            throw new RuntimeException('At least one table must be selected for partial restore.');
        }

        $temporaryBackupName = 'core-panel-central-set-'.bin2hex(random_bytes(8)).'.dump';
        $temporaryBackupPath = $this->backups->ensureDirectoryExists().DIRECTORY_SEPARATOR.$temporaryBackupName;

        File::copy($archive->directory.'/central.dump', $temporaryBackupPath);

        try {
            $this->coreRestoreService->restore($temporaryBackupName, 'tables', $tables);
        } finally {
            File::delete($temporaryBackupPath);
        }
    }

    private function restoreConnection(string $path, string $connectionName): void
    {
        $connection = config('database.connections.'.$connectionName);

        if (! is_array($connection)) {
            throw new RuntimeException('Database connection is not configured.');
        }

        $driver = (string) ($connection['driver'] ?? '');

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->runCommand([
                'mysql',
                '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
                '--port='.(string) ($connection['port'] ?? '3306'),
                '--user='.(string) ($connection['username'] ?? ''),
                '--database='.(string) ($connection['database'] ?? ''),
                '--execute=source '.$path,
            ], ['MYSQL_PWD' => (string) ($connection['password'] ?? '')]);

            return;
        }

        if ($driver !== 'pgsql') {
            throw new RuntimeException('Tenant backup restore currently supports pgsql and mysql connections only.');
        }

        $this->runCommand([
            'pg_restore',
            '--clean',
            '--if-exists',
            '--exit-on-error',
            '--no-owner',
            '--no-acl',
            '--single-transaction',
            '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
            '--port='.(string) ($connection['port'] ?? '5432'),
            '--username='.(string) ($connection['username'] ?? ''),
            '--dbname='.(string) ($connection['database'] ?? ''),
            $path,
        ], ['PGPASSWORD' => (string) ($connection['password'] ?? '')]);
    }

    /**
     * @param  array<string, string>  $env
     * @param  list<string>  $command
     */
    private function runCommand(array $command, array $env): void
    {
        $result = Process::timeout((int) config('core-panel.administration.database_backups.restore_timeout', 900))
            ->env($env)
            ->run($command);

        if (! $result->successful()) {
            throw new RuntimeException(trim($result->errorOutput()) ?: 'Database restore failed.');
        }
    }
}

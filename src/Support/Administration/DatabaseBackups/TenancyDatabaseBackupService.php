<?php

declare(strict_types=1);

namespace CorePanelTenancy\Support\Administration\DatabaseBackups;

use CorePanel\Support\Administration\DatabaseBackups\DatabaseBackupEncryptor;
use CorePanel\Support\Administration\DatabaseBackups\DatabaseBackupFile;
use CorePanel\Support\Administration\DatabaseBackups\DatabaseBackupService;
use CorePanel\Support\Administration\DatabaseBackups\DatabaseBackupSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;
use ZipArchive;

final class TenancyDatabaseBackupService
{
    public function __construct(
        private readonly DatabaseBackupEncryptor $encryptor,
        private readonly DatabaseBackupSettings $coreSettings,
        private readonly TenancyDatabaseBackupTenancySupport $tenancy,
        private readonly DatabaseBackupService $coreBackups,
    ) {}

    public function enabled(): bool
    {
        return $this->coreBackups->enabled();
    }

    public function exists(string $name): bool
    {
        return File::isFile($this->pathFor($name));
    }

    public function delete(string $name): void
    {
        $path = $this->pathFor($name);

        if (File::isFile($path)) {
            File::delete($path);
        }
    }

    /**
     * @return Collection<int, DatabaseBackupFile|TenancyDatabaseBackupFile>
     */
    public function list(): Collection
    {
        $directory = $this->ensureDirectoryExists();

        return collect(File::files($directory))
            ->filter(
                fn (\SplFileInfo $file): bool => str_ends_with($file->getFilename(), '.dump')
                    || str_ends_with($file->getFilename(), '.dump.enc')
                    || str_ends_with($file->getFilename(), '.zip')
                    || str_ends_with($file->getFilename(), '.zip.enc'),
            )
            ->map(fn (\SplFileInfo $file): DatabaseBackupFile|TenancyDatabaseBackupFile => $this->fileFromPath($file->getPathname()))
            ->sortByDesc(fn (DatabaseBackupFile|TenancyDatabaseBackupFile $file): int => $file->createdAt->getTimestamp())
            ->values();
    }

    public function find(string $name): DatabaseBackupFile|TenancyDatabaseBackupFile
    {
        return $this->fileFromPath($this->pathFor($name));
    }

    public function pathFor(string $name): string
    {
        $name = basename($name);

        if (preg_match('/^[A-Za-z0-9_.-]+\.(?:dump|zip)(?:\.enc)?$/', $name) !== 1) {
            throw new RuntimeException('Invalid backup name.');
        }

        return $this->ensureDirectoryExists().DIRECTORY_SEPARATOR.$name;
    }

    public function create(string $suffix = 'manual', string $scope = 'central_only'): DatabaseBackupFile|TenancyDatabaseBackupFile
    {
        if ($scope === 'full_set') {
            return $this->createBackupSet($suffix);
        }

        return $this->coreBackups->create($suffix);
    }

    public function importUploaded(UploadedFile $file): DatabaseBackupFile|TenancyDatabaseBackupFile
    {
        $this->ensureDirectoryExists();

        $name = mb_strtolower($file->getClientOriginalName());
        $encrypted = str_ends_with($name, '.enc');
        $extension = match (true) {
            str_ends_with($name, '.dump'), str_ends_with($name, '.dump.enc') => 'dump',
            str_ends_with($name, '.zip'), str_ends_with($name, '.zip.enc') => 'zip',
            default => throw new RuntimeException('Unsupported backup import format.'),
        };

        $importedName = $extension === 'zip'
            ? $this->makeImportedSetName($encrypted)
            : $this->makeImportedDumpName($encrypted);
        $targetPath = $this->pathFor($importedName);

        try {
            $file->move($this->ensureDirectoryExists(), $importedName);
            clearstatcache(true, $targetPath);

            $backup = $this->fileFromPath($targetPath);
            $this->enforceRetention();

            return $backup;
        } catch (Throwable $throwable) {
            File::delete($targetPath);

            throw $throwable;
        }
    }

    public function ensureDirectoryExists(): string
    {
        $configuredPath = trim((string) config('core-panel.administration.database_backups.path', ''));
        $directory = $configuredPath !== '' ? $configuredPath : storage_path('app/backups/database');

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0750, true);
        }

        return $directory;
    }

    public function makeSetName(string $suffix = 'manual'): string
    {
        $database = preg_replace(
            '/[^A-Za-z0-9_.-]+/',
            '-',
            (string) config('database.connections.'.$this->tenancy->centralConnectionName().'.database', 'database'),
        );
        $timestamp = now()->format('Y-m-d_H-i-s');
        $normalizedSuffix = trim((string) preg_replace('/[^A-Za-z0-9_.-]+/', '-', $suffix), '-');

        return "{$database}-{$timestamp}-{$normalizedSuffix}.zip";
    }

    /**
     * @return list<string>
     */
    public function availableBackupScopes(): array
    {
        return ['central_only', 'full_set'];
    }

    public function supportsBackupScope(string $scope): bool
    {
        return in_array($scope, ['central_only', 'full_set'], true);
    }

    public function extractArchive(string $name): TenancyDatabaseBackupArchive
    {
        $path = $this->pathFor($name);
        $workingPath = $path;
        $temporaryArchivePath = null;
        $directory = storage_path('framework/cache/database-backup-set-'.bin2hex(random_bytes(8)));

        File::ensureDirectoryExists($directory);

        if ($this->encryptor->isEncrypted($path)) {
            $temporaryArchivePath = storage_path('framework/cache/database-backup-set-'.bin2hex(random_bytes(8)).'.zip');
            File::ensureDirectoryExists(dirname($temporaryArchivePath));
            $this->encryptor->decryptFileWithCodes($path, $temporaryArchivePath, $this->coreSettings->encryptionCodes());
            $workingPath = $temporaryArchivePath;
        }

        try {
            $zip = new ZipArchive;
            $result = $zip->open($workingPath);

            if ($result !== true) {
                throw new RuntimeException('Database backup archive could not be opened.');
            }

            try {
                $this->validateArchiveEntries($zip);

                $manifestContents = $zip->getFromName('manifest.json');

                if (! is_string($manifestContents) || trim($manifestContents) === '') {
                    throw new RuntimeException('Database backup archive manifest is missing.');
                }

                /** @var array<string, mixed> $manifest */
                $manifest = json_decode($manifestContents, true, 512, JSON_THROW_ON_ERROR);

                if (! $zip->extractTo($directory)) {
                    throw new RuntimeException('Database backup archive could not be extracted.');
                }

                return new TenancyDatabaseBackupArchive($directory, $manifest);
            } finally {
                $zip->close();
            }
        } catch (Throwable $throwable) {
            File::deleteDirectory($directory);

            throw $throwable;
        } finally {
            if ($temporaryArchivePath !== null) {
                File::delete($temporaryArchivePath);
            }
        }
    }

    public function cleanupExtractedArchive(TenancyDatabaseBackupArchive $archive): void
    {
        File::deleteDirectory($archive->directory);
    }

    private function createBackupSet(string $suffix): TenancyDatabaseBackupFile
    {
        if (! $this->tenancy->enabledForBackups()) {
            throw new RuntimeException('Tenant backups are not available.');
        }

        $rawName = $this->makeSetName($suffix);
        $archiveName = $this->coreSettings->encryptionEnabled() ? $rawName.'.enc' : $rawName;
        $archivePath = $this->pathFor($archiveName);
        $workingArchivePath = $this->coreSettings->encryptionEnabled()
            ? $this->pathFor($rawName)
            : $archivePath;
        $workspace = storage_path('framework/cache/database-backup-set-'.bin2hex(random_bytes(8)));

        File::ensureDirectoryExists($workspace.'/tenants');

        $manifest = [
            'contains_tenants' => true,
            'created_at' => now()->toIso8601String(),
            'failed_tenants' => [],
            'kind' => 'set',
            'source' => $suffix,
            'central' => [
                'file' => 'central.dump',
                'sql_file' => 'central.sql',
            ],
            'tenants' => [],
            'version' => 1,
        ];

        try {
            $this->dumpConnection(
                $this->tenancy->centralConnectionName(),
                $workspace.'/central.dump',
                $workspace.'/central.sql',
            );

            foreach ($this->tenancy->tenants() as $tenant) {
                $tenantId = $this->tenancy->tenantId($tenant);
                $tenantLabel = $this->tenancy->tenantLabel($tenant);
                $relativePath = $this->tenantDumpRelativePath($tenantId);
                $sqlRelativePath = str_replace('.dump', '.sql', $relativePath);
                $absolutePath = $workspace.'/'.$relativePath;
                $sqlAbsolutePath = $workspace.'/'.$sqlRelativePath;

                try {
                    $this->tenancy->runForTenant($tenant, function () use ($absolutePath, $sqlAbsolutePath): void {
                        $this->dumpConnection('tenant', $absolutePath, $sqlAbsolutePath);
                    });

                    $manifest['tenants'][] = [
                        'file' => $relativePath,
                        'id' => $tenantId,
                        'label' => $tenantLabel,
                        'sql_file' => $sqlRelativePath,
                    ];
                } catch (Throwable $throwable) {
                    report($throwable);

                    $manifest['failed_tenants'][] = [
                        'id' => $tenantId,
                        'label' => $tenantLabel,
                        'message' => $throwable->getMessage(),
                    ];
                }
            }

            File::put(
                $workspace.'/manifest.json',
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            );

            $zip = new ZipArchive;
            $result = $zip->open($workingArchivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

            if ($result !== true) {
                throw new RuntimeException('Database backup archive could not be created.');
            }

            $zip->addFile($workspace.'/manifest.json', 'manifest.json');
            $zip->addFile($workspace.'/central.dump', 'central.dump');
            $zip->addFile($workspace.'/central.sql', 'central.sql');

            foreach ($manifest['tenants'] as $tenantEntry) {
                $zip->addFile($workspace.'/'.$tenantEntry['file'], $tenantEntry['file']);
                $zip->addFile($workspace.'/'.$tenantEntry['sql_file'], $tenantEntry['sql_file']);
            }

            $zip->close();

            if ($this->coreSettings->encryptionEnabled()) {
                $this->encryptor->encryptFile($workingArchivePath, $archivePath, $this->coreSettings->encryptionCode());
                File::delete($workingArchivePath);
            }

            $this->enforceRetention();

            /** @var TenancyDatabaseBackupFile $backup */
            $backup = $this->fileFromPath($archivePath);

            return $backup;
        } catch (Throwable $throwable) {
            File::delete([$archivePath, $workingArchivePath]);

            throw $throwable;
        } finally {
            File::deleteDirectory($workspace);
        }
    }

    private function dumpConnection(string $connectionName, string $dumpPath, string $sqlPath): void
    {
        $connection = config('database.connections.'.$connectionName);

        if (! is_array($connection)) {
            throw new RuntimeException('Database connection is not configured.');
        }

        $driver = (string) ($connection['driver'] ?? '');
        $database = (string) ($connection['database'] ?? '');

        if ($database === '') {
            throw new RuntimeException('Database name is not configured.');
        }

        if ($driver === 'sqlite') {
            File::delete($dumpPath);
            File::copy($database, $dumpPath);
            $this->exportSql($driver, $dumpPath, $sqlPath);

            return;
        }

        $result = match ($driver) {
            'pgsql' => Process::timeout((int) config('core-panel.administration.database_backups.timeout', 900))
                ->env(['PGPASSWORD' => (string) ($connection['password'] ?? '')])
                ->run([
                    'pg_dump',
                    '--format=custom',
                    '--no-owner',
                    '--no-acl',
                    '--file='.$dumpPath,
                    '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
                    '--port='.(string) ($connection['port'] ?? '5432'),
                    '--username='.(string) ($connection['username'] ?? ''),
                    $database,
                ]),
            'mariadb', 'mysql' => Process::timeout((int) config('core-panel.administration.database_backups.timeout', 900))
                ->env(['MYSQL_PWD' => (string) ($connection['password'] ?? '')])
                ->run([
                    'mysqldump',
                    '--result-file='.$dumpPath,
                    '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
                    '--port='.(string) ($connection['port'] ?? '3306'),
                    '--user='.(string) ($connection['username'] ?? ''),
                    $database,
                ]),
            default => throw new RuntimeException('Database backups currently support sqlite, pgsql and mysql connections only.'),
        };

        if (! $result->successful()) {
            File::delete($dumpPath);

            throw new RuntimeException(trim($result->errorOutput()) ?: 'Database backup failed.');
        }

        $this->exportSql($driver, $dumpPath, $sqlPath);
    }

    private function exportSql(string $driver, string $dumpPath, string $sqlPath): void
    {
        if (in_array($driver, ['mariadb', 'mysql'], true)) {
            File::copy($dumpPath, $sqlPath);

            return;
        }

        $result = $driver === 'sqlite'
            ? Process::timeout((int) config('core-panel.administration.database_backups.timeout', 900))
                ->run(['sqlite3', $dumpPath, '.dump'])
            : Process::timeout((int) config('core-panel.administration.database_backups.timeout', 900))
                ->run([
                    'pg_restore',
                    '--no-owner',
                    '--no-acl',
                    '--file='.$sqlPath,
                    $dumpPath,
                ]);

        if (! $result->successful()) {
            File::delete($sqlPath);

            throw new RuntimeException(trim($result->errorOutput()) ?: 'Database backup SQL export failed.');
        }

        if ($driver === 'sqlite') {
            File::put($sqlPath, $result->output());
        }
    }

    private function validateArchiveEntries(ZipArchive $zip): void
    {
        $totalSize = 0;
        $maximumSize = $this->maximumImportSizeBytes();

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->statIndex($index);

            if ($entry === false) {
                throw new RuntimeException('Database backup archive contains an invalid entry.');
            }

            $name = $entry['name'];

            if (
                str_contains($name, "\0")
                || str_starts_with($name, '/')
                || str_contains($name, '\\')
                || in_array('..', explode('/', $name), true)
            ) {
                throw new RuntimeException('Database backup archive contains an unsafe entry path.');
            }

            if ($entry['size'] > $maximumSize - $totalSize) {
                throw new RuntimeException('Database backup archive exceeds the configured import size limit.');
            }

            $totalSize += $entry['size'];
        }
    }

    private function maximumImportSizeBytes(): int
    {
        return max(1, (int) config('core-panel.administration.database_backups.import_max_size_kb', 1048576)) * 1024;
    }

    private function fileFromPath(string $path): DatabaseBackupFile|TenancyDatabaseBackupFile
    {
        $name = basename($path);

        if (str_ends_with($name, '.zip') || str_ends_with($name, '.zip.enc')) {
            $archive = $this->extractArchive($name);

            try {
                $manifest = $archive->manifest;
                $tenantOptions = collect((array) ($manifest['tenants'] ?? []))
                    ->map(fn (array $tenant): array => [
                        'id' => (string) ($tenant['id'] ?? ''),
                        'label' => (string) ($tenant['label'] ?? $tenant['id'] ?? ''),
                    ])
                    ->filter(fn (array $tenant): bool => $tenant['id'] !== '')
                    ->values()
                    ->all();

                return new TenancyDatabaseBackupFile(
                    name: $name,
                    path: $path,
                    size: File::size($path),
                    createdAt: Carbon::parse((string) ($manifest['created_at'] ?? now()->toIso8601String())),
                    encrypted: str_ends_with($name, '.enc'),
                    storageLocations: ['local'],
                    kind: 'set',
                    containsTenants: true,
                    failedTenantsCount: count((array) ($manifest['failed_tenants'] ?? [])),
                    tenantOptions: $tenantOptions,
                    restoreScopes: ['central_only', 'single_tenant', 'full_set'],
                );
            } finally {
                $this->cleanupExtractedArchive($archive);
            }
        }

        return new DatabaseBackupFile(
            name: $name,
            path: $path,
            size: File::size($path),
            createdAt: Carbon::createFromTimestamp(File::lastModified($path)),
            encrypted: str_ends_with($name, '.enc'),
            storageLocations: ['local'],
        );
    }

    private function makeImportedDumpName(bool $encrypted): string
    {
        $name = $this->coreBackups->makeName('imported');

        return $encrypted ? $name.'.enc' : $name;
    }

    private function makeImportedSetName(bool $encrypted): string
    {
        $name = $this->makeSetName('imported');

        return $encrypted ? $name.'.enc' : $name;
    }

    private function tenantDumpRelativePath(string $tenantId): string
    {
        return sprintf(
            'tenants/%s-%s.dump',
            $this->sanitizeSegment($tenantId),
            hash('sha256', $tenantId),
        );
    }

    private function sanitizeSegment(string $value): string
    {
        $sanitized = trim((string) preg_replace('/[^A-Za-z0-9_.-]+/', '-', $value), '-');

        return $sanitized !== '' ? $sanitized : 'tenant';
    }

    private function enforceRetention(): void
    {
        $settings = $this->coreSettings->toArray();
        $retentionMode = $settings['retention_mode'];

        if ($retentionMode === 'forever') {
            return;
        }

        if ($retentionMode === 'count') {
            $backupsToDelete = $this->list()->slice($settings['retention_count'])->pluck('name');

            foreach ($backupsToDelete as $backupName) {
                $this->delete($backupName);
            }

            return;
        }

        $cutoff = now()->subDays($settings['retention_days']);
        $backupsToDelete = $this->list()
            ->filter(fn (DatabaseBackupFile|TenancyDatabaseBackupFile $backup): bool => $backup->createdAt->lt($cutoff))
            ->pluck('name');

        foreach ($backupsToDelete as $backupName) {
            $this->delete($backupName);
        }
    }
}

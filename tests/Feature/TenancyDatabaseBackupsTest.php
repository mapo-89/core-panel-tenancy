<?php

declare(strict_types=1);

use CorePanel\Http\Controllers\Administration\AdministrationController;
use CorePanel\Http\Middleware\CheckPermission;
use CorePanel\Http\Middleware\EnsureCorePanelEmailIsVerified;
use CorePanel\Support\Administration\DatabaseBackups\DatabaseBackupRestoreStatus;
use CorePanel\Tests\FakeUser;
use CorePanelTenancy\Http\Controllers\Administration\TenancyAdministrationController;
use CorePanelTenancy\Support\Administration\DatabaseBackups\TenancyDatabaseBackupFile;
use CorePanelTenancy\Support\Administration\DatabaseBackups\TenancyDatabaseBackupRestoreService;
use CorePanelTenancy\Support\Administration\DatabaseBackups\TenancyDatabaseBackupService;
use CorePanelTenancy\Support\Administration\DatabaseBackups\TenancyDatabaseBackupSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;

final class TestBackupTenant extends Model
{
    protected $table = 'tenants';

    protected $connection = 'sqlite';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    public function run(Closure $callback): mixed
    {
        $previousDefault = config('database.default');
        $previousConnection = config('database.connections.tenant');

        config()->set('database.default', 'tenant');
        config()->set('database.connections.tenant', config('database.connections.pgsql'));

        try {
            return $callback();
        } finally {
            config()->set('database.default', $previousDefault);
            config()->set('database.connections.tenant', $previousConnection);
        }
    }
}

beforeEach(function (): void {
    if (! corePanelTestbenchDatabaseAvailable()) {
        $this->markTestSkipped('pdo_sqlite is not available in this environment.');
    }

    $this->migrateScaffoldDatabase();
    $this->backupPath = storage_path('framework/testing/core-panel-tenancy-backups-'.bin2hex(random_bytes(4)));

    File::ensureDirectoryExists($this->backupPath);

    config()->set('core-panel.administration.database_backups.enabled', true);
    config()->set('core-panel.administration.database_backups.path', $this->backupPath);
    config()->set('core-panel.administration.system_updates.enabled', false);
    config()->set('core-panel.horizon.enabled', false);
    config()->set('database.default', 'sqlite');
    config()->set('tenancy.database.central_connection', 'sqlite');
    config()->set('tenancy.tenant_model', TestBackupTenant::class);

    Schema::create('tenants', static function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->json('data')->nullable();
        $table->timestamps();
    });

    Gate::before(static fn (...$arguments): bool => true);
});

afterEach(function (): void {
    File::deleteDirectory($this->backupPath);
    Carbon::setTestNow();
});

it('binds the administration area to tenancy-specific backup services', function (): void {
    expect(app(AdministrationController::class))
        ->toBeInstanceOf(TenancyAdministrationController::class)
        ->and(app(TenancyDatabaseBackupSettings::class)->toArray()['automatic_scope'])
        ->toBe('full_set');
});

it('uses distinct archive paths for tenant IDs with the same sanitized segment', function (): void {
    $service = app(TenancyDatabaseBackupService::class);
    $method = new ReflectionMethod($service, 'tenantDumpRelativePath');
    $method->setAccessible(true);

    $slashIdPath = $method->invoke($service, 'acme/eu');
    $dashIdPath = $method->invoke($service, 'acme-eu');

    expect($slashIdPath)->toBe('tenants/acme-eu-'.hash('sha256', 'acme/eu').'.dump')
        ->and($dashIdPath)->toBe('tenants/acme-eu-'.hash('sha256', 'acme-eu').'.dump')
        ->and($slashIdPath)->not->toBe($dashIdPath);
});

it('shows the content scope column by default in the administration backup table state', function (): void {
    $user = FakeUser::query()->create([
        'email' => 'table@example.test',
        'first_name' => 'Table',
        'last_name' => 'Admin',
        'password' => bcrypt('password'),
    ]);

    $this->actingAs($user)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
        ])
        ->get(route('core-panel.administration.index'))
        ->assertSuccessful()
        ->assertJsonPath('props.databaseBackupsTab.backupsTable.state.visibleColumns.0', 'name')
        ->assertJsonPath('props.databaseBackupsTab.backupsTable.state.visibleColumns.1', 'content_scope')
        ->assertJsonPath('props.databaseBackupsTab.backupsTable.state.filters.content_scope', '');
});

it('removes a corrupt archive import before it can break backup listing', function (): void {
    $upload = UploadedFile::fake()->createWithContent('corrupt.zip', 'not a zip archive');

    expect(fn (): mixed => app(TenancyDatabaseBackupService::class)->importUploaded($upload))
        ->toThrow(RuntimeException::class)
        ->and(File::files($this->backupPath))->toBeEmpty()
        ->and(app(TenancyDatabaseBackupService::class)->list())->toBeEmpty();
});

it('removes an archive import without a manifest before it can break backup listing', function (): void {
    $archivePath = storage_path('framework/testing/core-panel-tenancy-invalid-'.bin2hex(random_bytes(4)).'.zip');
    $zip = new ZipArchive;
    $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('central.dump', 'central dump');
    $zip->close();
    $upload = UploadedFile::fake()->createWithContent('missing-manifest.zip', (string) File::get($archivePath));

    try {
        expect(fn (): mixed => app(TenancyDatabaseBackupService::class)->importUploaded($upload))
            ->toThrow(RuntimeException::class)
            ->and(File::files($this->backupPath))->toBeEmpty()
            ->and(app(TenancyDatabaseBackupService::class)->list())->toBeEmpty();
    } finally {
        File::delete($archivePath);
    }
});

it('rejects backup archives whose uncompressed contents exceed the configured import limit', function (): void {
    $archivePath = $this->backupPath.'/oversized.zip';
    $zip = new ZipArchive;
    $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('manifest.json', json_encode([
        'contains_tenants' => true,
        'created_at' => now()->toIso8601String(),
        'failed_tenants' => [],
        'kind' => 'set',
        'tenants' => [],
    ], JSON_THROW_ON_ERROR));
    $zip->addFromString('central.dump', str_repeat('x', 2 * 1024));
    $zip->close();
    config()->set('core-panel.administration.database_backups.import_max_size_kb', 1);

    expect(filesize($archivePath))->toBeLessThan(1024)
        ->and(fn (): mixed => app(TenancyDatabaseBackupService::class)->extractArchive('oversized.zip'))
        ->toThrow(RuntimeException::class);
});

it('applies the configured import size limit to uploaded backup archives', function (): void {
    $user = FakeUser::query()->create([
        'email' => 'import-limit@example.test',
        'first_name' => 'Import',
        'last_name' => 'Limit',
        'password' => bcrypt('password'),
    ]);
    config()->set('core-panel.administration.database_backups.import_max_size_kb', 1);

    $this->actingAs($user)
        ->from(route('core-panel.administration.index'))
        ->post(route('core-panel.database-backups.import'), [
            'backup' => UploadedFile::fake()->create('oversized.zip', 2, 'application/zip'),
        ])
        ->assertRedirect(route('core-panel.administration.index'))
        ->assertSessionHasErrors('backup');
});

it('filters the administration backup table by content scope', function (): void {
    $user = FakeUser::query()->create([
        'email' => 'filter@example.test',
        'first_name' => 'Filter',
        'last_name' => 'Admin',
        'password' => bcrypt('password'),
    ]);

    File::put($this->backupPath.'/central-only.dump', 'central backup');

    $archiveDirectory = storage_path('framework/testing/core-panel-tenancy-filter-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($archiveDirectory);
    File::put($archiveDirectory.'/central.dump', 'central dump');

    $manifestPath = $archiveDirectory.'/manifest.json';
    File::put($manifestPath, json_encode([
        'contains_tenants' => true,
        'created_at' => now()->toIso8601String(),
        'failed_tenants' => [],
        'kind' => 'set',
        'source' => 'manual',
        'tenants' => [[
            'file' => 'tenants/tenant-alpha.dump',
            'id' => 'tenant-alpha',
            'label' => 'Tenant Alpha',
        ]],
        'version' => 1,
    ], JSON_THROW_ON_ERROR));

    File::ensureDirectoryExists($archiveDirectory.'/tenants');
    File::put($archiveDirectory.'/tenants/tenant-alpha.dump', 'tenant dump');

    $zip = new ZipArchive;
    $zip->open($this->backupPath.'/full-set.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFile($manifestPath, 'manifest.json');
    $zip->addFile($archiveDirectory.'/central.dump', 'central.dump');
    $zip->addFile($archiveDirectory.'/tenants/tenant-alpha.dump', 'tenants/tenant-alpha.dump');
    $zip->close();

    $this->actingAs($user)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
        ])
        ->get(route('core-panel.administration.index', [
            'filter' => [
                'content_scope' => 'full_set',
            ],
        ]))
        ->assertSuccessful()
        ->assertJsonPath('props.databaseBackupsTab.backupsTable.pagination.total', 1)
        ->assertJsonPath('props.databaseBackupsTab.backups.0.name', 'full-set.zip')
        ->assertJsonPath('props.databaseBackupsTab.backupsTable.state.filters.content_scope', 'full_set');

    File::deleteDirectory($archiveDirectory);
});

it('exposes tenancy backup scopes through the addon backup service', function (): void {
    $service = app(TenancyDatabaseBackupService::class);

    expect($service->availableBackupScopes())
        ->toBe(['central_only', 'full_set'])
        ->and($service->supportsBackupScope('central_only'))->toBeTrue()
        ->and($service->supportsBackupScope('full_set'))->toBeTrue()
        ->and($service->supportsBackupScope('single_tenant'))->toBeFalse();
});

it('persists the automatic tenancy backup scope in settings', function (): void {
    $settings = app(TenancyDatabaseBackupSettings::class);
    $current = $settings->toArray();

    $settings->update([
        'automatic_enabled' => $current['automatic_enabled'],
        'automatic_scope' => 'central_only',
        'cloud_backup_enabled' => $current['cloud_backup_enabled'],
        'cloud_backup_path' => $current['cloud_backup_path'],
        'encryption_code' => $current['encryption_code'],
        'encryption_enabled' => $current['encryption_enabled'],
        'retention_count' => $current['retention_count'],
        'retention_days' => $current['retention_days'],
        'retention_mode' => $current['retention_mode'],
        'schedule_mode' => $current['schedule_mode'],
        'time' => $current['time'],
        'time_mode' => $current['time_mode'],
        'weekdays' => $current['weekdays'],
    ]);

    expect($settings->toArray()['automatic_scope'])->toBe('central_only');
});

it('returns restore status payloads through the shared status store', function (): void {
    $restoreStatus = app(DatabaseBackupRestoreStatus::class);
    $restoreId = $restoreStatus->start('tenant-set.zip', 'single_tenant', ['tenant-alpha']);
    $restoreStatus->complete($restoreId);

    expect($restoreStatus->get($restoreId))
        ->toMatchArray([
            'message_key' => 'database_backups.restored',
            'status' => 'completed',
        ]);
});

it('detects backup-set source suffixes on tenancy backup files', function (): void {
    $backup = new TenancyDatabaseBackupFile(
        name: 'core_panel-2026-07-16_03-15-00-automatic.zip',
        path: $this->backupPath.'/core_panel-2026-07-16_03-15-00-automatic.zip',
        size: 128,
        createdAt: now(),
        kind: 'set',
        containsTenants: true,
        restoreScopes: ['central_only', 'single_tenant', 'full_set'],
    );

    expect($backup->source())->toBe('automatic')
        ->and($backup->toArray())
        ->toMatchArray([
            'contains_tenants' => true,
            'kind' => 'set',
            'restore_scopes' => ['central_only', 'single_tenant', 'full_set'],
        ]);
});

it('uses central table restore for partial restores from backup sets', function (): void {
    $archiveDirectory = storage_path('framework/testing/core-panel-tenancy-restore-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($archiveDirectory);
    File::put($archiveDirectory.'/central.dump', 'central dump');

    $zipPath = $this->backupPath.'/tenant-set.zip';
    $manifestPath = $archiveDirectory.'/manifest.json';

    File::put($manifestPath, json_encode([
        'contains_tenants' => true,
        'created_at' => now()->toIso8601String(),
        'failed_tenants' => [],
        'kind' => 'set',
        'source' => 'manual',
        'tenants' => [],
        'version' => 1,
    ], JSON_THROW_ON_ERROR));

    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFile($manifestPath, 'manifest.json');
    $zip->addFile($archiveDirectory.'/central.dump', 'central.dump');
    $zip->close();

    config()->set('database.default', 'pgsql');
    config()->set('database.connections.pgsql', [
        'database' => 'core_panel',
        'driver' => 'pgsql',
        'host' => '127.0.0.1',
        'password' => 'secret',
        'port' => '5432',
        'username' => 'core_panel',
    ]);

    DB::shouldReceive('select')->times(4)->andReturn([]);
    DB::shouldReceive('disconnect')->once();
    Process::fake([
        '*' => Process::result(),
    ]);

    $service = app(TenancyDatabaseBackupRestoreService::class);
    $service->restore('tenant-set.zip', 'central_tables', ['users']);

    Process::assertRan(static fn ($process): bool => in_array('pg_restore', $process->command, true));
    Process::assertRan(static fn ($process): bool => in_array('psql', $process->command, true));

    expect(collect(File::files($this->backupPath))->pluck('filename'))->not->toContain(
        fn (string $filename): bool => str_starts_with($filename, 'core-panel-central-set-'),
    );

    File::deleteDirectory($archiveDirectory);
});

it('uses tenant table restore for partial tenant restores from backup sets', function (): void {
    $archiveDirectory = storage_path('framework/testing/core-panel-tenancy-tenant-restore-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($archiveDirectory.'/tenants');
    File::put($archiveDirectory.'/central.dump', 'central dump');
    File::put($archiveDirectory.'/tenants/tenant-alpha.dump', 'tenant dump');

    $zipPath = $this->backupPath.'/tenant-set.zip';
    $manifestPath = $archiveDirectory.'/manifest.json';

    File::put($manifestPath, json_encode([
        'contains_tenants' => true,
        'created_at' => now()->toIso8601String(),
        'failed_tenants' => [],
        'kind' => 'set',
        'source' => 'manual',
        'tenants' => [[
            'file' => 'tenants/tenant-alpha.dump',
            'id' => 'tenant-alpha',
            'label' => 'Tenant Alpha',
        ]],
        'version' => 1,
    ], JSON_THROW_ON_ERROR));

    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFile($manifestPath, 'manifest.json');
    $zip->addFile($archiveDirectory.'/central.dump', 'central.dump');
    $zip->addFile($archiveDirectory.'/tenants/tenant-alpha.dump', 'tenants/tenant-alpha.dump');
    $zip->close();

    TestBackupTenant::query()->create([
        'id' => 'tenant-alpha',
    ]);

    config()->set('database.default', 'pgsql');
    config()->set('database.connections.pgsql', [
        'database' => 'core_panel',
        'driver' => 'pgsql',
        'host' => '127.0.0.1',
        'password' => 'secret',
        'port' => '5432',
        'username' => 'core_panel',
    ]);

    DB::shouldReceive('select')->times(4)->andReturn([]);
    DB::shouldReceive('disconnect')->once();
    Process::fake([
        '*' => Process::result(),
    ]);

    $service = app(TenancyDatabaseBackupRestoreService::class);
    $service->restore('tenant-set.zip', 'tenant_tables', ['tenant-alpha', 'users']);

    Process::assertRan(static fn ($process): bool => in_array('pg_restore', $process->command, true));
    Process::assertRan(static fn ($process): bool => in_array('psql', $process->command, true));

    expect(collect(File::files($this->backupPath))->pluck('filename'))->not->toContain(
        fn (string $filename): bool => str_starts_with($filename, 'core-panel-tenant-set-'),
    );

    File::deleteDirectory($archiveDirectory);
});

it('accepts full backup set restores without table selection', function (): void {
    $this->withoutDefer();
    $this->withoutMiddleware(EnsureCorePanelEmailIsVerified::class);
    $this->withoutMiddleware(CheckPermission::class);

    $archiveDirectory = storage_path('framework/testing/core-panel-tenancy-full-set-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($archiveDirectory);
    File::put($archiveDirectory.'/central.dump', 'central dump');

    $backupName = 'tenant-set.zip';
    $manifestPath = $archiveDirectory.'/manifest.json';

    File::put($manifestPath, json_encode([
        'contains_tenants' => true,
        'created_at' => now()->toIso8601String(),
        'failed_tenants' => [],
        'kind' => 'set',
        'source' => 'manual',
        'tenants' => [],
        'version' => 1,
    ], JSON_THROW_ON_ERROR));

    $zip = new ZipArchive;
    $zip->open($this->backupPath.'/'.$backupName, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFile($manifestPath, 'manifest.json');
    $zip->addFile($archiveDirectory.'/central.dump', 'central.dump');
    $zip->close();

    $user = FakeUser::query()->create([
        'email' => 'restore@example.test',
        'first_name' => 'Restore',
        'last_name' => 'Admin',
        'password' => bcrypt('password'),
    ]);

    config()->set('database.default', 'pgsql');
    config()->set('database.connections.pgsql', [
        'database' => 'core_panel',
        'driver' => 'pgsql',
        'host' => '127.0.0.1',
        'password' => 'secret',
        'port' => '5432',
        'username' => 'core_panel',
    ]);

    Process::fake([
        '*' => Process::result(),
    ]);

    $this->actingAs($user)
        ->postJson(route('core-panel.database-backups.restore', ['backup' => $backupName]), [
            'confirmation' => 'RESTORE',
            'mode' => 'full_set',
            'tables' => [],
        ])
        ->assertStatus(202)
        ->assertJsonPath('message', __('database_backups.restore_started'));

    File::deleteDirectory($archiveDirectory);
});

<?php

declare(strict_types=1);

namespace CorePanelTenancy\Http\Controllers\Administration;

use CorePanel\Support\Administration\DatabaseBackups\DatabaseBackupCloudBackupService;
use CorePanel\Support\Administration\DatabaseBackups\DatabaseBackupFile;
use CorePanel\Support\Administration\SystemUpdates\SystemUpdaterClient;
use CorePanel\Support\Permissions\PermissionService;
use CorePanelTenancy\Support\Administration\DatabaseBackups\TenancyDatabaseBackupFile;
use CorePanelTenancy\Support\Administration\DatabaseBackups\TenancyDatabaseBackupRestoreService;
use CorePanelTenancy\Support\Administration\DatabaseBackups\TenancyDatabaseBackupService;
use CorePanelTenancy\Support\Administration\DatabaseBackups\TenancyDatabaseBackupSettings;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

final class TenancyAdministrationController extends Controller
{
    public function __construct(
        private readonly DatabaseBackupCloudBackupService $cloudBackups,
        private readonly TenancyDatabaseBackupRestoreService $backupRestoreService,
        private readonly TenancyDatabaseBackupSettings $backupSettings,
        private readonly TenancyDatabaseBackupService $backups,
        private readonly PermissionService $permissions,
        private readonly SystemUpdaterClient $systemUpdater,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 403);

        $databaseBackupsTab = $this->databaseBackupsTab($request);
        $horizonTab = $this->horizonTab($request);
        $systemUpdatesTab = $this->systemUpdatesTab($request);
        abort_unless($databaseBackupsTab !== null || $horizonTab !== null || $systemUpdatesTab !== null, 403);

        $availableTabs = collect([
            'database-backups' => $databaseBackupsTab,
            'horizon' => $horizonTab,
            'system-updates' => $systemUpdatesTab,
        ])->filter();

        $requestedTab = (string) $request->query('tab', '');
        $activeTab = $availableTabs->has($requestedTab)
            ? $requestedTab
            : (string) $availableTabs->keys()->first();

        return Inertia::render('Admin/Administration/Index', [
            'activeTab' => $activeTab,
            'databaseBackupsTab' => $databaseBackupsTab,
            'horizonTab' => $horizonTab,
            'systemUpdatesTab' => $systemUpdatesTab,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function databaseBackupsTab(Request $request): ?array
    {
        $user = $request->user();

        if ($user === null || ! $this->backups->enabled() || ! $this->permissions->userHas($user, 'database-backups.view')) {
            return null;
        }

        $canUpdateBackupSettings = $this->canManageDatabaseBackupSettings($user);

        return [
            ...$this->buildBackupsTable($request, $this->backups->list()),
            'backupScopes' => $this->backups->availableBackupScopes(),
            'cloudBackup' => $this->cloudBackups->status(),
            'routes' => [
                'destroy' => route('core-panel.database-backups.destroy', ['backup' => '__BACKUP__']),
                'download' => route('core-panel.database-backups.download', ['backup' => '__BACKUP__']),
                'downloadSql' => route('core-panel.database-backups.download-sql', ['backup' => '__BACKUP__']),
                'emergencyKit' => $canUpdateBackupSettings
                    ? route('core-panel.database-backups.emergency-kit')
                    : null,
                'import' => route('core-panel.database-backups.import'),
                'restore' => $this->backupRestoreService->supportsRestore()
                    ? route('core-panel.database-backups.restore', ['backup' => '__BACKUP__'])
                    : null,
                'restoreStatus' => $this->backupRestoreService->supportsRestore()
                    ? route('core-panel.database-backups.restore.status', ['restoreId' => '__RESTORE__'])
                    : null,
                'settings' => $canUpdateBackupSettings
                    ? route('core-panel.database-backups.settings.update')
                    : null,
                'store' => route('core-panel.database-backups.store'),
            ],
            'settings' => $this->databaseBackupSettingsPayload($canUpdateBackupSettings),
            'tableOptions' => [
                'central' => $this->backupRestoreService->tableOptions(),
                'tenant' => $this->backupRestoreService->tenantTableOptions(),
            ],
        ];
    }

    /**
     * @param  Collection<int, DatabaseBackupFile|TenancyDatabaseBackupFile>  $backups
     * @return array{backups: array<int, array<string, mixed>>, backupsTable: array<string, mixed>, summary: array<string, mixed>}
     */
    private function buildBackupsTable(Request $request, Collection $backups): array
    {
        $defaultColumns = ['name', 'content_scope', 'storage_locations', 'encrypted', 'source', 'created_at', 'size'];
        $search = trim((string) $request->query('search', ''));
        $contentScope = $this->contentScopeFilter($request);
        $source = $this->sourceFilter($request);
        $sort = $this->sort($request);

        $filteredBackups = $backups
            ->when($search !== '', fn (Collection $items): Collection => $items->filter(
                fn (object $backup): bool => str_contains(Str::lower((string) $backup->name), Str::lower($search))
                    || str_contains(Str::lower((string) $backup->source()), Str::lower($search)),
            ))
            ->when($contentScope !== '', fn (Collection $items): Collection => $items->filter(
                fn (object $backup): bool => ($contentScope === 'full_set')
                    ? (bool) data_get($backup, 'containsTenants', false)
                    : ! (bool) data_get($backup, 'containsTenants', false),
            ))
            ->when($source !== '', fn (Collection $items): Collection => $items->filter(
                fn (object $backup): bool => $backup->source() === $source,
            ));

        $sortedBackups = $filteredBackups
            ->sortBy(
                fn (object $backup): int|string => $this->sortValue($backup, $sort),
                SORT_REGULAR,
                str_starts_with($sort, '-'),
            )
            ->values();

        $perPage = max(1, min(100, $request->integer('per_page', 10)));
        $total = $sortedBackups->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($lastPage, $request->integer('page', 1)));
        $pageBackups = $sortedBackups->forPage($page, $perPage)->values();

        return [
            'backups' => $pageBackups->map(fn (object $backup): array => $backup->toArray())->all(),
            'backupsTable' => [
                'pagination' => [
                    'from' => $total === 0 ? null : (($page - 1) * $perPage) + 1,
                    'lastPage' => $lastPage,
                    'page' => $page,
                    'perPage' => $perPage,
                    'to' => $total === 0 ? null : min($page * $perPage, $total),
                    'total' => $total,
                ],
                'state' => [
                    'filters' => [
                        'content_scope' => $contentScope,
                        'source' => $source,
                    ],
                    'search' => $search,
                    'sort' => $sort,
                    'visibleColumns' => $this->visibleColumns($request, $defaultColumns),
                ],
            ],
            'summary' => [
                'count' => $backups->count(),
                'latest' => $backups->first()?->toArray(),
                'total_size' => $backups->sum(fn (object $backup): int => (int) $backup->size),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function databaseBackupSettingsPayload(bool $includeEncryptionCode): array
    {
        $settings = $this->backupSettings->toArray();

        if (! $includeEncryptionCode) {
            $settings['encryption_code'] = '';
        }

        return $settings;
    }

    private function canManageDatabaseBackupSettings(Authenticatable $user): bool
    {
        if (method_exists($user, 'hasPermissionTo')) {
            if (! $this->permissions->permissionExists('database-backups.update')) {
                return false;
            }

            return $user->hasPermissionTo('database-backups.update');
        }

        return $this->permissions->userHas($user, 'database-backups.update');
    }

    private function sourceFilter(Request $request): string
    {
        $source = (string) $request->input('filter.source', '');

        return in_array($source, ['automatic', 'custom', 'imported', 'manual'], true)
            ? $source
            : '';
    }

    private function contentScopeFilter(Request $request): string
    {
        $scope = (string) $request->input('filter.content_scope', '');

        return in_array($scope, ['central_only', 'full_set'], true)
            ? $scope
            : '';
    }

    private function sort(Request $request): string
    {
        $sort = (string) $request->query('sort', '-created_at');
        $field = ltrim($sort, '-');

        if (! in_array($field, ['created_at', 'encrypted', 'name', 'size', 'source'], true)) {
            return '-created_at';
        }

        return str_starts_with($sort, '-') ? "-{$field}" : $field;
    }

    private function sortValue(object $backup, string $sort): int|string
    {
        return match (ltrim($sort, '-')) {
            'name' => Str::lower((string) $backup->name),
            'encrypted' => (int) $backup->encrypted,
            'size' => (int) $backup->size,
            'source' => (string) $backup->source(),
            default => $backup->createdAt->getTimestamp(),
        };
    }

    /**
     * @param  array<int, string>  $fallback
     * @return array<int, string>
     */
    private function visibleColumns(Request $request, array $fallback): array
    {
        $columns = (string) $request->query('columns', '');

        if ($columns === '') {
            return $fallback;
        }

        $visibleColumns = collect(explode(',', $columns))
            ->filter(fn (string $column): bool => in_array($column, $fallback, true))
            ->values()
            ->all();

        return $visibleColumns === [] ? $fallback : $visibleColumns;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function systemUpdatesTab(Request $request): ?array
    {
        $user = $request->user();

        if ($user === null || ! $this->systemUpdater->enabled() || ! $this->permissions->userHas($user, 'system-updates.view')) {
            return null;
        }

        return [
            'automatic' => [
                'enabled' => (bool) config('core-panel.administration.system_updates.automatic.enabled', false),
                'forceUpdateEnabled' => (bool) config('core-panel.administration.system_updates.force_update_enabled', false),
                'inactiveMinutes' => (int) config('core-panel.administration.system_updates.automatic.inactive_minutes', 15),
                'timezone' => (string) config('core-panel.administration.system_updates.automatic.timezone', config('app.timezone')),
                'windowEnd' => (string) config('core-panel.administration.system_updates.automatic.window_end', '04:00'),
                'windowStart' => (string) config('core-panel.administration.system_updates.automatic.window_start', '02:00'),
            ],
            'logs' => $this->systemUpdater->safeLogs(),
            'routes' => [
                'check' => route('core-panel.system-updates.check'),
                'status' => route('core-panel.system-updates.status'),
                'update' => route('core-panel.system-updates.update'),
            ],
            'status' => $this->systemUpdater->safeStatus(),
        ];
    }

    /**
     * @return array{url: string}|null
     */
    private function horizonTab(Request $request): ?array
    {
        $user = $request->user();

        if (
            $user === null
            || ! (bool) config('core-panel.horizon.enabled', true)
            || ! $this->permissions->userHas($user, 'horizon.view')
            || ! Gate::forUser($user)->allows('viewHorizon')
            || ! $this->horizonIsRunning()
        ) {
            return null;
        }

        return [
            'url' => '/'.ltrim((string) config('horizon.path', 'horizon'), '/'),
        ];
    }

    private function horizonIsRunning(): bool
    {
        if (! app()->bound(MasterSupervisorRepository::class)) {
            return false;
        }

        try {
            $masters = app(MasterSupervisorRepository::class)->all();
        } catch (Throwable) {
            return false;
        }

        return collect($masters)->contains(
            static fn (mixed $master): bool => ($master->status ?? null) === 'running',
        );
    }
}

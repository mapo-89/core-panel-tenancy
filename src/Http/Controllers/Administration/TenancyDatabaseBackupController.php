<?php

declare(strict_types=1);

namespace CorePanelTenancy\Http\Controllers\Administration;

use CorePanel\Support\ActivityLog\ActivityLogService;
use CorePanel\Support\Administration\DatabaseBackups\DatabaseBackupRestoreStatus;
use CorePanel\Support\Administration\DatabaseBackups\DatabaseBackupSqlExportService;
use CorePanel\Support\Permissions\PermissionService;
use CorePanelTenancy\Support\Administration\DatabaseBackups\TenancyDatabaseBackupFile;
use CorePanelTenancy\Support\Administration\DatabaseBackups\TenancyDatabaseBackupRestoreService;
use CorePanelTenancy\Support\Administration\DatabaseBackups\TenancyDatabaseBackupService;
use CorePanelTenancy\Support\Administration\DatabaseBackups\TenancyDatabaseBackupSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidationValidator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

use function Illuminate\Support\defer;

final class TenancyDatabaseBackupController extends Controller
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
        private readonly TenancyDatabaseBackupRestoreService $restoreService,
        private readonly DatabaseBackupRestoreStatus $restoreStatus,
        private readonly TenancyDatabaseBackupSettings $settings,
        private readonly TenancyDatabaseBackupService $backups,
        private readonly DatabaseBackupSqlExportService $sqlExporter,
        private readonly PermissionService $permissions,
    ) {}

    public function updateSettings(Request $request): RedirectResponse
    {
        abort_unless($this->backups->enabled(), 404);

        $validated = Validator::make($request->all(), [
            'automatic_enabled' => ['required', 'boolean'],
            'automatic_scope' => ['required', 'in:central_only,full_set'],
            'cloud_backup_enabled' => ['nullable', 'boolean'],
            'cloud_backup_path' => ['nullable', 'required_if:cloud_backup_enabled,true', 'string', 'max:255'],
            'encryption_code' => ['required', 'string', 'min:16', 'max:128'],
            'encryption_enabled' => ['required', 'boolean'],
            'retention_count' => ['nullable', 'required_if:retention_mode,count', 'integer', 'min:1', 'max:365'],
            'retention_days' => ['nullable', 'required_if:retention_mode,days', 'integer', 'min:1', 'max:3650'],
            'retention_mode' => ['required', 'in:count,days,forever'],
            'schedule_mode' => ['required', 'in:daily,custom'],
            'time' => ['nullable', 'required_if:time_mode,custom', 'date_format:H:i'],
            'time_mode' => ['required', 'in:system,custom'],
            'weekdays' => ['exclude_unless:schedule_mode,custom', 'required', 'array', 'min:1'],
            'weekdays.*' => ['string', 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday'],
        ])->validate();

        $this->settings->update($validated);
        $this->logActivity($request, 'database_backups.settings_updated', [
            'automatic_scope' => $validated['automatic_scope'],
            'retention_mode' => $validated['retention_mode'],
            'schedule_mode' => $validated['schedule_mode'],
            'time_mode' => $validated['time_mode'],
        ]);

        return back()->with('success', __('database_backups.settings_saved'));
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->backups->enabled(), 404);
        abort_unless($request->user() !== null && $this->permissions->userHas($request->user(), 'database-backups.create'), 403);

        $scope = (string) $request->input('scope', 'central_only');
        abort_unless($this->backups->supportsBackupScope($scope), 422);

        try {
            $backup = $this->backups->create('manual', $scope);
            $this->logActivity($request, 'database_backups.created', [
                'name' => $backup->name,
                'scope' => $scope,
            ]);

            return back()->with(
                'success',
                $backup instanceof TenancyDatabaseBackupFile && $backup->failedTenantsCount > 0
                    ? __('database_backups.created')." ({$backup->failedTenantsCount} tenant failures)"
                    : __('database_backups.created'),
            );
        } catch (Throwable $throwable) {
            report($throwable);

            return back()->with('error', __('database_backups.create_failed'));
        }
    }

    public function importBackup(Request $request): RedirectResponse
    {
        abort_unless($this->backups->enabled(), 404);
        abort_unless($request->user() !== null && $this->permissions->userHas($request->user(), 'database-backups.create'), 403);

        $validated = Validator::make($request->all(), [
            'backup' => [
                'required',
                'file',
                'max:'.max(1, (int) config('core-panel.administration.database_backups.import_max_size_kb', 1048576)),
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! method_exists($value, 'getClientOriginalName')) {
                        $fail(__('database_backups.import_invalid_file'));

                        return;
                    }

                    $name = mb_strtolower((string) $value->getClientOriginalName());

                    if (
                        ! str_ends_with($name, '.dump')
                        && ! str_ends_with($name, '.dump.enc')
                        && ! str_ends_with($name, '.zip')
                        && ! str_ends_with($name, '.zip.enc')
                    ) {
                        $fail(__('database_backups.import_invalid_file'));
                    }
                },
            ],
        ])->validate();

        $file = $request->file('backup');

        if ($file === null) {
            return back()->with('error', __('database_backups.import_failed'));
        }

        try {
            $backup = $this->backups->importUploaded($file);
            $this->logActivity($request, 'database_backups.imported', ['name' => $backup->name]);

            return back()->with('success', __('database_backups.imported'));
        } catch (Throwable $throwable) {
            report($throwable);

            return back()->with('error', __('database_backups.import_failed'));
        }
    }

    public function emergencyKit(Request $request): StreamedResponse
    {
        abort_unless($this->backups->enabled(), 404);
        abort_unless($request->user() !== null && $this->permissions->userHas($request->user(), 'database-backups.update'), 403);

        $settings = $this->settings->toArray();
        $payload = [
            'backup_encryption_code' => $settings['encryption_code'],
            'created_at' => now()->toIso8601String(),
            'format' => 'core-panel-database-backup-emergency-kit-v1',
            'instructions' => [
                'Keep this file offline and separate from the server.',
                'Encrypted backup files end with .dump.enc or .zip.enc.',
                'This code is required to decrypt encrypted database backups.',
            ],
            'product' => config('app.name'),
        ];

        return response()->streamDownload(
            function () use ($payload): void {
                echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            },
            'core-panel-database-backup-emergency-kit.json',
            ['Content-Type' => 'application/json'],
        );
    }

    public function download(Request $request, string $backup): BinaryFileResponse
    {
        abort_unless($this->backups->enabled(), 404);
        abort_unless($request->user() !== null && $this->permissions->userHas($request->user(), 'database-backups.view'), 403);
        abort_unless($this->backups->exists($backup), 404);

        return response()->download($this->backups->pathFor($backup), $backup);
    }

    public function downloadSql(Request $request, string $backup): BinaryFileResponse
    {
        abort_unless($this->backups->enabled(), 404);
        abort_unless($request->user() !== null && $this->permissions->userHas($request->user(), 'database-backups.view'), 403);
        abort_unless($this->backups->exists($backup), 404);

        $descriptor = $this->backups->find($backup);

        abort_unless(! ($descriptor instanceof TenancyDatabaseBackupFile) || $descriptor->kind !== 'set', 404);

        $export = $this->sqlExporter->export($backup);

        return response()
            ->download($export->path, $export->name, ['Content-Type' => 'application/sql'])
            ->deleteFileAfterSend(true);
    }

    public function restore(Request $request, string $backup): JsonResponse|RedirectResponse
    {
        abort_unless($this->backups->enabled(), 404);
        abort_unless($request->user() !== null && $this->permissions->userHas($request->user(), 'database-backups.restore'), 403);
        abort_unless($this->backups->exists($backup), 404);

        $descriptor = $this->backups->find($backup);
        $supportsSelectiveRestore = $this->restoreService->supportsSelectiveRestore();
        $validator = Validator::make(
            $request->all(),
            $descriptor instanceof TenancyDatabaseBackupFile && $descriptor->kind === 'set'
                ? [
                    'confirmation' => ['required', 'string', 'in:RESTORE'],
                    'mode' => ['required', 'in:'.implode(',', array_filter([
                        'central_only',
                        $supportsSelectiveRestore ? 'central_tables' : null,
                        'single_tenant',
                        $supportsSelectiveRestore ? 'tenant_tables' : null,
                        'full_set',
                    ]))],
                    'tables' => ['exclude_unless:mode,central_tables,tenant_tables', 'array', 'min:1'],
                    'tables.*' => ['string'],
                    'tenant_id' => ['exclude_unless:mode,single_tenant,tenant_tables', 'string'],
                ]
                : [
                    'confirmation' => ['required', 'string', 'in:RESTORE'],
                    'mode' => ['required', 'in:all,tables'],
                    'tables' => ['exclude_if:mode,all', 'required_if:mode,tables', 'array', 'min:1'],
                    'tables.*' => ['string'],
                ],
        );

        if ($descriptor instanceof TenancyDatabaseBackupFile && $descriptor->kind === 'set') {
            $validator->after(function (ValidationValidator $validator) use ($descriptor, $request): void {
                $mode = (string) $request->input('mode');

                if (in_array($mode, ['central_tables', 'tenant_tables'], true) && count((array) $request->input('tables', [])) === 0) {
                    $validator->errors()->add('tables', __('validation.required', ['attribute' => 'tables']));
                }

                if (! in_array($mode, ['single_tenant', 'tenant_tables'], true)) {
                    return;
                }

                $tenantId = trim((string) $request->input('tenant_id', ''));

                if ($tenantId === '') {
                    $validator->errors()->add('tenant_id', __('validation.required', ['attribute' => 'tenant']));

                    return;
                }

                $tenantIds = collect($descriptor->tenantOptions)->pluck('id')->filter()->all();

                if (! in_array($tenantId, $tenantIds, true)) {
                    $validator->errors()->add('tenant_id', __('validation.exists', ['attribute' => 'tenant']));
                }
            });
        }

        $validated = $validator->validate();
        $mode = (string) $validated['mode'];
        $tables = $descriptor instanceof TenancyDatabaseBackupFile && $descriptor->kind === 'set'
            ? match ($mode) {
                'single_tenant' => [(string) $validated['tenant_id']],
                'tenant_tables' => [
                    (string) $validated['tenant_id'],
                    ...array_values((array) ($validated['tables'] ?? [])),
                ],
                'central_tables' => array_values((array) ($validated['tables'] ?? [])),
                default => [],
            }
        : ($mode === 'tables' ? array_values((array) ($validated['tables'] ?? [])) : []);
        $restoreId = $this->restoreStatus->start($backup, $mode, $tables);
        $this->logActivity($request, 'database_backups.restore_started', [
            'backup' => $backup,
            'mode' => $mode,
            'tables' => $tables,
        ]);

        defer(function () use ($backup, $mode, $restoreId, $tables): void {
            try {
                $this->restoreService->restore($backup, $mode, $tables);
                $this->restoreStatus->complete($restoreId);
            } catch (Throwable $throwable) {
                report($throwable);
                $this->restoreStatus->fail($restoreId, $throwable);
            }
        });

        return $this->restoreResponse($request, __('database_backups.restore_started'), 202, $restoreId);
    }

    public function restoreStatus(Request $request, string $restoreId): JsonResponse
    {
        abort_unless($this->backups->enabled(), 404);
        abort_unless($request->user() !== null && $this->permissions->userHas($request->user(), 'database-backups.restore'), 403);

        return response()->json([
            'restore' => [
                'id' => $restoreId,
                ...$this->restoreStatus->get($restoreId),
            ],
        ]);
    }

    public function destroy(Request $request, string $backup): RedirectResponse
    {
        abort_unless($this->backups->enabled(), 404);
        abort_unless($request->user() !== null && $this->permissions->userHas($request->user(), 'database-backups.delete'), 403);
        abort_unless($this->backups->exists($backup), 404);

        $this->backups->delete($backup);
        $this->logActivity($request, 'database_backups.deleted', ['name' => $backup]);

        return back()->with('success', __('database_backups.deleted'));
    }

    private function restoreResponse(Request $request, string $message, int $status = 200, ?string $restoreId = null): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'restore' => $restoreId !== null ? [
                    'id' => $restoreId,
                    'status_url' => route('core-panel.database-backups.restore.status', ['restoreId' => $restoreId]),
                ] : null,
            ], $status);
        }

        return back()->with('success', $message);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function logActivity(Request $request, string $event, array $properties): void
    {
        $user = $request->user();

        if ($user === null) {
            return;
        }

        $this->activityLog
            ->withCauser($user)
            ->log($user, $event, $properties);
    }
}

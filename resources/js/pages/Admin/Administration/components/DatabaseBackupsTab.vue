<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3'
import { trans } from 'laravel-vue-i18n'
import { useToast } from 'primevue/usetoast'
import { computed, ref } from 'vue'

import AppIcon from '@core-panel/components/AppIcon.vue'
import ConfirmActionDialog from '@core-panel/components/Dialogs/ConfirmActionDialog.vue'
import { useCan } from '@core-panel/composables/useCan'
import TableBuilderDataTable from '@core-panel/components/TableBuilder/DataTable.vue'
import type { DataTableSchema } from '@core-panel/components/TableBuilder/types'
import DatabaseBackupRestoreDialog from '@core-panel-tenancy/pages/Admin/Administration/components/DatabaseBackupRestoreDialog.vue'
import DatabaseBackupSettingsDialog from '@core-panel-tenancy/pages/Admin/Administration/components/DatabaseBackupSettingsDialog.vue'

type DatabaseBackup = {
    contains_tenants?: boolean
    created_at: string
    encrypted: boolean
    failed_tenants_count?: number
    kind?: 'legacy' | 'set'
    name: string
    restore_scopes?: string[]
    source: 'automatic' | 'custom' | 'imported' | 'manual'
    size: number
    size_for_humans: string
    storage_locations: Array<'local' | 'microsoft_cloud'> | string[]
    tenant_options?: Array<{ id: string; label: string }>
}

type DatabaseBackupSettings = {
    automatic_enabled: boolean
    automatic_scope?: 'central_only' | 'full_set'
    cloud_backup_enabled: boolean
    cloud_backup_path: string
    encryption_code: string
    encryption_enabled: boolean
    retention_count: number
    retention_days: number
    retention_mode: 'count' | 'days' | 'forever'
    schedule_mode: 'daily' | 'custom'
    system_time: string
    time: string
    time_mode: 'system' | 'custom'
    timezone: string
    weekdays: string[]
}

type DatabaseBackupCloudStatus = {
    available: boolean
    connected: boolean
    enabled: boolean
    missing_scopes: boolean
    path: string
    provider_email: string | null
}

type DatabaseBackupSummary = {
    count: number
    latest: DatabaseBackup | null
    total_size: number
}

type TableOption = {
    dependencies: string[]
    label: string
    value: string
}

const props = defineProps<{
    backupScopes?: Array<'central_only' | 'full_set'>
    backups: DatabaseBackup[]
    backupsTable: {
        pagination: DataTableSchema['pagination']
        state: DataTableSchema['state']
    }
    cloudBackup?: DatabaseBackupCloudStatus
    routes: {
        destroy: string
        download: string
        downloadSql?: string
        emergencyKit?: string
        import?: string
        restore?: string
        restoreStatus?: string
        settings?: string
        store?: string
    }
    settings?: DatabaseBackupSettings
    summary: DatabaseBackupSummary
    tableOptions?: {
        central: TableOption[]
        tenant: TableOption[]
    }
}>()

const { can } = useCan()
const toast = useToast()

const createBackupProcessing = ref(false)
const createBackupDialogVisible = ref(false)
const deleteDialogVisible = ref(false)
const deleteBackupProcessing = ref(false)
const pendingDeleteBackup = ref<DatabaseBackup | null>(null)
const pendingRestoreBackup = ref<DatabaseBackup | null>(null)
const restoreDialogVisible = ref(false)
const restoreNotice = ref<string | null>(null)
const restoreNoticeSeverity = ref<'error' | 'info' | 'success'>('success')
const settingsDialogVisible = ref(false)
const importDialogVisible = ref(false)
const importInputKey = ref(0)
const importForm = useForm<{ backup: File | null }>({
    backup: null,
})
const selectedCreateScope = ref<'central_only' | 'full_set'>('central_only')

const defaultCloudBackup: DatabaseBackupCloudStatus = {
    available: false,
    connected: false,
    enabled: false,
    missing_scopes: false,
    path: '',
    provider_email: null,
}

const defaultSettings: DatabaseBackupSettings = {
    automatic_enabled: false,
    automatic_scope: 'full_set',
    cloud_backup_enabled: false,
    cloud_backup_path: '',
    encryption_code: '',
    encryption_enabled: false,
    retention_count: 30,
    retention_days: 30,
    retention_mode: 'count',
    schedule_mode: 'daily',
    system_time: '02:00',
    time: '02:00',
    time_mode: 'system',
    timezone: 'UTC',
    weekdays: [],
}

const cloudBackup = computed(() => props.cloudBackup ?? defaultCloudBackup)
const settings = computed(() => props.settings ?? defaultSettings)
const tableOptions = computed(() => props.tableOptions ?? { central: [], tenant: [] })
const totalSize = computed(() => props.summary.total_size)
const latestBackup = computed(() => props.summary.latest)
const canCreate = computed(
    () => can('database-backups.create') && !!props.routes.store,
)
const canDelete = computed(() => can('database-backups.delete'))
const canRestore = computed(
    () =>
        can('database-backups.restore') &&
        !!props.routes.restore &&
        !!props.routes.restoreStatus,
)
const canUpdate = computed(
    () =>
        can('database-backups.update') &&
        !!props.routes.settings &&
        !!props.routes.emergencyKit,
)
const backupScopes = computed(
    () => props.backupScopes ?? (['central_only'] as Array<'central_only' | 'full_set'>),
)

const backupTableSchema = computed<DataTableSchema>(() => ({
    actions: [],
    bulkActions: [],
    columns: [
        {
            key: 'name',
            label: null,
            meta: { labelKey: 'database_backups.file' },
            searchable: true,
            sortable: true,
            toggleable: false,
            type: 'text',
            visible: true,
        },
        {
            key: 'content_scope',
            label: null,
            meta: { labelKey: 'database_backups.content_scope' },
            searchable: false,
            sortable: false,
            toggleable: true,
            type: 'text',
            visible: true,
        },
        {
            key: 'storage_locations',
            label: null,
            meta: { labelKey: 'database_backups.storage_locations' },
            searchable: false,
            sortable: false,
            toggleable: true,
            type: 'text',
            visible: true,
        },
        {
            key: 'encrypted',
            label: null,
            meta: { labelKey: 'database_backups.encryption' },
            searchable: false,
            sortable: true,
            toggleable: true,
            type: 'badge',
            visible: true,
        },
        {
            key: 'source',
            label: null,
            meta: { labelKey: 'database_backups.source' },
            searchable: false,
            sortable: true,
            toggleable: true,
            type: 'badge',
            visible: true,
        },
        {
            key: 'created_at',
            label: null,
            meta: { labelKey: 'database_backups.created_at' },
            searchable: false,
            sortable: true,
            toggleable: true,
            type: 'text',
            visible: true,
        },
        {
            key: 'size',
            label: null,
            meta: { labelKey: 'database_backups.size' },
            searchable: false,
            sortable: true,
            toggleable: true,
            type: 'text',
            visible: true,
        },
    ],
    filters: [
        {
            key: 'content_scope',
            label: null,
            meta: { labelKey: 'database_backups.content_scope' },
            options: {
                central_only: trans(
                    'database_backups.restore_scope_central_only_badge',
                ),
                full_set: trans(
                    'database_backups.restore_scope_full_set_badge',
                ),
            },
            type: 'select',
        },
        {
            key: 'source',
            label: null,
            meta: { labelKey: 'database_backups.source' },
            options: {
                automatic: trans('database_backups.sources.automatic'),
                custom: trans('database_backups.sources.custom'),
                imported: trans('database_backups.sources.imported'),
                manual: trans('database_backups.sources.manual'),
            },
            type: 'select',
        },
    ],
    pagination: props.backupsTable.pagination,
    rows: props.backups.map((backup) => ({
        backup,
        content_scope: backup.contains_tenants ? 'full_set' : 'central_only',
        created_at: backup.created_at,
        encrypted: backup.encrypted,
        name: backup.name,
        size: backup.size,
        size_for_humans: backup.size_for_humans,
        source: backup.source,
        storage_locations: backup.storage_locations,
    })),
    state: props.backupsTable.state,
}))

const effectiveTime = computed(() =>
    settings.value.time_mode === 'system'
        ? settings.value.system_time
        : settings.value.time,
)

const retentionSummary = computed(() => {
    if (settings.value.retention_mode === 'forever') {
        return trans('database_backups.retention_summary_forever')
    }

    if (settings.value.retention_mode === 'count') {
        return trans('database_backups.retention_summary_count', {
            count: String(settings.value.retention_count),
        })
    }

    return trans('database_backups.retention_summary_days', {
        days: String(settings.value.retention_days),
    })
})

const scheduleSummary = computed(() => {
    if (settings.value.schedule_mode === 'daily') {
        return trans('database_backups.schedule_summary_daily')
    }

    return trans('database_backups.schedule_summary_custom', {
        days: settings.value.weekdays
            .map((weekday) => trans(`database_backups.weekdays.${weekday}`))
            .join(', '),
    })
})

function formatDate(value?: string | null): string {
    if (!value) {
        return '-'
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value))
}

function formatSize(bytes: number): string {
    const units = ['B', 'KB', 'MB', 'GB']
    let size = bytes
    let unitIndex = 0

    while (size >= 1024 && unitIndex < units.length - 1) {
        size /= 1024
        unitIndex++
    }

    return `${unitIndex === 0 ? size.toFixed(0) : size.toFixed(1)} ${units[unitIndex]}`
}

function routeFor(
    template: string | undefined,
    backup: DatabaseBackup,
): string {
    return (template ?? '').replace(
        '__BACKUP__',
        encodeURIComponent(backup.name),
    )
}

function sourceLabel(source: DatabaseBackup['source']): string {
    return trans(`database_backups.sources.${source}`)
}

function storageLocationLabel(location: string): string {
    return trans(`database_backups.locations.${location}`)
}

function backupScopeLabel(backup: DatabaseBackup): string {
    return backup.contains_tenants
        ? trans('database_backups.restore_scope_full_set_badge')
        : trans('database_backups.restore_scope_central_only_badge')
}

function backupScopeSeverity(backup: DatabaseBackup): 'contrast' | 'info' | 'secondary' | 'success' {
    return backup.contains_tenants ? 'info' : 'secondary'
}

function backupScopeIcon(backup: DatabaseBackup): string {
    return backup.contains_tenants ? 'building' : 'database'
}

function sourceSeverity(
    source: DatabaseBackup['source'],
): 'info' | 'secondary' | 'success' | 'warn' {
    if (source === 'manual') {
        return 'info'
    }

    if (source === 'imported') {
        return 'success'
    }

    if (source === 'automatic') {
        return 'warn'
    }

    return 'secondary'
}

function createBackup(): void {
    if (!props.routes.store || createBackupProcessing.value) {
        return
    }

    if (backupScopes.value.includes('full_set')) {
        selectedCreateScope.value = settings.value.automatic_scope ?? 'full_set'
        createBackupDialogVisible.value = true

        return
    }

    submitCreateBackup('central_only')
}

function submitCreateBackup(scope: 'central_only' | 'full_set'): void {
    if (!props.routes.store || createBackupProcessing.value) {
        return
    }

    createBackupProcessing.value = true

    router.post(props.routes.store, { scope }, {
        onFinish: () => {
            createBackupProcessing.value = false
            createBackupDialogVisible.value = false
        },
        preserveScroll: true,
    })
}

function openImportDialog(): void {
    importForm.clearErrors()
    importDialogVisible.value = true
}

function selectImportFile(event: Event): void {
    const input = event.target as HTMLInputElement
    importForm.backup = input.files?.[0] ?? null
}

function importBackup(): void {
    if (!props.routes.import || importForm.processing) {
        return
    }

    if (importForm.backup === null) {
        importForm.setError(
            'backup',
            String(trans('database_backups.import_required')),
        )

        return
    }

    importForm.post(props.routes.import, {
        forceFormData: true,
        onSuccess: () => {
            importDialogVisible.value = false
            importForm.reset()
            importInputKey.value++
        },
        preserveScroll: true,
    })
}

function openSettingsDialog(): void {
    settingsDialogVisible.value = true
}

function openRestoreDialog(backup: DatabaseBackup): void {
    pendingRestoreBackup.value = backup
    restoreNotice.value = null
    restoreNoticeSeverity.value = 'success'
    restoreDialogVisible.value = true
}

function handleRestoreStarted(message: string): void {
    restoreNotice.value = message
    restoreNoticeSeverity.value = 'info'
}

function handleRestoreAccepted(message: string): void {
    restoreNotice.value = null
    toast.add({
        detail: message,
        life: 4000,
        severity: 'success',
        summary: trans('common.ui.saved'),
    })
    router.reload()
}

function handleRestoreFailed(message: string): void {
    restoreNotice.value = message
    restoreNoticeSeverity.value = 'error'
}

function deleteBackup(backup: DatabaseBackup): void {
    pendingDeleteBackup.value = backup
    deleteDialogVisible.value = true
}

function confirmDeleteBackup(): void {
    if (pendingDeleteBackup.value === null || deleteBackupProcessing.value) {
        return
    }

    deleteBackupProcessing.value = true

    router.delete(routeFor(props.routes.destroy, pendingDeleteBackup.value), {
        onFinish: () => {
            deleteBackupProcessing.value = false
            deleteDialogVisible.value = false
            pendingDeleteBackup.value = null
        },
        preserveScroll: true,
    })
}

function createScopeLabel(scope: 'central_only' | 'full_set'): string {
    return scope === 'full_set'
        ? 'Zentrale DB + Tenant-DBs'
        : 'Nur zentrale DB'
}

function automaticScopeSummary(): string {
    return settings.value.automatic_scope === 'central_only'
        ? 'Automatisch wird nur die zentrale Datenbank gesichert.'
        : 'Automatisch werden zentrale Datenbank und Tenant-Datenbanken gesichert.'
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <ConfirmActionDialog
            v-model:visible="deleteDialogVisible"
            :cancel-label="$t('common.ui.cancel')"
            :confirm-label="$t('common.ui.delete')"
            confirm-severity="danger"
            :description="$t('database_backups.delete_confirm')"
            :disabled="deleteBackupProcessing"
            icon="trash"
            :loading="deleteBackupProcessing"
            :message="
                pendingDeleteBackup?.name ?? $t('database_backups.delete')
            "
            :title="$t('database_backups.delete_header')"
            tone="danger"
            @confirm="confirmDeleteBackup"
        />

        <Dialog
            v-model:visible="createBackupDialogVisible"
            modal
            :draggable="false"
            header="Backup erstellen"
            :style="{ width: 'min(34rem, 94vw)' }"
        >
            <div class="grid gap-4">
                <p class="text-sm text-[var(--cp-text-secondary)]">
                    Wähle den Umfang für das neue Datenbank-Backup.
                </p>

                <div class="grid gap-2">
                    <Button
                        v-for="scope in backupScopes"
                        :key="scope"
                        :outlined="selectedCreateScope !== scope"
                        severity="secondary"
                        type="button"
                        @click="selectedCreateScope = scope"
                    >
                        {{ createScopeLabel(scope) }}
                    </Button>
                </div>

                <div class="flex justify-end gap-2">
                    <Button
                        :disabled="createBackupProcessing"
                        severity="secondary"
                        type="button"
                        @click="createBackupDialogVisible = false"
                    >
                        {{ trans('database_backups.cancel') }}
                    </Button>
                    <Button
                        :disabled="createBackupProcessing"
                        type="button"
                        @click="submitCreateBackup(selectedCreateScope)"
                    >
                        <AppIcon
                            v-if="createBackupProcessing"
                            name="refresh-cw"
                            class="cp-icon animate-spin"
                        />
                        {{ trans('database_backups.create') }}
                    </Button>
                </div>
            </div>
        </Dialog>

        <Message
            v-if="restoreNotice && restoreNoticeSeverity !== 'success'"
            :closable="false"
            :severity="restoreNoticeSeverity"
        >
            {{ restoreNotice }}
        </Message>

        <section
            class="rounded-lg border border-[var(--cp-surface-border)] bg-[var(--cp-surface-panel)] p-5 shadow-sm"
        >
            <div
                class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"
            >
                <div class="flex min-w-0 flex-col gap-3">
                    <div class="flex flex-wrap items-center gap-3">
                        <h2
                            class="text-lg font-semibold text-[var(--cp-text-primary)]"
                        >
                            {{ trans('database_backups.status_title') }}
                        </h2>
                        <Badge
                            :severity="latestBackup ? 'success' : 'secondary'"
                            :value="
                                latestBackup
                                    ? trans('database_backups.available')
                                    : trans('database_backups.empty_badge')
                            "
                        />
                    </div>

                    <div
                        class="grid gap-3 text-sm text-[var(--cp-text-secondary)] sm:grid-cols-3"
                    >
                        <div>
                            <span
                                class="block text-xs uppercase tracking-normal text-[var(--cp-text-muted)]"
                            >
                                {{ trans('database_backups.latest') }}
                            </span>
                            {{ formatDate(latestBackup?.created_at) }}
                        </div>
                        <div>
                            <span
                                class="block text-xs uppercase tracking-normal text-[var(--cp-text-muted)]"
                            >
                                {{ trans('database_backups.count') }}
                            </span>
                            {{ summary.count }}
                        </div>
                        <div>
                            <span
                                class="block text-xs uppercase tracking-normal text-[var(--cp-text-muted)]"
                            >
                                {{ trans('database_backups.total_size') }}
                            </span>
                            {{ formatSize(totalSize) }}
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="canUpdate"
                        :disabled="
                            createBackupProcessing || importForm.processing
                        "
                        severity="secondary"
                        type="button"
                        @click="openSettingsDialog"
                    >
                        <AppIcon name="settings" class="cp-icon" />
                        <span>
                            {{ trans('database_backups.settings_button') }}
                        </span>
                    </Button>
                    <Button
                        :disabled="
                            !canCreate ||
                            createBackupProcessing ||
                            importForm.processing
                        "
                        type="button"
                        @click="createBackup"
                    >
                        <AppIcon
                            :name="
                                createBackupProcessing
                                    ? 'refresh-cw'
                                    : 'download'
                            "
                            class="cp-icon"
                            :class="{
                                'animate-spin': createBackupProcessing,
                            }"
                        />
                        <span>{{ trans('database_backups.create') }}</span>
                    </Button>
                    <Button
                        :disabled="
                            !props.routes.import ||
                            !canCreate ||
                            createBackupProcessing ||
                            importForm.processing
                        "
                        severity="secondary"
                        type="button"
                        @click="openImportDialog"
                    >
                        <AppIcon name="upload" class="cp-icon" />
                        <span>{{ trans('database_backups.import') }}</span>
                    </Button>
                </div>
            </div>
        </section>

        <section
            class="rounded-lg border border-[var(--cp-surface-border)] bg-[var(--cp-surface-panel)] p-5 shadow-sm"
        >
            <div class="mb-4 flex flex-col gap-1">
                <h2 class="text-lg font-semibold text-[var(--cp-text-primary)]">
                    {{ trans('database_backups.automatic_title') }}
                </h2>
                <p class="text-sm text-[var(--cp-text-secondary)]">
                    {{
                        settings.automatic_enabled
                            ? trans('database_backups.automatic_enabled', {
                                  retention: retentionSummary,
                                  schedule: scheduleSummary,
                                  time: effectiveTime,
                                  timezone: settings.timezone,
                              })
                            : trans('database_backups.automatic_disabled')
                    }}
                </p>
                <p
                    v-if="settings.automatic_enabled"
                    class="text-sm text-[var(--cp-text-secondary)]"
                >
                    {{ automaticScopeSummary() }}
                </p>
            </div>
        </section>

        <section
            class="rounded-lg border border-[var(--cp-surface-border)] bg-[var(--cp-surface-panel)] shadow-sm"
        >
            <div
                class="grid gap-1 border-b border-[var(--cp-surface-border)] px-5 py-4"
            >
                <h2 class="text-lg font-semibold text-[var(--cp-text-primary)]">
                    {{ trans('database_backups.list_title') }}
                </h2>
            </div>

            <TableBuilderDataTable
                :empty-message="$t('database_backups.empty')"
                :schema="backupTableSchema"
            >
                <template #empty-state>
                    <div
                        class="grid justify-items-center gap-3 px-4 py-12 text-center"
                    >
                        <div
                            class="flex h-12 w-12 items-center justify-center rounded-full bg-[color-mix(in_srgb,var(--cp-surface-border)_18%,transparent)] text-[var(--cp-text-muted)]"
                        >
                            <AppIcon name="folder-open" />
                        </div>
                        <p class="text-sm text-[var(--cp-text-secondary)]">
                            {{ trans('database_backups.empty') }}
                        </p>
                    </div>
                </template>

                <template #cell-name="{ row }">
                    <div class="grid gap-1">
                        <span
                            class="block max-w-[28rem] truncate text-sm font-medium text-[var(--cp-text-primary)]"
                        >
                            {{ row.name }}
                        </span>
                        <span
                            v-if="(row.backup as DatabaseBackup).failed_tenants_count"
                            class="text-xs text-amber-600"
                        >
                            {{
                                `${(row.backup as DatabaseBackup).failed_tenants_count} Tenant-Fehler im Backup-Lauf`
                            }}
                        </span>
                    </div>
                </template>

                <template #cell-content_scope="{ row }">
                    <span
                        v-tooltip.top="
                            backupScopeLabel(row.backup as DatabaseBackup)
                        "
                        class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-[var(--cp-surface-border)] bg-[var(--cp-surface-ground)]"
                        :class="{
                            'text-sky-600':
                                backupScopeSeverity(
                                    row.backup as DatabaseBackup,
                                ) === 'info',
                            'text-[var(--cp-text-secondary)]':
                                backupScopeSeverity(
                                    row.backup as DatabaseBackup,
                                ) !== 'info',
                        }"
                        :aria-label="
                            backupScopeLabel(row.backup as DatabaseBackup)
                        "
                    >
                        <AppIcon
                            :name="backupScopeIcon(row.backup as DatabaseBackup)"
                            class="h-4 w-4"
                        />
                    </span>
                </template>

                <template #cell-source="{ row }">
                    <Badge
                        :severity="
                            sourceSeverity(
                                (row.backup as DatabaseBackup).source,
                            )
                        "
                        :value="
                            sourceLabel((row.backup as DatabaseBackup).source)
                        "
                    />
                </template>

                <template #cell-storage_locations="{ row }">
                    <div class="flex items-center gap-1.5">
                        <span
                            v-for="location in (row.backup as DatabaseBackup)
                                .storage_locations"
                            :key="location"
                            v-tooltip.top="
                                storageLocationLabel(String(location))
                            "
                            class="flex h-8 w-8 items-center justify-center rounded-md border border-[var(--cp-surface-border)] bg-[var(--cp-surface-ground)] text-[var(--cp-text-secondary)]"
                            :aria-label="storageLocationLabel(String(location))"
                        >
                            <AppIcon
                                :name="
                                    location === 'local'
                                        ? 'hard-drive'
                                        : 'cloud'
                                "
                                class="h-4 w-4"
                            />
                        </span>
                    </div>
                </template>

                <template #cell-encrypted="{ row }">
                    <Badge
                        :severity="
                            (row.backup as DatabaseBackup).encrypted
                                ? 'success'
                                : 'secondary'
                        "
                        :value="
                            (row.backup as DatabaseBackup).encrypted
                                ? trans('database_backups.encrypted')
                                : trans('database_backups.unencrypted')
                        "
                    />
                </template>

                <template #cell-created_at="{ row }">
                    <span class="text-sm text-[var(--cp-text-primary)]">
                        {{
                            formatDate(
                                (row.backup as DatabaseBackup).created_at,
                            )
                        }}
                    </span>
                </template>

                <template #cell-size="{ row }">
                    <span class="text-sm text-[var(--cp-text-primary)]">
                        {{ (row.backup as DatabaseBackup).size_for_humans }}
                    </span>
                </template>

                <template #row-actions="{ row }">
                    <div class="flex items-center justify-end gap-1.5">
                        <Button
                            v-tooltip.top="$t('database_backups.download')"
                            as="a"
                            :aria-label="$t('database_backups.download')"
                            class="cp-datatable__action-button"
                            :href="
                                routeFor(
                                    routes.download,
                                    row.backup as DatabaseBackup,
                                )
                            "
                            outlined
                            severity="secondary"
                            size="small"
                        >
                            <AppIcon name="download" />
                        </Button>
                        <Button
                            v-if="
                                routes.downloadSql &&
                                (row.backup as DatabaseBackup).kind !== 'set'
                            "
                            v-tooltip.top="$t('database_backups.download_sql')"
                            as="a"
                            :aria-label="$t('database_backups.download_sql')"
                            class="cp-datatable__action-button"
                            :href="
                                routeFor(
                                    routes.downloadSql,
                                    row.backup as DatabaseBackup,
                                )
                            "
                            outlined
                            severity="secondary"
                            size="small"
                        >
                            <AppIcon name="file-text" />
                        </Button>
                        <Button
                            v-if="routes.restore && routes.restoreStatus"
                            v-tooltip.top="$t('database_backups.restore')"
                            :aria-label="$t('database_backups.restore')"
                            class="cp-datatable__action-button"
                            :disabled="
                                !canRestore ||
                                createBackupProcessing ||
                                deleteBackupProcessing ||
                                importForm.processing
                            "
                            outlined
                            severity="warn"
                            size="small"
                            type="button"
                            @click="
                                openRestoreDialog(row.backup as DatabaseBackup)
                            "
                        >
                            <AppIcon name="rotate-cw" />
                        </Button>
                        <Button
                            v-tooltip.top="$t('database_backups.delete')"
                            :aria-label="$t('database_backups.delete')"
                            class="cp-datatable__action-button cp-datatable__action-button--danger"
                            :disabled="
                                !canDelete ||
                                createBackupProcessing ||
                                deleteBackupProcessing ||
                                importForm.processing
                            "
                            severity="danger"
                            size="small"
                            type="button"
                            @click="deleteBackup(row.backup as DatabaseBackup)"
                        >
                            <AppIcon name="trash" />
                        </Button>
                    </div>
                </template>
            </TableBuilderDataTable>
        </section>

        <DatabaseBackupSettingsDialog
            v-if="routes.settings && routes.emergencyKit"
            v-model:visible="settingsDialogVisible"
            :cloud-backup="cloudBackup"
            :emergency-kit-url="routes.emergencyKit"
            :settings="settings"
            :settings-url="routes.settings"
        />

        <Dialog
            v-if="routes.import"
            v-model:visible="importDialogVisible"
            modal
            :header="trans('database_backups.import_title')"
            :style="{ width: 'min(34rem, 94vw)' }"
        >
            <form class="grid gap-4" @submit.prevent="importBackup">
                <div class="grid gap-2">
                    <label
                        class="text-sm font-medium text-[var(--cp-text-primary)]"
                        for="database-backup-import-file"
                    >
                        {{ trans('database_backups.import_file') }}
                    </label>
                    <input
                        id="database-backup-import-file"
                        :key="importInputKey"
                        accept=".dump,.dump.enc,.zip,.zip.enc"
                        class="rounded-md border border-[var(--cp-surface-border)] bg-[var(--cp-surface-ground)] px-3 py-2 text-sm text-[var(--cp-text-primary)]"
                        type="file"
                        @change="selectImportFile"
                    />
                    <small class="text-[var(--cp-text-muted)]">
                        {{ trans('database_backups.import_help') }}
                    </small>
                    <Message
                        v-if="importForm.errors.backup"
                        severity="error"
                        size="small"
                    >
                        {{ importForm.errors.backup }}
                    </Message>
                </div>

                <progress
                    v-if="importForm.progress"
                    class="h-2 w-full"
                    max="100"
                    :value="importForm.progress.percentage"
                />

                <div class="flex justify-end gap-2">
                    <Button
                        :disabled="importForm.processing"
                        severity="secondary"
                        type="button"
                        @click="importDialogVisible = false"
                    >
                        {{ trans('database_backups.cancel') }}
                    </Button>
                    <Button :disabled="importForm.processing" type="submit">
                        <AppIcon
                            :name="
                                importForm.processing ? 'refresh-cw' : 'upload'
                            "
                            class="cp-icon"
                            :class="{ 'animate-spin': importForm.processing }"
                        />
                        <span>{{
                            trans('database_backups.import_submit')
                        }}</span>
                    </Button>
                </div>
            </form>
        </Dialog>

        <DatabaseBackupRestoreDialog
            v-if="routes.restore && routes.restoreStatus"
            v-model:visible="restoreDialogVisible"
            :backup="pendingRestoreBackup"
            :restore-url="
                pendingRestoreBackup
                    ? routeFor(routes.restore, pendingRestoreBackup)
                    : ''
            "
            :status-url-template="routes.restoreStatus"
            :table-options="tableOptions"
            @restore-accepted="handleRestoreAccepted"
            @restore-failed="handleRestoreFailed"
            @restore-started="handleRestoreStarted"
        />
    </div>
</template>

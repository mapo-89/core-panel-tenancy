<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { trans } from 'laravel-vue-i18n'
import { computed, onUnmounted, ref, watch } from 'vue'

import AppIcon from '@core-panel/components/AppIcon.vue'

type DatabaseBackup = {
    contains_tenants?: boolean
    created_at: string
    encrypted: boolean
    kind?: 'legacy' | 'set'
    name: string
    restore_scopes?: string[]
    source: 'automatic' | 'custom' | 'imported' | 'manual'
    size: number
    size_for_humans: string
    tenant_options?: Array<{ id: string; label: string }>
}

type TableOption = {
    dependencies: string[]
    label: string
    value: string
}

type RestoreMode =
    | 'all'
    | 'tables'
    | 'central_only'
    | 'central_tables'
    | 'single_tenant'
    | 'tenant_tables'
    | 'full_set'

type SetRestoreScope = 'central' | 'tenant' | 'full_set'
type SetRestoreDepth = 'all' | 'tables'

type RestoreErrorResponse = {
    errors?: Record<string, string[]>
    message?: string
}

type RestoreStartResponse = {
    message?: string
    restore?: {
        id: string
        status_url: string
    } | null
}

type RestoreStatusResponse = {
    restore?: {
        finished_at: string | null
        id: string
        message: string | null
        message_key: string | null
        status: 'running' | 'completed' | 'failed' | 'unknown'
    }
}

const props = defineProps<{
    backup: DatabaseBackup | null
    restoreUrl: string
    statusUrlTemplate: string
    tableOptions:
        | TableOption[]
        | {
              central: TableOption[]
              tenant: TableOption[]
          }
    visible: boolean
}>()

const emit = defineEmits<{
    'restore-accepted': [message: string]
    'restore-failed': [message: string]
    'restore-started': [message: string]
    'update:visible': [visible: boolean]
}>()

const dialogVisible = computed({
    get: () => props.visible,
    set: (visible: boolean) => emit('update:visible', visible),
})

const restoreForm = useForm({
    confirmation: '',
    mode: 'all' as RestoreMode,
    tables: [] as string[],
    tenant_id: '',
})
const directTables = ref<string[]>([])
const selectedTenantId = ref('')
const selectedSetScope = ref<SetRestoreScope>('central')
const selectedSetRestoreDepth = ref<SetRestoreDepth>('all')

const centralTableOptions = computed(() =>
    Array.isArray(props.tableOptions)
        ? props.tableOptions
        : props.tableOptions.central ?? [],
)
const tenantTableOptions = computed(() =>
    Array.isArray(props.tableOptions) ? props.tableOptions : props.tableOptions.tenant ?? [],
)
const activeTableOptions = computed(() => {
    if (!isSetBackup.value) {
        return centralTableOptions.value
    }

    return selectedSetScope.value === 'tenant'
        ? tenantTableOptions.value
        : centralTableOptions.value
})
const dependencyMap = computed(() =>
    activeTableOptions.value.reduce<Record<string, string[]>>(
        (dependencies, table) => {
            dependencies[table.value] = table.dependencies

            return dependencies
        },
        {},
    ),
)

const tableLabels = computed(() =>
    activeTableOptions.value.reduce<Record<string, string>>((labels, table) => {
        labels[table.value] = table.label

        return labels
    }, {}),
)

const selectedTables = computed(() => expandTables(directTables.value))
const dependencyTables = computed(() =>
    selectedTables.value.filter((table) => !directTables.value.includes(table)),
)
const dependencySummary = computed(() =>
    dependencyTables.value.map((table) => tableLabels.value[table] ?? table),
)
const confirmationAccepted = computed(
    () => restoreForm.confirmation.trim().toUpperCase() === 'RESTORE',
)
const isSetBackup = computed(() => props.backup?.kind === 'set')
const availableRestoreScopes = computed(() => props.backup?.restore_scopes ?? [])
const tenantOptions = computed(() => props.backup?.tenant_options ?? [])
const hasCentralScope = computed(() => availableRestoreScopes.value.includes('central_only'))
const hasTenantScope = computed(() => availableRestoreScopes.value.includes('single_tenant'))
const hasFullSetScope = computed(() => availableRestoreScopes.value.includes('full_set'))
const canSelectCentralTables = computed(() => centralTableOptions.value.length > 0)
const canSelectTenantTables = computed(() => tenantTableOptions.value.length > 0)
const standardRestoreOptions = computed<
    Array<{
        disabled?: boolean
        label: string
        value: 'all' | 'tables'
    }>
>(() => [
    {
        label: trans('database_backups.restore_all'),
        value: 'all',
    },
    {
        disabled: !canSelectCentralTables.value,
        label: trans('database_backups.restore_tables'),
        value: 'tables',
    },
])
const restoreProcessing = ref(false)
const restoreStatus = ref<'idle' | 'running' | 'accepted' | 'failed'>('idle')
const acceptedRestoreMessage = ref<string | null>(null)
const restoreStatusUrl = ref<string | null>(null)
const restoreStatusTimer = ref<number | null>(null)
const submittedMode = ref<RestoreMode>('all')
const acceptedMessage = computed(
    () =>
        acceptedRestoreMessage.value ??
        ((submittedMode.value === 'all' ||
            submittedMode.value === 'central_only' ||
            submittedMode.value === 'single_tenant' ||
            submittedMode.value === 'full_set')
            ? trans('database_backups.restore_started')
            : trans('database_backups.restored')),
)
const restoreError = computed(
    () =>
        (restoreForm.errors as Record<string, string | undefined>).restore ??
        null,
)
const statusMessage = computed(() => {
    if (
        restoreStatus.value === 'failed' &&
        !restoreForm.errors.confirmation &&
        !restoreForm.errors.mode &&
        !restoreError.value &&
        !restoreForm.errors.tables
    ) {
        return trans('database_backups.restore_request_failed')
    }

    return null
})
const statusSeverity = computed<'error' | 'info' | 'success'>(() => {
    if (restoreStatus.value === 'failed') {
        return 'error'
    }

    return 'info'
})

watch(
    () => props.visible,
    (visible) => {
        if (!visible) {
            return
        }

        restoreForm.defaults({
            confirmation: '',
            mode: defaultMode(),
            tables: [],
            tenant_id: '',
        })
        restoreForm.reset()
        restoreForm.clearErrors()
        directTables.value = []
        selectedTenantId.value = ''
        selectedSetScope.value = defaultSetScope()
        selectedSetRestoreDepth.value = 'all'
        acceptedRestoreMessage.value = null
        restoreStatusUrl.value = null
        stopRestoreStatusPolling()
        restoreStatus.value = 'idle'
        submittedMode.value = defaultMode()
        syncSetRestoreMode()
    },
)

watch(selectedTables, (tables) => {
    restoreForm.tables = tables
})

function expandTables(tables: string[]): string[] {
    const expanded: string[] = []
    const queue = [...new Set(tables)]

    while (queue.length > 0) {
        const table = queue.shift()

        if (!table || expanded.includes(table)) {
            continue
        }

        expanded.push(table)

        for (const dependency of dependencyMap.value[table] ?? []) {
            if (!expanded.includes(dependency)) {
                queue.push(dependency)
            }
        }
    }

    return expanded
}

function isDependencyOnly(table: string): boolean {
    return (
        selectedTables.value.includes(table) &&
        !directTables.value.includes(table)
    )
}

function toggleTable(table: string): void {
    if (isDependencyOnly(table)) {
        return
    }

    directTables.value = directTables.value.includes(table)
        ? directTables.value.filter((value) => value !== table)
        : [...directTables.value, table]
}

function defaultMode(): RestoreMode {
    if (!isSetBackup.value) {
        return 'all'
    }

    if (defaultSetScope() === 'full_set') {
        return 'full_set'
    }

    return defaultSetScope() === 'tenant' ? 'single_tenant' : 'central_only'
}

function selectRestoreMode(mode: 'all' | 'tables'): void {
    restoreForm.mode = mode

    if (mode === 'all') {
        directTables.value = []
        restoreForm.tables = []
    }

    if (mode !== 'tables') {
        directTables.value = []
        restoreForm.tables = []
    }

    selectedTenantId.value = ''
    restoreForm.tenant_id = ''
}

function defaultSetScope(): SetRestoreScope {
    if (hasCentralScope.value) {
        return 'central'
    }

    if (hasTenantScope.value) {
        return 'tenant'
    }

    return 'full_set'
}

function syncSetRestoreMode(): void {
    if (!isSetBackup.value) {
        return
    }

    if (selectedSetScope.value === 'full_set') {
        restoreForm.mode = 'full_set'
        directTables.value = []
        restoreForm.tables = []
        selectedTenantId.value = ''
        restoreForm.tenant_id = ''

        return
    }

    const wantsTables = selectedSetRestoreDepth.value === 'tables'
    const tablesAvailable =
        selectedSetScope.value === 'tenant'
            ? canSelectTenantTables.value
            : canSelectCentralTables.value

    if (wantsTables && !tablesAvailable) {
        selectedSetRestoreDepth.value = 'all'
    }

    if (selectedSetRestoreDepth.value === 'tables') {
        restoreForm.mode =
            selectedSetScope.value === 'tenant'
                ? 'tenant_tables'
                : 'central_tables'

        return
    }

    restoreForm.mode =
        selectedSetScope.value === 'tenant' ? 'single_tenant' : 'central_only'
    directTables.value = []
    restoreForm.tables = []
}

function selectSetRestoreScope(scope: SetRestoreScope): void {
    selectedSetScope.value = scope
    directTables.value = []
    restoreForm.tables = []

    if (scope !== 'tenant') {
        selectedTenantId.value = ''
        restoreForm.tenant_id = ''
    }

    syncSetRestoreMode()
}

function selectSetRestoreDepth(depth: SetRestoreDepth): void {
    selectedSetRestoreDepth.value = depth
    directTables.value = []
    restoreForm.tables = []
    syncSetRestoreMode()
}

function csrfToken(): string | null {
    return (
        document
            .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? null
    )
}

function xsrfToken(): string | null {
    const matches = document.cookie.match(/(^|;\s*)XSRF-TOKEN=([^;]*)/)

    return matches?.[2] ? decodeURIComponent(matches[2]) : null
}

function markRestoreAccepted(message?: string): void {
    acceptedRestoreMessage.value = message ?? null
    restoreStatus.value = 'accepted'
    emit('restore-accepted', acceptedMessage.value)
    dialogVisible.value = false
}

function markRestoreFailed(message?: string): void {
    const restoreFailedMessage =
        message ?? trans('database_backups.restore_failed')

    restoreStatus.value = 'failed'
    emit('restore-failed', restoreFailedMessage)
    dialogVisible.value = false
}

function statusUrlFor(restoreId: string): string {
    return props.statusUrlTemplate.replace(
        '__RESTORE__',
        encodeURIComponent(restoreId),
    )
}

function stopRestoreStatusPolling(): void {
    if (restoreStatusTimer.value === null) {
        return
    }

    window.clearInterval(restoreStatusTimer.value)
    restoreStatusTimer.value = null
}

function restoreStatusMessage(
    restore: RestoreStatusResponse['restore'] | undefined,
    fallbackKey: string,
): string {
    if (restore?.message_key) {
        return trans(restore.message_key)
    }

    return restore?.message ?? trans(fallbackKey)
}

async function pollRestoreStatus(): Promise<void> {
    if (restoreStatusUrl.value === null) {
        return
    }

    let body: RestoreStatusResponse | undefined

    try {
        const response = await fetch(restoreStatusUrl.value, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
            },
            method: 'GET',
        })

        body = (await response.json().catch(() => ({}))) as
            | RestoreStatusResponse
            | undefined
    } catch {
        return
    }

    const status = body?.restore?.status

    if (status === 'completed') {
        stopRestoreStatusPolling()
        markRestoreAccepted(
            restoreStatusMessage(body?.restore, 'database_backups.restored'),
        )

        return
    }

    if (status === 'failed' || status === 'unknown') {
        markRestoreFailed(
            restoreStatusMessage(
                body?.restore,
                'database_backups.restore_failed',
            ),
        )
        stopRestoreStatusPolling()
    }
}

function startRestoreStatusPolling(statusUrl: string): void {
    restoreStatusUrl.value = statusUrl
    stopRestoreStatusPolling()
    restoreStatusTimer.value = window.setInterval(() => {
        void pollRestoreStatus()
    }, 2000)
    void pollRestoreStatus()
}

function setRestoreErrors(errors: Record<string, string[]>): void {
    Object.entries(errors).forEach(([field, messages]) => {
        const message = messages[0]

        if (message) {
            restoreForm.setError(
                field as keyof typeof restoreForm.errors,
                message,
            )
        }
    })
}

async function submitRestore(): Promise<void> {
    if (props.backup === null || props.restoreUrl === '') {
        return
    }

    if (isSetBackup.value) {
        syncSetRestoreMode()
    }

    const mode = restoreForm.mode

    submittedMode.value = mode
    restoreForm.confirmation = restoreForm.confirmation.trim().toUpperCase()
    restoreForm.tables =
        mode === 'tables' ||
        mode === 'central_tables' ||
        mode === 'tenant_tables'
            ? selectedTables.value
            : []
    restoreForm.tenant_id =
        mode === 'single_tenant' || mode === 'tenant_tables'
            ? selectedTenantId.value
            : ''

    restoreForm.clearErrors()
    acceptedRestoreMessage.value = null
    restoreProcessing.value = true
    restoreStatus.value = 'running'

    try {
        const token = csrfToken()
        const xsrf = xsrfToken()
        const response = await fetch(props.restoreUrl, {
            body: JSON.stringify({
                confirmation: restoreForm.confirmation,
                mode,
                tables:
                    mode === 'tables' ||
                    mode === 'central_tables' ||
                    mode === 'tenant_tables'
                        ? selectedTables.value
                        : [],
                tenant_id:
                    mode === 'single_tenant' || mode === 'tenant_tables'
                        ? selectedTenantId.value
                        : null,
            }),
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                ...(token ? { 'X-CSRF-TOKEN': token } : {}),
                ...(xsrf ? { 'X-XSRF-TOKEN': xsrf } : {}),
            },
            method: 'POST',
        })

        const body = (await response.json().catch(() => ({}))) as
            | (RestoreErrorResponse & RestoreStartResponse)
            | undefined

        if (!response.ok) {
            if (body?.errors) {
                setRestoreErrors(body.errors)
                markRestoreFailed(Object.values(body.errors).flat()[0])
            } else {
                markRestoreFailed(
                    body?.message ??
                        trans('database_backups.restore_request_failed'),
                )
            }

            return
        }

        acceptedRestoreMessage.value = body?.message ?? null
        emit(
            'restore-started',
            body?.message ?? trans('database_backups.restore_requesting'),
        )
        dialogVisible.value = false

        if (body?.restore?.status_url) {
            startRestoreStatusPolling(body.restore.status_url)
        } else if (body?.restore?.id) {
            startRestoreStatusPolling(statusUrlFor(body.restore.id))
        } else {
            markRestoreAccepted(body?.message)
        }
    } catch {
        markRestoreFailed(trans('database_backups.restore_request_failed'))
    } finally {
        restoreProcessing.value = false
    }
}

onUnmounted(() => {
    stopRestoreStatusPolling()
})

const activeTableSectionTitle = computed(() => {
    if (!isSetBackup.value) {
        return trans('database_backups.restore_tables')
    }

    return selectedSetScope.value === 'tenant'
        ? trans('database_backups.restore_tables_tenant')
        : trans('database_backups.restore_tables_central')
})

const showSetRestoreDepthSelection = computed(
    () => isSetBackup.value && selectedSetScope.value !== 'full_set',
)

const needsTableSelection = computed(
    () =>
        restoreForm.mode === 'tables' ||
        restoreForm.mode === 'central_tables' ||
        restoreForm.mode === 'tenant_tables',
)

const needsTenantSelection = computed(
    () =>
        restoreForm.mode === 'single_tenant' ||
        restoreForm.mode === 'tenant_tables',
)

const activeTablesSelectable = computed(() => {
    if (!isSetBackup.value) {
        return canSelectCentralTables.value
    }

    return selectedSetScope.value === 'tenant'
        ? canSelectTenantTables.value
        : canSelectCentralTables.value
})

function setScopeDescription(scope: SetRestoreScope): string {
    if (scope === 'tenant') {
        return trans('database_backups.restore_scope_tenant')
    }

    if (scope === 'full_set') {
        return trans('database_backups.restore_scope_full_set')
    }

    return trans('database_backups.restore_scope_central')
}
</script>

<template>
    <Dialog
        v-model:visible="dialogVisible"
        modal
        :draggable="false"
        :header="trans('database_backups.restore_title')"
        class="w-[min(42rem,calc(100vw-2rem))]"
    >
        <form class="grid gap-5" @submit.prevent="submitRestore">
            <div
                class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900"
            >
                <div class="flex items-start gap-3">
                    <AppIcon name="triangle-alert" class="mt-0.5 shrink-0" />
                    <p>
                        {{ trans('database_backups.restore_warning') }}
                    </p>
                </div>
            </div>

            <div v-if="backup" class="grid gap-1">
                <span class="text-xs text-[var(--cp-text-muted)]">
                    {{ trans('database_backups.file') }}
                </span>
                <strong
                    class="break-all font-mono text-sm text-[var(--cp-text-primary)]"
                >
                    {{ backup.name }}
                </strong>
            </div>

            <Message
                v-if="statusMessage"
                :closable="false"
                :severity="statusSeverity"
            >
                {{ statusMessage }}
            </Message>

            <section class="grid gap-3">
                <h3
                    class="text-base font-semibold text-[var(--cp-text-primary)]"
                >
                    {{ trans('database_backups.restore_scope') }}
                </h3>
                <div v-if="isSetBackup" class="grid gap-2 sm:grid-cols-3">
                    <Button
                        v-if="hasCentralScope"
                        :outlined="selectedSetScope !== 'central'"
                        severity="secondary"
                        type="button"
                        @click="selectSetRestoreScope('central')"
                    >
                        {{ setScopeDescription('central') }}
                    </Button>
                    <Button
                        v-if="hasTenantScope"
                        :outlined="selectedSetScope !== 'tenant'"
                        severity="secondary"
                        type="button"
                        @click="selectSetRestoreScope('tenant')"
                    >
                        {{ setScopeDescription('tenant') }}
                    </Button>
                    <Button
                        v-if="hasFullSetScope"
                        :outlined="selectedSetScope !== 'full_set'"
                        severity="secondary"
                        type="button"
                        @click="selectSetRestoreScope('full_set')"
                    >
                        {{ setScopeDescription('full_set') }}
                    </Button>
                </div>
                <div v-else class="grid gap-2 sm:grid-cols-2">
                    <Button
                        v-for="option in standardRestoreOptions"
                        :key="option.value"
                        :disabled="option.disabled"
                        :outlined="restoreForm.mode !== option.value"
                        severity="secondary"
                        type="button"
                        @click="selectRestoreMode(option.value)"
                    >
                        {{ option.label }}
                    </Button>
                </div>
            </section>

            <section
                v-if="showSetRestoreDepthSelection"
                class="grid gap-3"
            >
                <h3
                    class="text-base font-semibold text-[var(--cp-text-primary)]"
                >
                    {{ trans('database_backups.restore_depth') }}
                </h3>
                <div class="grid gap-2 sm:grid-cols-2">
                    <Button
                        :outlined="selectedSetRestoreDepth !== 'all'"
                        severity="secondary"
                        type="button"
                        @click="selectSetRestoreDepth('all')"
                    >
                        {{ trans('database_backups.restore_all') }}
                    </Button>
                    <Button
                        :disabled="!activeTablesSelectable"
                        :outlined="selectedSetRestoreDepth !== 'tables'"
                        severity="secondary"
                        type="button"
                        @click="selectSetRestoreDepth('tables')"
                    >
                        {{ trans('database_backups.restore_tables') }}
                    </Button>
                </div>
            </section>

            <section
                v-if="needsTenantSelection"
                class="grid gap-3"
            >
                <label
                    class="grid gap-1 text-sm font-medium text-[var(--cp-text-secondary)]"
                >
                    {{ trans('database_backups.tenant') }}
                    <select
                        v-model="selectedTenantId"
                        class="rounded-md border border-[var(--cp-surface-border)] bg-[var(--cp-surface-panel)] px-3 py-2 text-[var(--cp-text-primary)]"
                    >
                        <option value="">
                            {{ trans('database_backups.tenant_select_placeholder') }}
                        </option>
                        <option
                            v-for="tenant in tenantOptions"
                            :key="tenant.id"
                            :value="tenant.id"
                        >
                            {{ tenant.label }}
                        </option>
                    </select>
                </label>
                <small v-if="restoreForm.errors.tenant_id" class="text-red-600">
                    {{ restoreForm.errors.tenant_id }}
                </small>
            </section>

            <section
                v-if="needsTableSelection"
                class="grid gap-3"
            >
                <h3
                    class="text-base font-semibold text-[var(--cp-text-primary)]"
                >
                    {{ activeTableSectionTitle }}
                </h3>
                <div
                    class="rounded-md border border-sky-200 bg-sky-50 p-3 text-sm text-sky-900"
                >
                    <div class="flex items-start gap-2">
                        <AppIcon name="info" class="mt-0.5 shrink-0" />
                        <p>
                            {{
                                trans('database_backups.restore_tables_warning')
                            }}
                        </p>
                    </div>
                </div>
                <div
                    v-if="dependencySummary.length > 0"
                    class="rounded-md border border-[var(--cp-surface-border)] bg-[var(--cp-surface-muted)] p-3 text-sm text-[var(--cp-text-secondary)]"
                >
                    {{
                        trans('database_backups.restore_dependency_summary', {
                            tables: dependencySummary.join(', '),
                        })
                    }}
                </div>
                <div
                    class="grid max-h-56 gap-2 overflow-y-auto rounded-md border border-[var(--cp-surface-border)] bg-[var(--cp-surface-panel)] p-3 sm:grid-cols-2"
                >
                    <label
                        v-for="table in activeTableOptions"
                        :key="table.value"
                        class="inline-flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-[var(--cp-text-primary)]"
                    >
                        <Checkbox
                            :model-value="selectedTables.includes(table.value)"
                            binary
                            :disabled="isDependencyOnly(table.value)"
                            @update:model-value="toggleTable(table.value)"
                        />
                        <span class="truncate font-mono">{{
                            table.label
                        }}</span>
                        <Badge
                            v-if="isDependencyOnly(table.value)"
                            :value="
                                trans(
                                    'database_backups.restore_dependency_badge',
                                )
                            "
                            severity="info"
                        />
                    </label>
                </div>
                    <small v-if="restoreForm.errors.tables" class="text-red-600">
                    {{ restoreForm.errors.tables }}
                </small>
            </section>

            <label
                class="grid gap-1 text-sm font-medium text-[var(--cp-text-secondary)]"
            >
                {{ trans('database_backups.restore_confirmation_label') }}
                <input
                    v-model="restoreForm.confirmation"
                    class="rounded-md border border-[var(--cp-surface-border)] bg-[var(--cp-surface-panel)] px-3 py-2 font-mono text-[var(--cp-text-primary)]"
                    type="text"
                />
            </label>
            <small v-if="restoreForm.errors.confirmation" class="text-red-600">
                {{ restoreForm.errors.confirmation }}
            </small>
            <small v-if="restoreForm.errors.mode" class="text-red-600">
                {{ restoreForm.errors.mode }}
            </small>
            <small v-if="restoreError" class="text-red-600">
                {{ restoreError }}
            </small>
            <div
                class="flex justify-end gap-2 border-t border-[var(--cp-surface-border)] pt-4"
            >
                <Button
                    severity="secondary"
                    type="button"
                    :disabled="restoreStatus === 'running'"
                    @click="dialogVisible = false"
                >
                    {{ trans('database_backups.cancel') }}
                </Button>
                <Button
                    :disabled="
                        !confirmationAccepted ||
                        restoreStatus === 'accepted' ||
                        restoreStatus === 'running' ||
                        (needsTableSelection && selectedTables.length === 0) ||
                        (needsTenantSelection && selectedTenantId === '')
                    "
                    severity="danger"
                    type="button"
                    @click="submitRestore"
                >
                    <AppIcon
                        name="refresh-cw"
                        class="cp-icon"
                        :class="{
                            'animate-spin':
                                restoreProcessing ||
                                restoreStatus === 'running',
                        }"
                    />
                    <span>{{ trans('database_backups.restore') }}</span>
                </Button>
            </div>
        </form>
    </Dialog>
</template>

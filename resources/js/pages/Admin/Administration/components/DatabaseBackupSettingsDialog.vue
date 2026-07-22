<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { trans } from 'laravel-vue-i18n'
import { computed, ref, watch } from 'vue'

import AppIcon from '@core-panel/components/AppIcon.vue'

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

const props = defineProps<{
    cloudBackup: DatabaseBackupCloudStatus
    emergencyKitUrl: string
    settings: DatabaseBackupSettings
    settingsUrl: string
    visible: boolean
}>()

const emit = defineEmits<{
    'update:visible': [visible: boolean]
}>()

const activeTab = ref<'cloud' | 'retention' | 'schedule' | 'security'>(
    'schedule',
)
const dialogVisible = computed({
    get: () => props.visible,
    set: (visible: boolean) => emit('update:visible', visible),
})

const settingsForm = useForm({
    automatic_enabled: props.settings.automatic_enabled,
    automatic_scope: props.settings.automatic_scope ?? 'full_set',
    cloud_backup_enabled: props.settings.cloud_backup_enabled,
    cloud_backup_path: props.settings.cloud_backup_path,
    encryption_code: props.settings.encryption_code,
    encryption_enabled: props.settings.encryption_enabled,
    retention_count: props.settings.retention_count,
    retention_days: props.settings.retention_days,
    retention_mode: props.settings.retention_mode,
    schedule_mode: props.settings.schedule_mode,
    time: props.settings.time,
    time_mode: props.settings.time_mode,
    weekdays: [...props.settings.weekdays],
})

const tabs = [
    {
        icon: 'calendar-check',
        key: 'schedule',
        label: 'database_backups.settings_schedule',
    },
    {
        icon: 'shield',
        key: 'security',
        label: 'database_backups.encryption',
    },
    {
        icon: 'cloud',
        key: 'cloud',
        label: 'database_backups.settings_cloud',
    },
    {
        icon: 'activity',
        key: 'retention',
        label: 'database_backups.settings_retention',
    },
] as const

const weekdayOptions = [
    'monday',
    'tuesday',
    'wednesday',
    'thursday',
    'friday',
    'saturday',
    'sunday',
]

watch(
    () => props.visible,
    (visible) => {
        if (!visible) {
            return
        }

        activeTab.value = 'schedule'
        settingsForm.defaults({
            automatic_enabled: props.settings.automatic_enabled,
            automatic_scope: props.settings.automatic_scope ?? 'full_set',
            cloud_backup_enabled: props.settings.cloud_backup_enabled,
            cloud_backup_path: props.settings.cloud_backup_path,
            encryption_code: props.settings.encryption_code,
            encryption_enabled: props.settings.encryption_enabled,
            retention_count: props.settings.retention_count,
            retention_days: props.settings.retention_days,
            retention_mode: props.settings.retention_mode,
            schedule_mode: props.settings.schedule_mode,
            time: props.settings.time,
            time_mode: props.settings.time_mode,
            weekdays: [...props.settings.weekdays],
        })
        settingsForm.reset()
        if (!props.cloudBackup.available) {
            settingsForm.cloud_backup_enabled = false
        }
        settingsForm.clearErrors()
    },
)

function copyEncryptionCode(): void {
    void navigator.clipboard.writeText(settingsForm.encryption_code)
}

function downloadEmergencyKit(): void {
    window.location.href = props.emergencyKitUrl
}

function generateEncryptionCode(): void {
    const bytes = new Uint8Array(16)
    window.crypto.getRandomValues(bytes)
    const code = Array.from(bytes)
        .map((byte) => byte.toString(16).padStart(2, '0'))
        .join('')
        .toUpperCase()
        .match(/.{1,4}/g)
        ?.join('-')

    settingsForm.encryption_code = code ?? settingsForm.encryption_code
}

function saveSettings(): void {
    if (!props.cloudBackup.available) {
        settingsForm.cloud_backup_enabled = false
    }

    settingsForm.put(props.settingsUrl, {
        preserveScroll: true,
        onSuccess: () => {
            dialogVisible.value = false
        },
    })
}

function toggleWeekday(weekday: string): void {
    settingsForm.weekdays = settingsForm.weekdays.includes(weekday)
        ? settingsForm.weekdays.filter((value) => value !== weekday)
        : [...settingsForm.weekdays, weekday]
}
</script>

<template>
    <Dialog
        v-model:visible="dialogVisible"
        modal
        :draggable="false"
        :header="trans('database_backups.settings_title')"
        class="w-[min(46rem,calc(100vw-2rem))]"
    >
        <form class="flex flex-col gap-5" @submit.prevent="saveSettings">
            <div
                class="grid gap-2 rounded-lg border border-[var(--cp-surface-border)] bg-[var(--cp-surface-panel-alt)] p-1 sm:grid-cols-4"
            >
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    class="inline-flex items-center justify-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition"
                    :class="
                        activeTab === tab.key
                            ? 'bg-[var(--cp-surface-panel)] text-[var(--cp-text-primary)] shadow-sm'
                            : 'text-[var(--cp-text-secondary)] hover:text-[var(--cp-text-primary)]'
                    "
                    type="button"
                    @click="activeTab = tab.key"
                >
                    <AppIcon :name="tab.icon" class="cp-icon" />
                    <span>{{ trans(tab.label) }}</span>
                </button>
            </div>

            <div class="min-h-[22rem]">
                <section v-if="activeTab === 'schedule'" class="grid gap-5">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <h3
                                class="text-base font-semibold text-[var(--cp-text-primary)]"
                            >
                                {{
                                    trans('database_backups.settings_automatic')
                                }}
                            </h3>
                            <p class="text-sm text-[var(--cp-text-secondary)]">
                                {{
                                    trans(
                                        'database_backups.settings_automatic_help',
                                    )
                                }}
                            </p>
                        </div>
                        <div
                            class="flex min-w-[4.5rem] shrink-0 justify-end self-start"
                        >
                            <ToggleSwitch
                                v-model="settingsForm.automatic_enabled"
                            />
                        </div>
                    </div>

                    <div class="grid gap-3">
                        <h3
                            class="text-base font-semibold text-[var(--cp-text-primary)]"
                        >
                            Backup-Umfang
                        </h3>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <Button
                                :outlined="
                                    settingsForm.automatic_scope !==
                                    'central_only'
                                "
                                severity="secondary"
                                type="button"
                                @click="
                                    settingsForm.automatic_scope =
                                        'central_only'
                                "
                            >
                                Nur zentrale DB
                            </Button>
                            <Button
                                :outlined="
                                    settingsForm.automatic_scope !== 'full_set'
                                "
                                severity="secondary"
                                type="button"
                                @click="settingsForm.automatic_scope = 'full_set'"
                            >
                                Zentrale DB + Tenant-DBs
                            </Button>
                        </div>
                        <p class="text-sm text-[var(--cp-text-secondary)]">
                            Lege fest, ob automatische Läufe nur die zentrale
                            Datenbank oder das vollständige Backup-Set
                            erzeugen.
                        </p>
                        <small
                            v-if="settingsForm.errors.automatic_scope"
                            class="text-red-600"
                        >
                            {{ settingsForm.errors.automatic_scope }}
                        </small>
                    </div>

                    <div class="grid gap-3">
                        <h3
                            class="text-base font-semibold text-[var(--cp-text-primary)]"
                        >
                            {{ trans('database_backups.settings_schedule') }}
                        </h3>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <Button
                                :outlined="
                                    settingsForm.schedule_mode !== 'daily'
                                "
                                severity="secondary"
                                type="button"
                                @click="settingsForm.schedule_mode = 'daily'"
                            >
                                {{ trans('database_backups.schedule_daily') }}
                            </Button>
                            <Button
                                :outlined="
                                    settingsForm.schedule_mode !== 'custom'
                                "
                                severity="secondary"
                                type="button"
                                @click="settingsForm.schedule_mode = 'custom'"
                            >
                                {{ trans('database_backups.schedule_custom') }}
                            </Button>
                        </div>

                        <div
                            v-if="settingsForm.schedule_mode === 'custom'"
                            class="grid grid-cols-2 gap-2 sm:grid-cols-4"
                        >
                            <button
                                v-for="weekday in weekdayOptions"
                                :key="weekday"
                                type="button"
                                class="rounded-md border px-3 py-2 text-sm font-medium transition"
                                :class="
                                    settingsForm.weekdays.includes(weekday)
                                        ? 'border-[var(--cp-primary)] bg-[color-mix(in_srgb,var(--cp-primary)_10%,transparent)] text-[var(--cp-primary)]'
                                        : 'border-[var(--cp-surface-border)] bg-[var(--cp-surface-panel)] text-[var(--cp-text-secondary)]'
                                "
                                @click="toggleWeekday(weekday)"
                            >
                                {{
                                    trans(
                                        `database_backups.weekdays.${weekday}`,
                                    )
                                }}
                            </button>
                        </div>
                        <small
                            v-if="settingsForm.errors.weekdays"
                            class="text-red-600"
                        >
                            {{ settingsForm.errors.weekdays }}
                        </small>
                    </div>

                    <div class="grid gap-3">
                        <h3
                            class="text-base font-semibold text-[var(--cp-text-primary)]"
                        >
                            {{ trans('database_backups.settings_time') }}
                        </h3>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <Button
                                :outlined="settingsForm.time_mode !== 'system'"
                                severity="secondary"
                                type="button"
                                @click="settingsForm.time_mode = 'system'"
                            >
                                {{
                                    trans('database_backups.time_system', {
                                        time: settings.system_time,
                                    })
                                }}
                            </Button>
                            <Button
                                :outlined="settingsForm.time_mode !== 'custom'"
                                severity="secondary"
                                type="button"
                                @click="settingsForm.time_mode = 'custom'"
                            >
                                {{ trans('database_backups.time_custom') }}
                            </Button>
                        </div>
                        <label
                            v-if="settingsForm.time_mode === 'custom'"
                            class="flex flex-col gap-1 text-sm font-medium text-[var(--cp-text-secondary)]"
                        >
                            {{ trans('database_backups.time_label') }}
                            <input
                                v-model="settingsForm.time"
                                class="rounded-md border border-[var(--cp-surface-border)] bg-[var(--cp-surface-panel)] px-3 py-2 text-[var(--cp-text-primary)]"
                                type="time"
                            />
                        </label>
                        <small
                            v-if="settingsForm.errors.time"
                            class="text-red-600"
                        >
                            {{ settingsForm.errors.time }}
                        </small>
                    </div>
                </section>

                <section
                    v-else-if="activeTab === 'security'"
                    class="grid gap-5"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <h3
                                class="text-base font-semibold text-[var(--cp-text-primary)]"
                            >
                                {{ trans('database_backups.encryption') }}
                            </h3>
                            <p class="text-sm text-[var(--cp-text-secondary)]">
                                {{ trans('database_backups.encryption_help') }}
                            </p>
                        </div>
                        <div
                            class="flex min-w-[4.5rem] shrink-0 justify-end self-start"
                        >
                            <ToggleSwitch
                                v-model="settingsForm.encryption_enabled"
                            />
                        </div>
                    </div>

                    <label
                        class="flex flex-col gap-1 text-sm font-medium text-[var(--cp-text-secondary)]"
                    >
                        {{ trans('database_backups.encryption_code') }}
                        <input
                            v-model="settingsForm.encryption_code"
                            class="rounded-md border border-[var(--cp-surface-border)] bg-[var(--cp-surface-panel)] px-3 py-2 font-mono text-sm tracking-normal text-[var(--cp-text-primary)]"
                            spellcheck="false"
                            type="text"
                        />
                    </label>
                    <small
                        v-if="settingsForm.errors.encryption_code"
                        class="text-red-600"
                    >
                        {{ settingsForm.errors.encryption_code }}
                    </small>

                    <div class="flex flex-wrap gap-2">
                        <Button
                            outlined
                            severity="secondary"
                            type="button"
                            @click="copyEncryptionCode"
                        >
                            <AppIcon name="copy" class="cp-icon" />
                            <span>
                                {{ trans('database_backups.copy_code') }}
                            </span>
                        </Button>
                        <Button
                            outlined
                            severity="secondary"
                            type="button"
                            @click="generateEncryptionCode"
                        >
                            <AppIcon name="refresh-cw" class="cp-icon" />
                            <span>
                                {{ trans('database_backups.generate_code') }}
                            </span>
                        </Button>
                        <Button
                            outlined
                            severity="secondary"
                            type="button"
                            @click="downloadEmergencyKit"
                        >
                            <AppIcon name="download" class="cp-icon" />
                            <span>
                                {{
                                    trans(
                                        'database_backups.download_emergency_kit',
                                    )
                                }}
                            </span>
                        </Button>
                    </div>

                    <p class="text-xs text-[var(--cp-text-muted)]">
                        {{ trans('database_backups.encryption_warning') }}
                    </p>
                </section>

                <section v-else-if="activeTab === 'cloud'" class="grid gap-5">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <h3
                                class="text-base font-semibold text-[var(--cp-text-primary)]"
                            >
                                {{ trans('database_backups.cloud_backup') }}
                            </h3>
                            <p class="text-sm text-[var(--cp-text-secondary)]">
                                {{
                                    trans('database_backups.cloud_backup_help')
                                }}
                            </p>
                        </div>
                        <div
                            class="flex min-w-[4.5rem] shrink-0 justify-end self-start"
                        >
                            <ToggleSwitch
                                v-model="settingsForm.cloud_backup_enabled"
                                :disabled="!cloudBackup.available"
                            />
                        </div>
                    </div>

                    <Message
                        v-if="!cloudBackup.connected"
                        :closable="false"
                        severity="warn"
                    >
                        {{
                            trans(
                                'database_backups.cloud_backup_missing_connection',
                            )
                        }}
                    </Message>
                    <Message
                        v-else-if="cloudBackup.missing_scopes"
                        :closable="false"
                        severity="warn"
                    >
                        {{
                            trans(
                                'database_backups.cloud_backup_missing_scopes',
                            )
                        }}
                    </Message>
                    <Message v-else :closable="false" severity="success">
                        {{
                            trans('database_backups.cloud_backup_available', {
                                email:
                                    cloudBackup.provider_email ??
                                    trans(
                                        'database_backups.cloud_backup_account',
                                    ),
                            })
                        }}
                    </Message>

                    <label
                        class="flex flex-col gap-1 text-sm font-medium text-[var(--cp-text-secondary)]"
                    >
                        {{ trans('database_backups.cloud_backup_path') }}
                        <input
                            v-model="settingsForm.cloud_backup_path"
                            :disabled="!cloudBackup.available"
                            class="rounded-md border border-[var(--cp-surface-border)] bg-[var(--cp-surface-panel)] px-3 py-2 text-[var(--cp-text-primary)] disabled:opacity-60"
                            type="text"
                        />
                    </label>
                    <small
                        v-if="settingsForm.errors.cloud_backup_path"
                        class="text-red-600"
                    >
                        {{ settingsForm.errors.cloud_backup_path }}
                    </small>
                </section>

                <section v-else class="grid gap-5">
                    <div class="grid gap-3">
                        <h3
                            class="text-base font-semibold text-[var(--cp-text-primary)]"
                        >
                            {{ trans('database_backups.settings_retention') }}
                        </h3>
                        <div class="grid gap-2 sm:grid-cols-3">
                            <Button
                                :outlined="
                                    settingsForm.retention_mode !== 'count'
                                "
                                severity="secondary"
                                type="button"
                                @click="settingsForm.retention_mode = 'count'"
                            >
                                {{
                                    trans('database_backups.retention_count', {
                                        count: String(
                                            settingsForm.retention_count,
                                        ),
                                    })
                                }}
                            </Button>
                            <Button
                                :outlined="
                                    settingsForm.retention_mode !== 'days'
                                "
                                severity="secondary"
                                type="button"
                                @click="settingsForm.retention_mode = 'days'"
                            >
                                {{
                                    trans('database_backups.retention_days', {
                                        days: String(
                                            settingsForm.retention_days,
                                        ),
                                    })
                                }}
                            </Button>
                            <Button
                                :outlined="
                                    settingsForm.retention_mode !== 'forever'
                                "
                                severity="secondary"
                                type="button"
                                @click="settingsForm.retention_mode = 'forever'"
                            >
                                {{
                                    trans('database_backups.retention_forever')
                                }}
                            </Button>
                        </div>

                        <label
                            v-if="settingsForm.retention_mode === 'count'"
                            class="flex flex-col gap-1 text-sm font-medium text-[var(--cp-text-secondary)]"
                        >
                            {{
                                trans('database_backups.retention_count_label')
                            }}
                            <input
                                v-model.number="settingsForm.retention_count"
                                class="rounded-md border border-[var(--cp-surface-border)] bg-[var(--cp-surface-panel)] px-3 py-2 text-[var(--cp-text-primary)]"
                                min="1"
                                type="number"
                            />
                        </label>
                        <label
                            v-if="settingsForm.retention_mode === 'days'"
                            class="flex flex-col gap-1 text-sm font-medium text-[var(--cp-text-secondary)]"
                        >
                            {{ trans('database_backups.retention_days_label') }}
                            <input
                                v-model.number="settingsForm.retention_days"
                                class="rounded-md border border-[var(--cp-surface-border)] bg-[var(--cp-surface-panel)] px-3 py-2 text-[var(--cp-text-primary)]"
                                min="1"
                                type="number"
                            />
                        </label>
                        <small
                            v-if="settingsForm.errors.retention_count"
                            class="text-red-600"
                        >
                            {{ settingsForm.errors.retention_count }}
                        </small>
                        <small
                            v-if="settingsForm.errors.retention_days"
                            class="text-red-600"
                        >
                            {{ settingsForm.errors.retention_days }}
                        </small>
                    </div>
                </section>
            </div>

            <div
                class="flex justify-end gap-2 border-t border-[var(--cp-surface-border)] pt-4"
            >
                <Button
                    :disabled="settingsForm.processing"
                    severity="secondary"
                    type="button"
                    @click="dialogVisible = false"
                >
                    {{ trans('database_backups.cancel') }}
                </Button>
                <Button :disabled="settingsForm.processing" type="submit">
                    <AppIcon
                        :name="settingsForm.processing ? 'refresh-cw' : 'save'"
                        class="cp-icon"
                        :class="{ 'animate-spin': settingsForm.processing }"
                    />
                    <span>{{ trans('database_backups.save_settings') }}</span>
                </Button>
            </div>
        </form>
    </Dialog>
</template>

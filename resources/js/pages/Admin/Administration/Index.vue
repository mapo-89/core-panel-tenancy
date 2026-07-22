<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { trans } from 'laravel-vue-i18n'
import { computed, markRaw, ref } from 'vue'

import TabsRenderer from '@core-panel/components/TabBuilder/TabsRenderer.vue'

import AppLayout from '@core-panel/layouts/AppLayout.vue'
import HorizonTab from '@core-panel/pages/Admin/Administration/components/HorizonTab.vue'
import SystemUpdatesTab from '@core-panel/pages/Admin/Administration/components/SystemUpdatesTab.vue'
import type { TabsSchema } from '@core-panel/types/core-panel'
import DatabaseBackupsTab from '@core-panel-tenancy/pages/Admin/Administration/components/DatabaseBackupsTab.vue'

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
    storage_locations: string[]
    tenant_options?: Array<{ id: string; label: string }>
}

type SystemUpdateImage = {
    available_digest: string | null
    current_digest: string | null
    image: string
    manual_update_required?: boolean
    service: string
    update_available: boolean
}

type SystemUpdateLogEntry = {
    level: string
    message: string
    timestamp: string
}

const props = defineProps<{
    activeTab: 'database-backups' | 'horizon' | 'system-updates'
    databaseBackupsTab?: {
        backups: DatabaseBackup[]
        backupScopes?: Array<'central_only' | 'full_set'>
        cloudBackup?: {
            available: boolean
            connected: boolean
            enabled: boolean
            missing_scopes: boolean
            path: string
            provider_email: string | null
        }
        routes: {
            destroy: string
            download: string
            downloadSql?: string
            emergencyKit?: string
            import?: string
            restore?: string
            restoreStatus?: string
            settings?: string
            store: string
        }
        settings?: {
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
        summary: {
            count: number
            latest: DatabaseBackup | null
            total_size: number
        }
        tableOptions?: {
            central: Array<{
                dependencies: string[]
                label: string
                value: string
            }>
            tenant: Array<{
                dependencies: string[]
                label: string
                value: string
            }>
        }
    } | null
    horizonTab?: {
        url: string
    } | null
    systemUpdatesTab?: {
        automatic: {
            enabled: boolean
            forceUpdateEnabled: boolean
            inactiveMinutes: number
            timezone: string
            windowEnd: string
            windowStart: string
        }
        logs: {
            entries?: SystemUpdateLogEntry[]
        }
        routes: {
            check: string
            status: string
            update: string
        }
        status: {
            configured: boolean
            error: string | null
            images?: SystemUpdateImage[]
            last_check_at?: string | null
            last_update_at?: string | null
            last_update_state?: string | null
            update_available: boolean
            update_running: boolean
        }
    } | null
}>()

const activeTab = ref(props.activeTab)

const tabComponents = {
    DatabaseBackupsTab: markRaw(DatabaseBackupsTab),
    HorizonTab: markRaw(HorizonTab),
    SystemUpdatesTab: markRaw(SystemUpdatesTab),
}

const tabSchema = computed<TabsSchema>(() => {
    const tabs: TabsSchema['tabs'] = []

    if (props.databaseBackupsTab) {
        tabs.push({
            component: 'DatabaseBackupsTab',
            componentProps: props.databaseBackupsTab,
            icon: 'folder-check',
            key: 'database-backups',
            label: 'database_backups.title',
        })
    }

    if (props.horizonTab) {
        tabs.push({
            component: 'HorizonTab',
            componentProps: props.horizonTab,
            icon: 'desktop',
            key: 'horizon',
            label: 'administration.horizon_title',
        })
    }

    if (props.systemUpdatesTab) {
        tabs.push({
            component: 'SystemUpdatesTab',
            componentProps: props.systemUpdatesTab,
            icon: 'package-search',
            key: 'system-updates',
            label: 'system_updates.title',
        })
    }

    return {
        panelSurface: false,
        tabs,
    }
})
</script>

<template>
    <AppLayout
        :title="trans('administration.title')"
        :subtitle="trans('administration.subtitle')"
    >
        <Head :title="trans('administration.title')" />

        <TabsRenderer
            v-model="activeTab"
            class="cp-side-tabs"
            :components="tabComponents"
            layout="vertical"
            :schema="tabSchema"
            sync-with-url
        />
    </AppLayout>
</template>

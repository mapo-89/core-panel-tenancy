<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { computed, onMounted, ref, watch } from 'vue'

import { trans } from 'laravel-vue-i18n'
import { useConfirm } from 'primevue/useconfirm'
import { useDialog } from 'primevue/usedialog'

import AppIcon from '@/components/AppIcon.vue'
import { useCan } from '@/composables/useCan'
import TenantForm from '@/pages/Admin/Tenants/components/TenantForm.vue'
import {
    destroy as destroyTenant,
    dtApi as tenantDtApi,
} from '@/routes/tenants'
import TableBuilderDataTable from '@core-panel/components/TableBuilder/DataTable.vue'
import type { DataTableSchema } from '@core-panel/components/TableBuilder/types'

type TenantManagementRecord = {
    id: string
    primary_domain: string
    domains: string[]
    domains_count: number
    database_name: string
    name: string | null
    plan: string | null
    status: string
    super_admin: {
        email: string | null
        first_name: string | null
        full_name: string | null
        id: string | null
        last_name: string | null
        mobile: string | null
    } | null
    created_at: string | null
}

const props = defineProps<{
    onSaved?: () => void
    refreshKey?: number
    tenantRecords?: TenantManagementRecord[]
}>()

const { can } = useCan()
const dialog = useDialog()
const confirm = useConfirm()
const loading = ref(false)
const tenantRecordsState = ref<TenantManagementRecord[]>(
    props.tenantRecords ?? [],
)
const dateFormatter = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
})

const tableSchema = computed<DataTableSchema>(() => ({
    actions: [],
    bulkActions: [],
    columns: [
        {
            key: 'primary_domain',
            label: null,
            meta: {
                labelKey: 'page-tenants.primary_domain',
                searchKeys: ['name', 'plan', 'status'],
            },
            searchable: true,
            sortable: true,
            toggleable: false,
            type: 'text',
            visible: true,
        },
        {
            key: 'domains_count',
            label: null,
            meta: {
                labelKey: 'page-tenants.domains_count',
                localSortType: 'number',
            },
            searchable: false,
            sortable: true,
            toggleable: true,
            type: 'text',
            visible: true,
        },
        {
            key: 'database_name',
            label: null,
            meta: {
                labelKey: 'page-tenants.database_name',
            },
            searchable: true,
            sortable: true,
            toggleable: true,
            type: 'text',
            visible: true,
        },
        {
            key: 'created_at',
            label: null,
            meta: {
                labelKey: 'table-builder.columns.created_at',
                localSortType: 'date',
            },
            searchable: false,
            sortable: true,
            toggleable: true,
            type: 'text',
            visible: true,
        },
    ],
    filters: [],
    mode: 'local',
    pagination: {
        from: tenantRecordsState.value.length > 0 ? 1 : null,
        lastPage: Math.max(1, Math.ceil(tenantRecordsState.value.length / 10)),
        page: 1,
        perPage: 10,
        to:
            tenantRecordsState.value.length > 0
                ? Math.min(tenantRecordsState.value.length, 10)
                : null,
        total: tenantRecordsState.value.length,
    },
    rows: tenantRecordsState.value,
    state: {
        filters: {},
        search: '',
        sort: 'primary_domain',
        visibleColumns: [
            'primary_domain',
            'domains_count',
            'database_name',
            'created_at',
        ],
    },
}))

function openEditDialog(tenant: TenantManagementRecord): void {
    dialog.open(TenantForm, {
        data: {
            onSaved: props.onSaved,
            tenant,
        },
        props: {
            header: trans('page-tenants.edit_title'),
            modal: true,
            style: {
                width: 'min(54rem, 96vw)',
            },
        },
    })
}

function confirmDelete(tenant: TenantManagementRecord): void {
    confirm.require({
        accept: () => {
            router.delete(destroyTenant.url(tenant.id), {
                onSuccess: () => props.onSaved?.(),
                preserveScroll: true,
            })
        },
        acceptLabel: trans('common.ui.delete'),
        header: trans('page-tenants.delete_title'),
        message: trans('page-tenants.delete_message', {
            name: tenant.name ?? tenant.primary_domain,
        }),
        rejectLabel: trans('common.ui.cancel'),
    })
}

async function loadTenants(): Promise<void> {
    loading.value = true

    try {
        const response = await fetch(tenantDtApi.url(), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })

        if (!response.ok) {
            throw new Error(
                `Unexpected tenant response status: ${response.status}`,
            )
        }

        const payload = (await response.json()) as {
            tenants?: TenantManagementRecord[]
        }

        tenantRecordsState.value = Array.isArray(payload.tenants)
            ? payload.tenants.filter(
                  (tenant): tenant is TenantManagementRecord =>
                      tenant !== null && tenant !== undefined,
              )
            : []
    } catch {
        tenantRecordsState.value = []
    } finally {
        loading.value = false
    }
}

onMounted(async () => {
    if ((props.tenantRecords?.length ?? 0) > 0) {
        tenantRecordsState.value = props.tenantRecords ?? []

        return
    }

    await loadTenants()
})

watch(
    () => props.refreshKey,
    async () => {
        await loadTenants()
    },
)
</script>

<template>
    <section class="cp-datatable cp-user-tenants-tab">
        <TableBuilderDataTable
            :action-column-width="
                can('tenants.update') || can('tenants.delete')
                    ? '8.25rem'
                    : '0px'
            "
            :loading="loading"
            :schema="tableSchema"
            surface-class="cp-user-tenants-tab__surface"
        >
            <template #cell-primary_domain="{ row }">
                <div class="flex flex-col gap-1">
                    <span class="font-medium text-[var(--cp-text-primary)]">
                        {{ row.primary_domain ?? '' }}
                    </span>
                    <span class="text-xs text-[var(--cp-text-muted)]">
                        {{ row.name || row.plan || row.status || '' }}
                    </span>
                </div>
            </template>

            <template #cell-domains_count="{ row }">
                <span class="font-medium text-[var(--cp-text-primary)]">
                    {{ row.domains_count ?? 0 }}
                </span>
            </template>

            <template #cell-database_name="{ row }">
                <span class="font-mono text-sm text-[var(--cp-text-primary)]">
                    {{ row.database_name ?? '' }}
                </span>
            </template>

            <template #cell-created_at="{ row }">
                <span class="text-sm text-[var(--cp-text-primary)]">
                    {{
                        row.created_at
                            ? dateFormatter.format(
                                  new Date(String(row.created_at)),
                              )
                            : '—'
                    }}
                </span>
            </template>

            <template #row-actions="{ row }">
                <div class="flex justify-end gap-2">
                    <Button
                        v-if="can('tenants.update')"
                        class="cp-datatable__action-button"
                        outlined
                        severity="secondary"
                        size="small"
                        @click="openEditDialog(row as TenantManagementRecord)"
                    >
                        <AppIcon name="pencil" />
                    </Button>
                    <Button
                        v-if="can('tenants.delete')"
                        class="cp-datatable__action-button cp-datatable__action-button--danger"
                        severity="danger"
                        size="small"
                        @click="confirmDelete(row as TenantManagementRecord)"
                    >
                        <AppIcon name="trash" />
                    </Button>
                </div>
            </template>

            <template #empty-state>
                <div
                    class="flex min-h-[12rem] items-center justify-center px-6 py-10"
                >
                    <Message severity="secondary" variant="simple">
                        {{ $t('page-tenants.empty') }}
                    </Message>
                </div>
            </template>
        </TableBuilderDataTable>
    </section>
</template>

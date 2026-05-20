<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { computed, onMounted, ref, watch } from 'vue'

import { trans } from 'laravel-vue-i18n'
import type { DataTableSortEvent } from 'primevue/datatable'
import { useConfirm } from 'primevue/useconfirm'
import { useDialog } from 'primevue/usedialog'

import AppIcon from '@/components/AppIcon.vue'
import { useCan } from '@/composables/useCan'
import TenantForm from '@/pages/Admin/Tenants/components/TenantForm.vue'
import {
    destroy as destroyTenant,
    dtApi as tenantDtApi,
} from '@/routes/tenants'
import ColumnVisibilityDropdown from '@core-panel/components/TableBuilder/ColumnVisibilityDropdown.vue'
import TablePagination from '@core-panel/components/TableBuilder/TablePagination.vue'
import type {
    DataTableColumn,
    DataTablePagination,
} from '@core-panel/components/TableBuilder/types'

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
const search = ref('')
const rowsPerPage = ref(10)
const currentPage = ref(1)
const sortField = ref<
    'created_at' | 'database_name' | 'domains_count' | 'primary_domain'
>('primary_domain')
const sortOrder = ref<1 | -1>(1)
const tenantRecordsState = ref<TenantManagementRecord[]>(
    props.tenantRecords ?? [],
)
const visibleColumns = ref(['domains_count', 'database_name', 'created_at'])
const dateFormatter = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
})

const columns = computed<DataTableColumn[]>(() => [
    {
        key: 'primary_domain',
        label: null,
        meta: { labelKey: 'page-tenants.primary_domain' },
        searchable: true,
        sortable: true,
        toggleable: false,
        type: 'text',
        visible: true,
    },
    {
        key: 'domains_count',
        label: null,
        meta: { labelKey: 'page-tenants.domains_count' },
        searchable: false,
        sortable: true,
        toggleable: true,
        type: 'text',
        visible: true,
    },
    {
        key: 'database_name',
        label: null,
        meta: { labelKey: 'page-tenants.database_name' },
        searchable: true,
        sortable: true,
        toggleable: true,
        type: 'text',
        visible: true,
    },
    {
        key: 'created_at',
        label: null,
        meta: { labelKey: 'table-builder.columns.created_at' },
        searchable: false,
        sortable: true,
        toggleable: true,
        type: 'text',
        visible: true,
    },
])

const activeColumns = computed(() =>
    columns.value.filter(
        (column) =>
            !column.toggleable || visibleColumns.value.includes(column.key),
    ),
)

const filteredRows = computed(() => {
    const query = search.value.trim().toLowerCase()

    if (query === '') {
        return tenantRecordsState.value
    }

    return tenantRecordsState.value.filter((tenant) =>
        [
            tenant.primary_domain,
            tenant.database_name,
            tenant.name ?? '',
            tenant.plan ?? '',
            tenant.status,
        ].some((value) => value.toLowerCase().includes(query)),
    )
})

const sortedRows = computed(() => {
    return [...filteredRows.value].sort((left, right) => {
        const leftValue = left[sortField.value]
        const rightValue = right[sortField.value]

        if (sortField.value === 'created_at') {
            const leftDate = leftValue
                ? new Date(String(leftValue)).getTime()
                : 0
            const rightDate = rightValue
                ? new Date(String(rightValue)).getTime()
                : 0

            return (leftDate - rightDate) * sortOrder.value
        }

        if (typeof leftValue === 'number' && typeof rightValue === 'number') {
            return (leftValue - rightValue) * sortOrder.value
        }

        return (
            String(leftValue ?? '').localeCompare(String(rightValue ?? '')) *
            sortOrder.value
        )
    })
})

const paginatedRows = computed(() => {
    const start = (currentPage.value - 1) * rowsPerPage.value

    return sortedRows.value.slice(start, start + rowsPerPage.value)
})

const pagination = computed<DataTablePagination>(() => {
    const total = sortedRows.value.length
    const from =
        total === 0 ? null : (currentPage.value - 1) * rowsPerPage.value + 1
    const to =
        total === 0
            ? null
            : Math.min(currentPage.value * rowsPerPage.value, total)

    return {
        from,
        lastPage: Math.max(1, Math.ceil(total / rowsPerPage.value)),
        page: currentPage.value,
        perPage: rowsPerPage.value,
        to,
        total,
    }
})

function handleSort(event: DataTableSortEvent): void {
    if (typeof event.sortField !== 'string' || event.sortField === '') {
        return
    }

    sortField.value = event.sortField as typeof sortField.value
    sortOrder.value = event.sortOrder === -1 ? -1 : 1
}

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
        currentPage.value = 1
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
        <div class="grid gap-3 px-[1.125rem] pt-[1.125rem] pb-1">
            <div class="cp-datatable__toolbar">
                <div class="min-w-0 flex-1">
                    <div class="cp-datatable__search">
                        <IconField class="w-full">
                            <InputIcon>
                                <AppIcon name="search" />
                            </InputIcon>
                            <InputText
                                v-model="search"
                                class="w-full"
                                :placeholder="$t('common.ui.search')"
                            />
                        </IconField>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2">
                    <ColumnVisibilityDropdown
                        v-model="visibleColumns"
                        :columns="columns"
                    />
                </div>
            </div>
        </div>

        <div class="cp-card cp-datatable__surface cp-user-tenants-tab__surface">
            <DataTable
                class="cp-datatable__table cp-user-tenants-tab__table"
                data-key="id"
                :rows="rowsPerPage"
                :sort-field="sortField"
                :sort-order="sortOrder"
                :value="paginatedRows"
                @sort="handleSort"
            >
                <Column
                    field="primary_domain"
                    header-class="cp-datatable__header-cell"
                    sortable
                    :header="$t('page-tenants.primary_domain')"
                >
                    <template #body="{ data }">
                        <div class="flex flex-col gap-1">
                            <span
                                class="font-medium text-[var(--cp-text-primary)]"
                            >
                                {{ data?.primary_domain ?? '' }}
                            </span>
                            <span class="text-xs text-[var(--cp-text-muted)]">
                                {{
                                    data?.name ||
                                    data?.plan ||
                                    data?.status ||
                                    ''
                                }}
                            </span>
                        </div>
                    </template>
                </Column>

                <Column
                    v-if="
                        activeColumns.some(
                            (column) => column.key === 'domains_count',
                        )
                    "
                    field="domains_count"
                    header-class="cp-datatable__header-cell"
                    sortable
                    :header="$t('page-tenants.domains_count')"
                >
                    <template #body="{ data }">
                        <span class="font-medium text-[var(--cp-text-primary)]">
                            {{ data?.domains_count ?? 0 }}
                        </span>
                    </template>
                </Column>

                <Column
                    v-if="
                        activeColumns.some(
                            (column) => column.key === 'database_name',
                        )
                    "
                    field="database_name"
                    header-class="cp-datatable__header-cell"
                    sortable
                    :header="$t('page-tenants.database_name')"
                >
                    <template #body="{ data }">
                        <span
                            class="font-mono text-sm text-[var(--cp-text-primary)]"
                        >
                            {{ data?.database_name ?? '' }}
                        </span>
                    </template>
                </Column>

                <Column
                    v-if="
                        activeColumns.some(
                            (column) => column.key === 'created_at',
                        )
                    "
                    field="created_at"
                    header-class="cp-datatable__header-cell"
                    sortable
                    :header="$t('table-builder.columns.created_at')"
                >
                    <template #body="{ data }">
                        <span class="text-sm text-[var(--cp-text-primary)]">
                            {{
                                data?.created_at
                                    ? dateFormatter.format(
                                          new Date(data.created_at),
                                      )
                                    : '—'
                            }}
                        </span>
                    </template>
                </Column>

                <Column
                    header-class="cp-datatable__actions-header"
                    :header="$t('common.ui.actions')"
                >
                    <template #body="{ data }">
                        <div class="flex justify-end gap-2">
                            <Button
                                v-if="can('tenants.update')"
                                class="cp-datatable__action-button"
                                outlined
                                severity="secondary"
                                size="small"
                                @click="data && openEditDialog(data)"
                            >
                                <AppIcon name="pencil" />
                            </Button>
                            <Button
                                v-if="can('tenants.delete')"
                                class="cp-datatable__action-button cp-datatable__action-button--danger"
                                severity="danger"
                                size="small"
                                @click="data && confirmDelete(data)"
                            >
                                <AppIcon name="trash" />
                            </Button>
                        </div>
                    </template>
                </Column>

                <template #empty>
                    <div
                        class="flex min-h-[12rem] items-center justify-center px-6 py-10"
                    >
                        <Message severity="secondary" variant="simple">
                            {{ $t('page-tenants.empty') }}
                        </Message>
                    </div>
                </template>
            </DataTable>

            <TablePagination
                v-model:page="currentPage"
                v-model:per-page="rowsPerPage"
                :pagination="pagination"
                class="-mt-[0.15rem]"
            />
        </div>
    </section>
</template>

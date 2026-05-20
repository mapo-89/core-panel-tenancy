<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { useDialog } from 'primevue/usedialog'

import { trans } from 'laravel-vue-i18n'

import AppLayout from '@/layouts/AppLayout.vue'
import UserTenantsTab from '@/components/Users/UserTenantsTab.vue'
import TenantForm from '@/pages/Admin/Tenants/components/TenantForm.vue'
import { useCan } from '@/composables/useCan'

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
    tenantRecords: TenantManagementRecord[]
}>()

const dialog = useDialog()
const { can } = useCan()

function reloadTenants(): void {
    router.reload({
        only: ['tenantRecords'],
    })
}

function openCreateTenantDialog(): void {
    dialog.open(TenantForm, {
        data: {
            onSaved: reloadTenants,
            tenant: null,
        },
        props: {
            header: trans('page-tenants.create'),
            modal: true,
            style: {
                width: 'min(54rem, 96vw)',
            },
        },
    })
}
</script>

<template>
    <AppLayout
        :title="$t('page-tenants.management_title')"
        :subtitle="$t('page-tenants.index_description')"
    >
        <Head :title="$t('page-tenants.page_title')" />

        <template #actions>
            <Button
                v-if="can('tenants.create')"
                class="gap-2"
                @click="openCreateTenantDialog"
            >
                <i class="pi pi-plus" />
                <span>{{ $t('page-tenants.create') }}</span>
            </Button>
        </template>

        <UserTenantsTab
            :tenant-records="props.tenantRecords"
            :on-saved="reloadTenants"
        />
    </AppLayout>
</template>

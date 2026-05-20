<script setup lang="ts">
import { Head } from '@inertiajs/vue3'

import AppLayout from '@/layouts/AppLayout.vue'
import TenantForm from '@/pages/Admin/Tenants/components/TenantForm.vue'

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

defineProps<{
    tenant: TenantManagementRecord
}>()
</script>

<template>
    <AppLayout
        :title="$t('page-tenants.edit_title')"
        :subtitle="$t('page-tenants.index_description')"
    >
        <Head :title="$t('page-tenants.edit_title')" />

        <TenantForm :tenant="tenant" />
    </AppLayout>
</template>

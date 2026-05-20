<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3'
import { computed, inject } from 'vue'
import TranslatedPassword from '@/components/TranslatedPassword.vue'

import {
    index as tenantsIndex,
    store as storeTenant,
    update as updateTenant,
} from '@/routes/tenants'

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

type DialogRef = {
    close: () => void
    data?: {
        onSaved?: () => void
        tenant?: TenantManagementRecord | null
    }
}

const dialogRef = inject<{ value: DialogRef }>('dialogRef')
const onSaved = dialogRef?.value.data?.onSaved
const tenant = dialogRef?.value.data?.tenant ?? null
const isEditMode = computed(() => tenant !== null)
const additionalDomains = computed(
    () => tenant?.domains.slice(1).join('\n') ?? '',
)
const primaryDomainPlaceholder = 'acme.example.test'
const additionalDomainsPlaceholder =
    'acme-alt.example.test\nshop.acme.example.test'
const tenantIdPlaceholder = 'acme'
const databaseNamePlaceholder = 'tenant_acme'

const form = useForm({
    primary_domain: tenant?.primary_domain ?? '',
    additional_domains: additionalDomains.value,
    tenant_id: '',
    database_name: tenant?.database_name ?? '',
    name: tenant?.name ?? '',
    plan: tenant?.plan ?? '',
    super_admin_first_name: tenant?.super_admin?.first_name ?? '',
    super_admin_last_name: tenant?.super_admin?.last_name ?? '',
    super_admin_email: tenant?.super_admin?.email ?? '',
    super_admin_mobile: tenant?.super_admin?.mobile ?? '',
    super_admin_password: '',
    super_admin_password_confirmation: '',
})

function submit(): void {
    if (tenant !== null) {
        form.put(updateTenant.url(tenant.id), {
            onSuccess: () => {
                onSaved?.()
                dialogRef?.value.close()
            },
        })

        return
    }

    form.post(storeTenant.url(), {
        onSuccess: () => {
            onSaved?.()
            dialogRef?.value.close()
        },
    })
}

function cancel(): void {
    if (dialogRef !== undefined) {
        dialogRef.value.close()
    } else {
        router.visit(tenantsIndex.url())
    }
}
</script>

<template>
    <form class="grid gap-6" @submit.prevent="submit">
        <div class="cp-section">
            <div class="cp-section__header">
                <div>
                    <h3
                        class="text-sm font-semibold text-[var(--cp-text-primary)]"
                    >
                        {{ $t('page-tenants.page_title') }}
                    </h3>
                    <p class="mt-1 text-sm text-[var(--cp-text-muted)]">
                        {{ $t('page-tenants.index_description') }}
                    </p>
                </div>
            </div>

            <div class="cp-section__body grid gap-5 md:grid-cols-2">
                <div class="md:col-span-2">
                    <label
                        class="mb-2 block text-sm font-medium text-[var(--cp-text-primary)]"
                    >
                        {{ $t('page-tenants.primary_domain') }}
                    </label>
                    <InputText
                        v-model="form.primary_domain"
                        class="w-full"
                        :invalid="!!form.errors.primary_domain"
                        :placeholder="primaryDomainPlaceholder"
                    />
                    <small class="mt-1 block text-[var(--cp-text-muted)]">
                        {{ $t('page-tenants.primary_domain_hint') }}
                    </small>
                    <small
                        v-if="form.errors.primary_domain"
                        class="mt-1 block text-red-500"
                    >
                        {{ form.errors.primary_domain }}
                    </small>
                </div>

                <div class="md:col-span-2">
                    <label
                        class="mb-2 block text-sm font-medium text-[var(--cp-text-primary)]"
                    >
                        {{ $t('page-tenants.additional_domains') }}
                    </label>
                    <Textarea
                        v-model="form.additional_domains"
                        class="w-full"
                        rows="4"
                        :invalid="!!form.errors.additional_domains"
                        :placeholder="additionalDomainsPlaceholder"
                    />
                    <small class="mt-1 block text-[var(--cp-text-muted)]">
                        {{ $t('page-tenants.additional_domains_hint') }}
                    </small>
                    <small
                        v-if="form.errors.additional_domains"
                        class="mt-1 block text-red-500"
                    >
                        {{ form.errors.additional_domains }}
                    </small>
                </div>

                <div v-if="!isEditMode">
                    <label
                        class="mb-2 block text-sm font-medium text-[var(--cp-text-primary)]"
                    >
                        {{ $t('page-tenants.tenant_id') }}
                    </label>
                    <InputText
                        v-model="form.tenant_id"
                        class="w-full"
                        :invalid="!!form.errors.tenant_id"
                        :placeholder="tenantIdPlaceholder"
                    />
                    <small class="mt-1 block text-[var(--cp-text-muted)]">
                        {{ $t('page-tenants.tenant_id_hint') }}
                    </small>
                    <small
                        v-if="form.errors.tenant_id"
                        class="mt-1 block text-red-500"
                    >
                        {{ form.errors.tenant_id }}
                    </small>
                </div>

                <div v-if="!isEditMode">
                    <label
                        class="mb-2 block text-sm font-medium text-[var(--cp-text-primary)]"
                    >
                        {{ $t('page-tenants.database_name') }}
                    </label>
                    <InputText
                        v-model="form.database_name"
                        class="w-full"
                        :invalid="!!form.errors.database_name"
                        :placeholder="databaseNamePlaceholder"
                    />
                    <small class="mt-1 block text-[var(--cp-text-muted)]">
                        {{ $t('page-tenants.database_name_hint') }}
                    </small>
                    <small
                        v-if="form.errors.database_name"
                        class="mt-1 block text-red-500"
                    >
                        {{ form.errors.database_name }}
                    </small>
                </div>

                <div class="md:col-span-2">
                    <Message severity="secondary" variant="simple">
                        {{ $t('page-tenants.metadata_hint') }}
                    </Message>
                </div>

                <div>
                    <label
                        class="mb-2 block text-sm font-medium text-[var(--cp-text-primary)]"
                    >
                        {{ $t('page-tenants.name') }}
                    </label>
                    <InputText
                        v-model="form.name"
                        class="w-full"
                        :invalid="!!form.errors.name"
                    />
                    <small
                        v-if="form.errors.name"
                        class="mt-1 block text-red-500"
                    >
                        {{ form.errors.name }}
                    </small>
                </div>

                <div>
                    <label
                        class="mb-2 block text-sm font-medium text-[var(--cp-text-primary)]"
                    >
                        {{ $t('page-tenants.plan') }}
                    </label>
                    <InputText
                        v-model="form.plan"
                        class="w-full"
                        :invalid="!!form.errors.plan"
                    />
                    <small
                        v-if="form.errors.plan"
                        class="mt-1 block text-red-500"
                    >
                        {{ form.errors.plan }}
                    </small>
                </div>
            </div>
        </div>

        <div class="cp-section">
            <div class="cp-section__header">
                <div>
                    <h3
                        class="text-sm font-semibold text-[var(--cp-text-primary)]"
                    >
                        {{ $t('page-tenants.super_admin_title') }}
                    </h3>
                    <p class="mt-1 text-sm text-[var(--cp-text-muted)]">
                        {{ $t('page-tenants.super_admin_hint') }}
                    </p>
                </div>
            </div>

            <div class="cp-section__body grid gap-5 md:grid-cols-2">
                <div>
                    <label
                        class="mb-2 block text-sm font-medium text-[var(--cp-text-primary)]"
                    >
                        {{ $t('page-tenants.super_admin_first_name') }}
                    </label>
                    <InputText
                        v-model="form.super_admin_first_name"
                        class="w-full"
                        :invalid="!!form.errors.super_admin_first_name"
                    />
                    <small
                        v-if="form.errors.super_admin_first_name"
                        class="mt-1 block text-red-500"
                    >
                        {{ form.errors.super_admin_first_name }}
                    </small>
                </div>

                <div>
                    <label
                        class="mb-2 block text-sm font-medium text-[var(--cp-text-primary)]"
                    >
                        {{ $t('page-tenants.super_admin_last_name') }}
                    </label>
                    <InputText
                        v-model="form.super_admin_last_name"
                        class="w-full"
                        :invalid="!!form.errors.super_admin_last_name"
                    />
                    <small
                        v-if="form.errors.super_admin_last_name"
                        class="mt-1 block text-red-500"
                    >
                        {{ form.errors.super_admin_last_name }}
                    </small>
                </div>

                <div>
                    <label
                        class="mb-2 block text-sm font-medium text-[var(--cp-text-primary)]"
                    >
                        {{ $t('page-tenants.super_admin_email') }}
                    </label>
                    <InputText
                        v-model="form.super_admin_email"
                        class="w-full"
                        inputmode="email"
                        :invalid="!!form.errors.super_admin_email"
                    />
                    <small
                        v-if="form.errors.super_admin_email"
                        class="mt-1 block text-red-500"
                    >
                        {{ form.errors.super_admin_email }}
                    </small>
                </div>

                <div>
                    <label
                        class="mb-2 block text-sm font-medium text-[var(--cp-text-primary)]"
                    >
                        {{ $t('page-tenants.super_admin_mobile') }}
                    </label>
                    <InputText
                        v-model="form.super_admin_mobile"
                        class="w-full"
                        :invalid="!!form.errors.super_admin_mobile"
                    />
                    <small
                        v-if="form.errors.super_admin_mobile"
                        class="mt-1 block text-red-500"
                    >
                        {{ form.errors.super_admin_mobile }}
                    </small>
                </div>

                <div>
                    <label
                        class="mb-2 block text-sm font-medium text-[var(--cp-text-primary)]"
                    >
                        {{ $t('page-tenants.super_admin_password') }}
                    </label>
                    <TranslatedPassword
                        v-model="form.super_admin_password"
                        class="w-full"
                        fluid
                        :min-length="8"
                        toggle-mask
                        :invalid="!!form.errors.super_admin_password"
                    />
                    <small class="mt-1 block text-[var(--cp-text-muted)]">
                        {{
                            $t(
                                isEditMode
                                    ? 'page-tenants.super_admin_password_edit_hint'
                                    : 'page-tenants.super_admin_password_create_hint',
                            )
                        }}
                    </small>
                    <small
                        v-if="form.errors.super_admin_password"
                        class="mt-1 block text-red-500"
                    >
                        {{ form.errors.super_admin_password }}
                    </small>
                </div>

                <div>
                    <label
                        class="mb-2 block text-sm font-medium text-[var(--cp-text-primary)]"
                    >
                        {{
                            $t('page-tenants.super_admin_password_confirmation')
                        }}
                    </label>
                    <TranslatedPassword
                        v-model="form.super_admin_password_confirmation"
                        class="w-full"
                        fluid
                        :match-password="form.super_admin_password"
                        toggle-mask
                        :invalid="
                            !!form.errors.super_admin_password_confirmation
                        "
                    />
                    <small
                        v-if="form.errors.super_admin_password_confirmation"
                        class="mt-1 block text-red-500"
                    >
                        {{ form.errors.super_admin_password_confirmation }}
                    </small>
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-2">
            <Button type="button" outlined severity="secondary" @click="cancel">
                {{ $t('common.ui.cancel') }}
            </Button>
            <Button type="submit" :loading="form.processing">
                {{
                    $t(
                        isEditMode
                            ? 'common.ui.save_changes'
                            : 'common.ui.create',
                    )
                }}
            </Button>
        </div>
    </form>
</template>

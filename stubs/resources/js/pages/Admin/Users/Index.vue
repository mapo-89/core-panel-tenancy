<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3'
import { computed, markRaw, ref } from 'vue'

import { trans } from 'laravel-vue-i18n'
import { useDialog } from 'primevue/usedialog'

import TabsRenderer from '@core-panel/components/TabBuilder/TabsRenderer.vue'
import AppIcon from '@/components/AppIcon.vue'
import RolesOverviewPanel from '@/pages/Admin/Access/components/RolesOverviewPanel.vue'
import { useCan } from '@/composables/useCan'
import UserFormDialog from '@/pages/Admin/Users/components/UserFormDialog.vue'
import UserGroupsTab from '@/pages/Admin/Users/components/UserGroupsTab.vue'
import UserTenantsTab from '@/components/Users/UserTenantsTab.vue'
import UsersTableTab from '@/pages/Admin/Users/components/UsersTableTab.vue'
import AppLayout from '@/layouts/AppLayout.vue'
import TenantForm from '@/pages/Admin/Tenants/components/TenantForm.vue'
import UserGroupForm from '@/pages/Admin/UserGroups/components/UserGroupForm.vue'
import UserGroupImportForm from '@/pages/Admin/UserGroups/components/UserGroupImportForm.vue'
import roleRoutes from '@/routes/core-panel/roles'
import type {
    AssignableUser,
    DataTablePagination,
    DataTableState,
    PermissionRecord,
    RoleRecord,
    TabsSchema,
    UserGroupRecord,
    UserCapabilities,
    UserRecord,
} from '@/types/core-panel'

type CorePanelTenancyContext = {
    centralDomain: string | null
    isCentral: boolean
    currentTenantId: string | null
    currentTenantName: string | null
}

type UsersPageProps = {
    tenancy?: CorePanelTenancyContext
}

type ManagedRoleRecord = {
    name: string
    group: string
    label: string
    permissions: string[]
    protected: boolean
}

type UserManagementTab = 'users' | 'user_groups' | 'roles' | 'tenants'

const props = defineProps<{
    assignableUsers: AssignableUser[]
    assignableRoles: RoleRecord[]
    canAssignRoles: boolean
    capabilities: UserCapabilities
    defaultRoles: ManagedRoleRecord[]
    filters: {
        role?: string
        search: string
        status?: string
        userGroupId?: string
        withTrashed: boolean
    }
    permissionDefaults: string[]
    permissionGroups: Record<string, string>
    permissions: PermissionRecord[]
    roleLabels: Record<string, string>
    roles: RoleRecord[]
    userGroupOptions: Array<{
        color: string
        label: string
        value: string
    }>
    userGroups: UserGroupRecord[]
    users: UserRecord[]
    usersTable: {
        pagination: DataTablePagination
        state: DataTableState
    }
}>()

const activeTab = ref<UserManagementTab>('users')
const dialog = useDialog()
const tenantRefreshKey = ref(0)
const page = usePage<UsersPageProps>()

const tabComponents = {
    RolesOverviewPanel: markRaw(RolesOverviewPanel),
    UserGroupsTab: markRaw(UserGroupsTab),
    UserTenantsTab: markRaw(UserTenantsTab),
    UsersTableTab: markRaw(UsersTableTab),
}

const canManageTenants = computed(() => page.props.tenancy?.isCentral === true)

const tabSchema = computed<TabsSchema>(() => ({
    panelSurface: true,
    panelSurfaceVariant: 'card',
    tabs: [
        {
            component: 'UsersTableTab',
            componentProps: {
                capabilities: props.capabilities,
                filters: props.filters,
                onEditUser: openEditDialog,
                roleLabels: props.roleLabels,
                users: props.users,
                usersTable: props.usersTable,
                userGroupOptions: props.userGroupOptions,
            },
            icon: 'users',
            key: 'users',
            label: 'navigation.users',
        },
        {
            component: 'UserGroupsTab',
            componentProps: {
                userGroups: props.userGroups,
            },
            icon: 'sitemap',
            key: 'user_groups',
            label: 'navigation.user_groups',
        },
        {
            component: 'RolesOverviewPanel',
            componentProps: {
                defaultRoles: props.defaultRoles,
                permissionDefaults: props.permissionDefaults,
                permissionGroups: props.permissionGroups,
                permissions: props.permissions,
                roles: props.roles,
                variant: 'tab',
            },
            icon: 'shield',
            key: 'roles',
            label: 'navigation.roles',
        },
        ...(canManageTenants.value
            ? [
                  {
                      component: 'UserTenantsTab',
                      componentProps: {
                          onSaved: reloadTenants,
                          refreshKey: tenantRefreshKey.value,
                      },
                      icon: 'building',
                      key: 'tenants',
                      label: 'page-tenants.page_title',
                  } satisfies TabsSchema['tabs'][number],
              ]
            : []),
    ],
}))

const { can } = useCan()

function reloadUsers(): void {
    router.reload({
        only: [
            'filters',
            'userGroups',
            'userGroupOptions',
            'users',
            'usersTable',
        ],
    })
}

function reloadTenants(): void {
    tenantRefreshKey.value += 1
}

function openCreateDialog(): void {
    dialog.open(UserFormDialog, {
        data: {
            canAssignRoles: props.canAssignRoles,
            capabilities: props.capabilities,
            onSaved: reloadUsers,
            roleLabels: props.roleLabels,
            roles: props.assignableRoles,
            userGroupOptions: props.userGroupOptions,
        },
        props: {
            header: trans('page-users.create_title'),
            modal: true,
            style: {
                width: 'min(58rem, 92vw)',
            },
        },
    })
}

function openEditDialog(user: UserRecord): void {
    if (!user.canUpdate) {
        return
    }

    dialog.open(UserFormDialog, {
        data: {
            canAssignRoles: props.canAssignRoles,
            capabilities: props.capabilities,
            onSaved: reloadUsers,
            roleLabels: props.roleLabels,
            roles: props.assignableRoles,
            userGroupOptions: props.userGroupOptions,
            user,
        },
        props: {
            header: trans('page-users.edit_title'),
            modal: true,
            style: {
                width: 'min(58rem, 92vw)',
            },
        },
    })
}

function openCreateUserGroupDialog(): void {
    dialog.open(UserGroupForm, {
        data: {
            onSaved: reloadUsers,
        },
        props: {
            header: trans('page-user-groups.create'),
            modal: true,
            style: {
                width: 'min(32rem, 92vw)',
            },
        },
    })
}

function openImportUserGroupsDialog(): void {
    dialog.open(UserGroupImportForm, {
        data: {
            onSaved: reloadUsers,
        },
        props: {
            header: trans('page-user-groups.import_title'),
            modal: true,
            style: {
                width: 'min(40rem, 92vw)',
            },
        },
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

function reloadRoles(): void {
    router.reload({
        only: [
            'assignableRoles',
            'canAssignRoles',
            'defaultRoles',
            'permissionDefaults',
            'permissionGroups',
            'permissions',
            'roleLabels',
            'roles',
        ],
    })
}

function openCreateRoleDialog(): void {
    router.visit(roleRoutes.matrix.url())
}

function resyncManagedRoles(): void {
    router.post(
        roleRoutes.resync.url(),
        {},
        {
            onSuccess: () => reloadRoles(),
        },
    )
}
</script>

<template>
    <AppLayout
        :title="$t('page-users.management_title')"
        :subtitle="$t('page-users.index_description')"
    >
        <Head :title="$t('page-users.management_title')" />

        <template #page-actions>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="activeTab === 'users' && can('users.create')"
                    class="gap-2"
                    @click="openCreateDialog"
                >
                    <AppIcon name="user-plus" />
                    <span>{{ $t('page-users.new') }}</span>
                </Button>
                <template v-else-if="activeTab === 'user_groups'">
                    <Button
                        class="gap-2"
                        outlined
                        severity="secondary"
                        @click="openImportUserGroupsDialog"
                    >
                        <AppIcon name="upload" />
                        <span>{{ $t('page-user-groups.import_action') }}</span>
                    </Button>
                    <Button class="gap-2" @click="openCreateUserGroupDialog">
                        <AppIcon name="plus" />
                        <span>{{ $t('page-user-groups.create') }}</span>
                    </Button>
                </template>
                <template v-else-if="activeTab === 'roles'">
                    <Button
                        v-role="'super-admin'"
                        v-can="'roles.update'"
                        class="gap-2"
                        outlined
                        severity="secondary"
                        @click="resyncManagedRoles"
                    >
                        <AppIcon name="refresh" />
                        <span>{{ $t('page-roles.resync') }}</span>
                    </Button>
                    <Button
                        v-if="can('roles.create')"
                        class="gap-2"
                        @click="openCreateRoleDialog"
                    >
                        <AppIcon name="plus" />
                        <span>{{ $t('page-roles.new_role') }}</span>
                    </Button>
                </template>
                <template v-else-if="activeTab === 'tenants'">
                    <Button
                        v-if="can('tenants.create')"
                        class="gap-2"
                        @click="openCreateTenantDialog"
                    >
                        <AppIcon name="plus" />
                        <span>{{ $t('page-tenants.create') }}</span>
                    </Button>
                </template>
            </div>
        </template>

        <div class="cp-user-management">
            <TabsRenderer
                v-model="activeTab"
                class="cp-side-tabs"
                :components="tabComponents"
                layout="vertical"
                :schema="tabSchema"
                sync-with-url
            />
        </div>
    </AppLayout>
</template>

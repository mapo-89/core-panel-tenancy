<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use CorePanel\Database\Seeders\CorePanelPermissionSeeder;
use Illuminate\Database\Seeder;

final class TenantRolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        /** @var array<string, mixed> $originalAccess */
        $originalAccess = (array) config('core-panel-access', []);
        $tenantAccess = $originalAccess;

        unset($tenantAccess['resources']['tenants']);
        unset($tenantAccess['route_permissions']['core-panel.tenants.dtApi']);
        unset($tenantAccess['route_permissions']['core-panel.tenants.index']);
        unset($tenantAccess['route_permissions']['core-panel.tenants.data']);

        $tenantAccess['permission_groups'] = $this->filterTenantPermissionGroups(
            (array) ($tenantAccess['permission_groups'] ?? []),
        );
        $tenantAccess['groups'] = $this->filterTenantPermissionGroups(
            (array) ($tenantAccess['groups'] ?? []),
            true,
        );

        $tenantAccess['role_permissions'] = $this->filterTenantRolePermissions(
            (array) ($tenantAccess['role_permissions'] ?? []),
        );
        $tenantAccess['roles'] = $this->filterLegacyRolePermissions(
            (array) ($tenantAccess['roles'] ?? []),
        );

        config()->set('core-panel-access', $tenantAccess);

        try {
            $this->call(CorePanelPermissionSeeder::class);
        } finally {
            config()->set('core-panel-access', $originalAccess);
        }
    }

    /**
     * @param  array<string, list<string>>  $permissionGroups
     * @return array<string, list<string>>
     */
    private function filterTenantPermissionGroups(array $permissionGroups, bool $legacy = false): array
    {
        if (! $legacy && ! isset($permissionGroups['platform'])) {
            return $permissionGroups;
        }

        $groups = [];
        foreach ($permissionGroups as $key => $resources) {
            if (! is_string($key) || ! is_array($resources)) {
                continue;
            }

            if ($legacy && $key === 'platform') {
                $resources = array_values(array_filter(
                    (array) $resources,
                    static fn (string $resource): bool => $resource !== 'tenants',
                ));
            }

            if ($resources !== []) {
                $groups[$key] = $resources;
            }
        }

        return $groups;
    }

    /**
     * @param  array<string, list<string>|string>  $rolePermissions
     * @return array<string, list<string>|string>
     */
    private function filterTenantRolePermissions(array $rolePermissions): array
    {
        if (! isset($rolePermissions['admin'])) {
            return $rolePermissions;
        }

        $admin = $rolePermissions['admin'];
        if (! is_array($admin)) {
            return $rolePermissions;
        }

        $rolePermissions['admin'] = array_values(array_filter(
            $admin,
            static fn (mixed $permission): bool => is_string($permission) && ! str_starts_with($permission, 'tenants.'),
        ));

        return $rolePermissions;
    }

    /**
     * @param  array<string, array<string, mixed>>  $roles
     * @return array<string, array<string, mixed>>
     */
    private function filterLegacyRolePermissions(array $roles): array
    {
        if (! isset($roles['admin']['permissions']) || ! is_array($roles['admin']['permissions'])) {
            return $roles;
        }

        $roles['admin']['permissions'] = array_values(array_filter(
            $roles['admin']['permissions'],
            static fn (mixed $permission): bool => is_string($permission) && ! str_starts_with($permission, 'tenants.'),
        ));

        return $roles;
    }
}

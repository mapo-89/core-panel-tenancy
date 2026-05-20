<?php

declare(strict_types=1);

namespace CorePanelTenancy;

use CorePanel\Contracts\SettingsLogoUrlGenerator;
use CorePanelTenancy\Console\InstallTenancyCommand;
use CorePanelTenancy\Console\UpdateTenancyCommand;
use CorePanelTenancy\Domains\Tenancy\Policies\TenantPolicy;
use CorePanelTenancy\Support\Media\TenantAwareUrlGenerator;
use CorePanelTenancy\Support\Settings\TenantAwareSettingsLogoUrlGenerator;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Spatie\MediaLibrary\Support\UrlGenerator\DefaultUrlGenerator;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;

final class CorePanelTenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeCorePanelAccessConfig();
        $this->mergeFortifyMiddlewareConfig();
        $this->configureMediaLibraryForTenancy();
        $this->app->bind(SettingsLogoUrlGenerator::class, TenantAwareSettingsLogoUrlGenerator::class);
    }

    public function boot(): void
    {
        $this->ensureUniversalMiddlewareGroup();
        $this->shareTenancyContext();
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'core-panel-tenancy');
        $tenantModel = config('tenancy.tenant_model');

        if (is_string($tenantModel) && $tenantModel !== '' && class_exists($tenantModel)) {
            Gate::policy($tenantModel, TenantPolicy::class);
        }

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            InstallTenancyCommand::class,
            UpdateTenancyCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../stubs/app/Models/Tenant.php' => app_path('Models/Tenant.php'),
            __DIR__.'/../stubs/app/Providers/TenancyServiceProvider.php' => app_path('Providers/TenancyServiceProvider.php'),
            __DIR__.'/../stubs/routes/central.php' => base_path('routes/central.php'),
            __DIR__.'/../stubs/routes/tenant.php' => base_path('routes/tenant.php'),
            __DIR__.'/../stubs/routes/universal.php' => base_path('routes/universal.php'),
            __DIR__.'/../routes/web/admin/settings.php' => base_path('routes/web/admin/settings.php'),
            __DIR__.'/../routes/web/tenants.php' => base_path('routes/web/tenants.php'),
        ], 'core-panel-tenancy-core');

        $this->publishes([
            __DIR__.'/../stubs/config/tenancy.php' => config_path('tenancy.php'),
        ], 'core-panel-tenancy-config');

        $this->publishes([
            ...$this->publishableMigrationsTree(__DIR__.'/../stubs/database/migrations', database_path('migrations')),
            ...$this->publishableTree(__DIR__.'/../stubs/database/seeders', database_path('seeders')),
        ], 'core-panel-tenancy-migrations');

        $this->publishes([
            ...$this->publishableTree(__DIR__.'/../stubs/lang', lang_path()),
            ...$this->publishableTree(__DIR__.'/../resources/lang', $this->app->langPath('vendor/core-panel-tenancy')),
        ], 'core-panel-tenancy-lang');

        $this->publishes([
            __DIR__.'/../stubs/resources/js/components/Users/UserTenantsTab.vue' => resource_path('js/components/Users/UserTenantsTab.vue'),
            __DIR__.'/../stubs/resources/js/pages/Admin/Tenants/Edit.vue' => resource_path('js/pages/Admin/Tenants/Edit.vue'),
            __DIR__.'/../stubs/resources/js/pages/Admin/Tenants/components/TenantForm.vue' => resource_path('js/pages/Admin/Tenants/components/TenantForm.vue'),
            __DIR__.'/../stubs/resources/js/pages/Admin/Users/Index.vue' => resource_path('js/pages/Admin/Users/Index.vue'),
        ], 'core-panel-tenancy-ui');
    }

    private function mergeCorePanelAccessConfig(): void
    {
        /** @var array<string, mixed> $coreAccess */
        $coreAccess = (array) config('core-panel-access', []);
        /** @var array<string, mixed> $tenancyAccess */
        $tenancyAccess = require __DIR__.'/../config/core-panel-access.php';

        $coreAccess['resources'] = array_merge(
            (array) ($coreAccess['resources'] ?? []),
            (array) ($tenancyAccess['resources'] ?? []),
        );

        $coreAccess['route_permissions'] = array_merge(
            (array) ($coreAccess['route_permissions'] ?? []),
            (array) ($tenancyAccess['route_permissions'] ?? []),
        );

        $coreAccess['custom_permissions'] = array_values(array_unique(array_merge(
            (array) ($coreAccess['custom_permissions'] ?? []),
            (array) ($tenancyAccess['custom_permissions'] ?? []),
        )));

        $coreAccess['permission_groups'] = array_merge(
            (array) ($coreAccess['permission_groups'] ?? []),
            $this->resolveTenantPermissionGroups($tenancyAccess),
        );
        $coreAccess['groups'] = array_merge(
            (array) ($coreAccess['groups'] ?? []),
            $this->resolveTenantPermissionGroups($tenancyAccess, 'groups'),
        );

        $coreAccess['role_permissions'] = $this->mergeRolePermissions(
            (array) ($coreAccess['role_permissions'] ?? []),
            (array) ($tenancyAccess['role_permissions'] ?? []),
        );
        $coreAccess['roles'] = $this->mergeLegacyRolePermissions(
            (array) ($coreAccess['roles'] ?? []),
            (array) ($tenancyAccess['roles'] ?? []),
        );

        $coreAccess['role_groups'] = array_merge(
            (array) ($coreAccess['role_groups'] ?? []),
            (array) ($tenancyAccess['role_groups'] ?? []),
        );
        foreach ((array) ($tenancyAccess['roles'] ?? []) as $roleName => $roleConfig) {
            if (! is_array($roleConfig) || ! isset($roleConfig['group']) || ! is_string($roleConfig['group'])) {
                continue;
            }

            if (! isset($coreAccess['role_groups'][$roleName])) {
                $coreAccess['role_groups'][$roleName] = $roleConfig['group'];
            }
        }

        $coreAccess['display_names'] = array_merge_recursive(
            (array) ($coreAccess['display_names'] ?? []),
            (array) ($tenancyAccess['display_names'] ?? []),
        );

        config()->set('core-panel-access', $coreAccess);
    }

    /**
     * @param  array<string, mixed>  $tenancyAccess
     * @return array<string, list<string>>
     */
    private function resolveTenantPermissionGroups(array $tenancyAccess, string $scope = 'permission_groups'): array
    {
        $groups = (array) ($tenancyAccess[$scope] ?? []);
        if ($groups !== []) {
            return $groups;
        }

        return (array) ($tenancyAccess['groups'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $coreRoles
     * @param  array<string, mixed>  $tenantRoles
     * @return array<string, mixed>
     */
    private function mergeLegacyRolePermissions(array $coreRoles, array $tenantRoles): array
    {
        if (! array_key_exists('admin', $tenantRoles) || ! is_array($tenantRoles['admin'])) {
            return $coreRoles;
        }

        $tenantAdminPermissions = $tenantRoles['admin']['permissions'] ?? null;
        if (! is_array($tenantAdminPermissions)) {
            return $coreRoles;
        }

        $admin = $coreRoles['admin'] ?? null;
        if (! is_array($admin)) {
            $admin = [];
        }

        $coreAdmin = is_array($admin['permissions'] ?? null) ? (array) $admin['permissions'] : [];
        $coreRoles['admin']['permissions'] = array_values(array_unique(array_merge(
            $coreAdmin,
            $tenantAdminPermissions,
        )));

        return $coreRoles;
    }

    /**
     * @param  array<string, list<string>|string>  $coreRolePermissions
     * @param  array<string, list<string>|string>  $tenantRolePermissions
     * @return array<string, list<string>|string>
     */
    private function mergeRolePermissions(array $coreRolePermissions, array $tenantRolePermissions): array
    {
        if (! isset($tenantRolePermissions['admin'])) {
            return $coreRolePermissions;
        }

        $admin = (array) $tenantRolePermissions['admin'];
        if ($admin === ['*']) {
            $coreRolePermissions['admin'] = '*';

            return $coreRolePermissions;
        }

        $current = (array) ($coreRolePermissions['admin'] ?? []);
        if ($current === ['*']) {
            return $coreRolePermissions;
        }

        $coreRolePermissions['admin'] = array_values(array_unique(array_merge($current, $admin)));

        return $coreRolePermissions;
    }

    private function ensureUniversalMiddlewareGroup(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $middlewareGroups = $router->getMiddlewareGroups();

        if (array_key_exists('universal', $middlewareGroups)) {
            return;
        }

        $router->middlewareGroup('universal', []);
    }

    private function mergeFortifyMiddlewareConfig(): void
    {
        $fortifyMiddleware = array_values(array_unique([
            ...(array) config('fortify.middleware', ['web']),
            'universal',
            InitializeTenancyByDomain::class,
        ]));

        config()->set('fortify.middleware', $fortifyMiddleware);
    }

    private function configureMediaLibraryForTenancy(): void
    {
        if (! class_exists(DefaultUrlGenerator::class)) {
            return;
        }

        config()->set('media-library.url_generator', TenantAwareUrlGenerator::class);
    }

    private function shareTenancyContext(): void
    {
        Inertia::share('tenancy', static fn (): array => [
            'isCentral' => ! tenancy()->initialized,
            'isTenant' => tenancy()->initialized,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function publishableTree(string $sourceRoot, string $destinationRoot): array
    {
        if (! is_dir($sourceRoot)) {
            return [];
        }

        $paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRoot, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $sourcePath = $file->getPathname();
            $relativePath = ltrim(str_replace($sourceRoot, '', $sourcePath), DIRECTORY_SEPARATOR);
            $paths[$sourcePath] = rtrim($destinationRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$relativePath;
        }

        return $paths;
    }

    /**
     * @return array<string, string>
     */
    private function publishableMigrationsTree(string $sourceRoot, string $destinationRoot): array
    {
        if (! is_dir($sourceRoot)) {
            return [];
        }

        $paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRoot, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $sourcePath = $file->getPathname();
            $relativePath = ltrim(str_replace($sourceRoot, '', $sourcePath), DIRECTORY_SEPARATOR);
            $destinationPath = basename($relativePath);

            if (str_starts_with($relativePath, 'tenant'.DIRECTORY_SEPARATOR)) {
                $destinationPath = 'tenant'.DIRECTORY_SEPARATOR.basename($relativePath);
            }

            $paths[$sourcePath] = rtrim($destinationRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$destinationPath;
        }

        return $paths;
    }
}

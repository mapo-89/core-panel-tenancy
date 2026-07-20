<?php

declare(strict_types=1);

use CorePanel\Contracts\SettingsLogoUrlGenerator;
use CorePanel\Support\Publishing\CorePanelPublisher;
use CorePanelTenancy\Console\InstallTenancyCommand;
use CorePanelTenancy\Console\UpdateTenancyCommand;
use CorePanelTenancy\CorePanelTenancyServiceProvider;
use CorePanelTenancy\Support\Install\HandleInertiaRequestsTenancyMerger;
use CorePanelTenancy\Support\Media\TenantAwareUrlGenerator;
use CorePanelTenancy\Support\Settings\TenantAwareSettingsLogoUrlGenerator;
use CorePanelTenancy\Support\Tenancy\CentralImpersonationContext;
use CorePanelTenancy\Support\Tenancy\TenantSwitcher;
use CorePanelTenancy\Support\Wayfinder\WayfinderRouteUrlNormalizer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;

function tenancyMigrationStubPath(string $filename, string $scope = ''): string
{
    $root = __DIR__.'/../../stubs/database/migrations'.($scope !== '' ? '/'.$scope : '');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getFilename() === $filename) {
            return $file->getPathname();
        }
    }

    return $root.'/'.$filename;
}

function makeTenancyUpdateBasePath(string $suffix): string
{
    return sys_get_temp_dir().'/core-panel-tenancy-update-'.bin2hex(random_bytes(4)).'-'.$suffix;
}

function readTenancyPublishManifest(string $basePath): array
{
    $contents = file_get_contents($basePath.'/storage/app/core-panel/published.json');

    expect($contents)->not->toBeFalse();

    $decoded = json_decode((string) $contents, true, 512, JSON_THROW_ON_ERROR);

    expect($decoded)->toBeArray();

    return $decoded;
}

it('publishes the stancl tenancy foundation for host applications', function (): void {
    $readme = file_get_contents(__DIR__.'/../../README.md');
    $installCommand = file_get_contents(__DIR__.'/../../src/Console/InstallTenancyCommand.php');
    $provider = file_get_contents(__DIR__.'/../../src/CorePanelTenancyServiceProvider.php');
    $tenantController = file_get_contents(__DIR__.'/../../src/Http/Controllers/TenantController.php');
    $storeTenantRequest = file_get_contents(__DIR__.'/../../src/Http/Requests/StoreTenantRequest.php');
    $updateTenantRequest = file_get_contents(__DIR__.'/../../src/Http/Requests/UpdateTenantRequest.php');
    $upsertTenantSuperAdminAction = file_get_contents(__DIR__.'/../../src/Domains/Tenancy/Actions/UpsertTenantSuperAdminAction.php');
    $tenantModel = file_get_contents(__DIR__.'/../../stubs/app/Models/Tenant.php');
    $centralRouteFile = file_get_contents(__DIR__.'/../../stubs/routes/central.php');
    $tenancyConfig = file_get_contents(__DIR__.'/../../stubs/config/tenancy.php');
    $tenantRouteFile = file_get_contents(__DIR__.'/../../stubs/routes/tenant.php');
    $universalRouteFile = file_get_contents(__DIR__.'/../../stubs/routes/universal.php');
    $tenantCentralRouteFile = file_get_contents(__DIR__.'/../../routes/web/tenants.php');
    $tenantSettingsRouteFile = file_get_contents(__DIR__.'/../../routes/web/admin/settings.php');
    $tenantUsersOverride = file_get_contents(__DIR__.'/../../resources/js/pages/Admin/Users/Index.vue');
    $tenantEditPage = file_get_contents(__DIR__.'/../../resources/js/pages/Admin/Tenants/Edit.vue');
    $tenantTab = file_get_contents(__DIR__.'/../../resources/js/components/Users/UserTenantsTab.vue');
    $tenantForm = file_get_contents(__DIR__.'/../../resources/js/pages/Admin/Tenants/components/TenantForm.vue');
    $tenantImpersonationController = file_get_contents(__DIR__.'/../../src/Http/Controllers/CentralTenantImpersonationController.php');
    $tenantAppImpersonationController = file_get_contents(__DIR__.'/../../src/Http/Controllers/TenantImpersonationController.php');
    $leaveTenantImpersonationController = file_get_contents(__DIR__.'/../../src/Http/Controllers/LeaveTenantImpersonationController.php');
    $centralImpersonationContext = file_get_contents(__DIR__.'/../../src/Support/Tenancy/CentralImpersonationContext.php');
    $tenantSwitcher = file_get_contents(__DIR__.'/../../src/Support/Tenancy/TenantSwitcher.php');
    $coreAdminTheme = file_get_contents(__DIR__.'/../../../core-panel/resources/css/theme/_admin.css');
    $coreSidebar = file_get_contents(__DIR__.'/../../../core-panel/resources/js/layouts/components/AppSidebar.vue');
    $appServiceProviderStub = file_get_contents(__DIR__.'/../../stubs/app/Providers/AppServiceProvider.php');
    $addonTypesAwareUsersIndex = file_get_contents(__DIR__.'/../../resources/js/pages/Admin/Users/Index.vue');
    $tenancyServiceProviderStub = file_get_contents(__DIR__.'/../../stubs/app/Providers/TenancyServiceProvider.php');
    $appServiceProviderTenancyMerger = file_get_contents(__DIR__.'/../../src/Support/Install/AppServiceProviderTenancyMerger.php');
    $corePanelTypesTenancyMerger = file_get_contents(__DIR__.'/../../src/Support/Install/CorePanelTypesTenancyMerger.php');
    $handleInertiaRequestsTenancyMerger = file_get_contents(__DIR__.'/../../src/Support/Install/HandleInertiaRequestsTenancyMerger.php');
    $appServiceProviderTenancyHook = file_get_contents(__DIR__.'/../../stubs/merge/app-service-provider.tenancy-hook.stub');
    $corePanelTenancyContextStub = file_get_contents(__DIR__.'/../../stubs/merge/core-panel-tenancy-context.stub');
    $sessionCookieMiddleware = file_get_contents(__DIR__.'/../../src/Http/Middleware/SetTenantAwareSessionCookie.php');
    $handleInertiaRequests = file_get_contents(__DIR__.'/../../../core-panel/stubs/app/Http/Middleware/HandleInertiaRequests.php');
    $tenantsMigration = file_get_contents(tenancyMigrationStubPath('2026_01_01_000001_create_tenants_table.php', 'tenancy'));
    $domainsMigration = file_get_contents(tenancyMigrationStubPath('2026_01_01_000020_create_domains_table.php', 'tenancy'));
    $impersonationTokensMigration = file_get_contents(tenancyMigrationStubPath('2026_01_01_000024_create_tenant_user_impersonation_tokens_table.php', 'tenancy'));
    $tenantUsersMigration = file_get_contents(tenancyMigrationStubPath('0001_01_01_000000_create_users_table.php', 'tenant'));
    $tenantSettingsMigration = file_get_contents(tenancyMigrationStubPath('2026_01_01_000003_create_core_panel_settings_table.php', 'tenant'));
    $tenantUserGroupsMigration = file_get_contents(tenancyMigrationStubPath('2026_01_01_000019_create_user_groups_table.php', 'tenant'));
    $tenantPermissionMigration = file_get_contents(tenancyMigrationStubPath('2026_01_01_000014_create_permission_tables.php', 'tenant'));
    $tenantOauthAuthCodesMigration = file_get_contents(tenancyMigrationStubPath('2016_06_01_000001_create_oauth_auth_codes_table.php', 'tenant'));
    $tenantOauthAccessTokensMigration = file_get_contents(tenancyMigrationStubPath('2016_06_01_000002_create_oauth_access_tokens_table.php', 'tenant'));
    $tenantOauthAccessTokensLastUsedMigration = file_get_contents(tenancyMigrationStubPath('2026_01_01_000022_add_last_used_at_to_oauth_access_tokens_table.php', 'tenant'));
    $tenantOauthClientsMigration = file_get_contents(tenancyMigrationStubPath('2016_06_01_000004_create_oauth_clients_table.php', 'tenant'));
    $tenantOauthDeviceCodesMigration = file_get_contents(tenancyMigrationStubPath('2024_06_01_000001_create_oauth_device_codes_table.php', 'tenant'));
    $tenantMediaMigration = file_get_contents(tenancyMigrationStubPath('2019_01_01_000001_create_media_table.php', 'tenant'));
    $tenantAwareUrlGenerator = file_get_contents(__DIR__.'/../../src/Support/Media/TenantAwareUrlGenerator.php');
    $tenantDatabaseSeeder = file_get_contents(__DIR__.'/../../stubs/database/seeders/Tenant/TenantDatabaseSeeder.php');
    $tenantRolePermissionSeeder = file_get_contents(__DIR__.'/../../stubs/database/seeders/Tenant/TenantRolePermissionSeeder.php');
    $tenantGermanTranslations = file_get_contents(__DIR__.'/../../resources/lang/de/page-tenants.php');
    $tenantEnglishTranslations = file_get_contents(__DIR__.'/../../resources/lang/en/page-tenants.php');
    $tenantGermanTenancyTranslations = file_get_contents(__DIR__.'/../../resources/lang/de/tenancy.php');
    $tenantEnglishTenancyTranslations = file_get_contents(__DIR__.'/../../resources/lang/en/tenancy.php');
    $upsertTenantSuperAdminAction = file_get_contents(__DIR__.'/../../src/Domains/Tenancy/Actions/UpsertTenantSuperAdminAction.php');
    $tenantSettingsController = file_get_contents(__DIR__.'/../../src/Http/Controllers/SettingsController.php');

    expect($readme)->toContain('Read-only split repository')
        ->and($readme)->toContain('mapo-89/core-panel-monorepo')
        ->and($readme)->toContain('composer update mapo-89/core-panel-tenancy')
        ->and($readme)->toContain('php artisan core-panel:tenancy:update --force')
        ->and($readme)->toContain('composer update mapo-89/core-panel mapo-89/core-panel-tenancy')
        ->and($readme)->toContain('php artisan core-panel:update --force --with-addon-updates')
        ->and($readme)->toContain('npm install')
        ->and($readme)->toContain('npm run build')
        ->and($readme)->toContain('php artisan optimize:clear')
        ->and($readme)->toContain('git rm -r --cached -- resources/js/actions resources/js/routes resources/js/wayfinder public/build public/hot')
        ->and($readme)->toContain("runs the host application's outstanding migrations once")
        ->and($installCommand)->toContain('core-panel-tenancy-core')
        ->and($installCommand)->toContain('core-panel-tenancy-config')
        ->and($installCommand)->toContain('core-panel-tenancy-migrations')
        ->and($installCommand)->toContain('core-panel-tenancy-lang-vendor')
        ->and($installCommand)->toContain('CorePanelPublisher::class')
        ->and($installCommand)->toContain('publishForProvider(')
        ->and($installCommand)->not->toContain("\$this->call('vendor:publish'")
        ->and($installCommand)->toContain('AppServiceProviderTenancyMerger')
        ->and($installCommand)->toContain('CorePanelTypesTenancyMerger')
        ->and($installCommand)->toContain('HandleInertiaRequestsTenancyMerger')
        ->and($installCommand)->toContain('appServiceProviderTenancyMerger->merge();')
        ->and($installCommand)->toContain('corePanelTypesTenancyMerger->merge();')
        ->and($installCommand)->toContain('handleInertiaRequestsTenancyMerger->merge();')
        ->and($installCommand)->toContain("database_path('migrations/2026_01_01_000021_add_data_column_to_existing_tenants_table.php')")
        ->and($installCommand)->toContain("resource_path('js/routes/core-panel/tenants.ts')")
        ->and($installCommand)->toContain("'CENTRAL_DOMAINS' =>")
        ->and($installCommand)->toContain("config('tenancy.central_domains_env', '')")
        ->and($installCommand)->toContain("return \$domains === ['127.0.0.1', 'localhost'];")
        ->and($installCommand)->toContain("'TENANCY_CENTRAL_CONNECTION' =>")
        ->and($installCommand)->toContain("config('tenancy.database.central_connection_env', '')")
        ->and($installCommand)->toContain("config('database.default_env', '')")
        ->and($installCommand)->not->toContain('CORE_PANEL_TENANCY_ADAPTER')
        ->and($installCommand)->not->toContain('CORE_PANEL_TENANCY_MODE')
        ->and($provider)->toContain('core-panel-tenancy-core')
        ->and($provider)->toContain('mergeFortifyMiddlewareConfig')
        ->and($provider)->toContain('configureMediaLibraryForTenancy')
        ->and($provider)->toContain('shareTenancyContext')
        ->and($provider)->toContain('shareNavigation')
        ->and($provider)->toContain('SettingsLogoUrlGenerator::class')
        ->and($provider)->toContain('TenantAwareSettingsLogoUrlGenerator::class')
        ->and($provider)->toContain("Inertia::share('tenancy'")
        ->and($provider)->toContain("Inertia::share(\n            'navigation.tenant_switcher'")
        ->and($provider)->toContain("config()->set('fortify.middleware', \$fortifyMiddleware);")
        ->and($provider)->toContain("config()->set('media-library.url_generator', TenantAwareUrlGenerator::class);")
        ->and($provider)->toContain('InitializeTenancyByDomain::class')
        ->and($provider)->toContain("middlewareGroup('universal', [])")
        ->and($provider)->toContain("stubs/routes/central.php' => base_path('routes/central.php')")
        ->and($provider)->toContain("stubs/routes/tenant.php' => base_path('routes/tenant.php')")
        ->and($provider)->toContain("stubs/routes/universal.php' => base_path('routes/universal.php')")
        ->and($provider)->toContain("routes/web/admin/settings.php' => base_path('routes/web/admin/settings.php')")
        ->and($provider)->toContain("routes/web/tenants.php' => base_path('routes/web/tenants.php')")
        ->and($provider)->not->toContain('stubs/app/Providers/AppServiceProvider.php')
        ->and($provider)->toContain("publishableMigrationsTree(__DIR__.'/../stubs/database/migrations', database_path('migrations'))")
        ->and($provider)->toContain("publishableTree(__DIR__.'/../stubs/database/seeders', database_path('seeders'))")
        ->and($provider)->toContain("publishableTree(__DIR__.'/../stubs/lang', lang_path())")
        ->and($provider)->toContain("publishableTree(__DIR__.'/../resources/lang', \$this->app->langPath('vendor/core-panel-tenancy'))")
        ->and($provider)->toContain("resources/js/pages/Admin/Users/Index.vue' => resource_path('js/pages/Admin/Users/Index.vue')")
        ->and($tenantRouteFile)->toContain("\$packageWebRoutesRoot = base_path('vendor/mapo-89/core-panel/routes/web');")
        ->and($tenantRouteFile)->toContain('Unable to locate CorePanel tenant web route fragment')
        ->and($tenantUsersOverride)->toContain('resyncManagedRoles')
        ->and($tenantUsersOverride)->toContain("'assignableRoles'")
        ->and($tenantUsersOverride)->toContain("'canAssignRoles'")
        ->and($appServiceProviderTenancyMerger)->toContain('stubs/app/Providers/AppServiceProvider.php')
        ->and($appServiceProviderTenancyMerger)->toContain('stubs/merge/app-service-provider.tenancy-hook.stub')
        ->and($handleInertiaRequestsTenancyMerger)->toContain('app/Http/Middleware/HandleInertiaRequests.php')
        ->and($handleInertiaRequestsTenancyMerger)->toContain('TenantSwitcher::class')
        ->and($handleInertiaRequestsTenancyMerger)->toContain("'tenant_switcher' => fn (): ?array =>")
        ->and($handleInertiaRequestsTenancyMerger)->toContain('app(\\\\CorePanelTenancy\\\\Support\\\\Tenancy\\\\TenantSwitcher::class)->forRequest(\$request)')
        ->and($handleInertiaRequestsTenancyMerger)->toContain('forRequest(\$request)')
        ->and($appServiceProviderTenancyHook)->toContain('Vite::createAssetPathsUsing(')
        ->and($appServiceProviderTenancyHook)->toContain('CommandFinished $event')
        ->and($corePanelTypesTenancyMerger)->toContain('stubs/merge/core-panel-tenancy-context.stub')
        ->and($corePanelTenancyContextStub)->toContain('export type CorePanelTenancyContext = {')
        ->and($appServiceProviderStub)->toContain("if (function_exists('global_asset')) {")
        ->and($appServiceProviderStub)->toContain('Vite::createAssetPathsUsing(')
        ->and($appServiceProviderStub)->toContain('Event::listen(static function (CommandFinished $event) use ($wayfinderRouteUrlNormalizer): void {')
        ->and($appServiceProviderStub)->toContain('app($wayfinderRouteUrlNormalizer)->normalize();')
        ->and($tenancyServiceProviderStub)->toContain('use Stancl\Tenancy\Controllers\TenantAssetsController;')
        ->and($tenancyServiceProviderStub)->toContain('TenantAssetsController::$tenancyMiddleware = Middleware\InitializeTenancyByDomain::class;')
        ->and($tenantModel)->toContain('class Tenant extends BaseTenant implements TenantWithDatabase')
        ->and($tenantModel)->toContain('use HasDatabase;')
        ->and($tenantModel)->toContain('use HasDomains;')
        ->and($tenantModel)->not->toContain('getCustomColumns')
        ->and($tenantController)->toContain("return redirect()->route('core-panel.users.index', ['tab' => 'tenants']);")
        ->and($tenantController)->toContain('public function dtApi(): JsonResponse')
        ->and($tenantController)->toContain('public function edit(string $tenant): Response')
        ->and($tenantController)->toContain('UpsertTenantSuperAdminAction')
        ->and($tenantController)->toContain("->route('core-panel.users.index', ['tab' => 'tenants'])")
        ->and($tenantImpersonationController)->toContain('ImpersonationToken::create([')
        ->and($tenantImpersonationController)->toContain("route('tenant.core-panel.dashboard', absolute: false)")
        ->and($tenantAppImpersonationController)->toContain('ImpersonationToken::findOrFail($token)')
        ->and($leaveTenantImpersonationController)->toContain('return Inertia::location($url);')
        ->and($centralImpersonationContext)->toContain("public const SESSION_KEY = 'tenant_impersonation.central_user_id';")
        ->and($tenantSwitcher)->toContain('public function forRequest(Request $request): ?array')
        ->and($tenantSwitcher)->toContain("'tenant.tenants.impersonate'")
        ->and($tenantSwitcher)->toContain("'tenants.impersonate'")
        ->and($provider)->not->toContain("'tenant.tenants.impersonate' => 'tenants.update'")
        ->and($tenantRolePermissionSeeder)->toContain("unset(\$tenantAccess['route_permissions']['tenants.dtApi']);")
        ->and($tenantRolePermissionSeeder)->toContain("unset(\$tenantAccess['route_permissions']['tenants.index']);")
        ->and($tenantRolePermissionSeeder)->toContain("unset(\$tenantAccess['route_permissions']['tenants.data']);")
        ->and($storeTenantRequest)->toContain("'database_name' => ['nullable', 'string', 'max:255']")
        ->and($storeTenantRequest)->toContain("'super_admin_password' => ['required', 'confirmed', Password::defaults()]")
        ->and($updateTenantRequest)->toContain('shouldManageTenantSuperAdmin')
        ->and($upsertTenantSuperAdminAction)->toContain("Role::findOrCreate('super-admin', 'web');")
        ->and($tenancyConfig)->toContain("'tenant_model' => Tenant::class")
        ->and($tenancyConfig)->toContain('use CorePanel\Support\Migrations\MigrationPathResolver;')
        ->and($tenancyConfig)->toContain('UserImpersonation::class')
        ->and($tenancyConfig)->toContain('UniversalRoutes::class')
        ->and($tenantsMigration)->toContain('$table->timestampsTz();')
        ->and($domainsMigration)->toContain('$table->timestampsTz();')
        ->and($impersonationTokensMigration)->toContain("\$table->timestampTz('created_at');")
        ->and($tenantUsersMigration)->toContain("\$table->timestampTz('email_verified_at')->nullable();")
        ->and($tenantUsersMigration)->toContain('$table->softDeletesTz();')
        ->and($tenantUsersMigration)->toContain('$table->timestampsTz();')
        ->and($tenancyConfig)->toContain("'asset_helper_tenancy' => false")
        ->and($tenancyConfig)->toContain("'--path' => MigrationPathResolver::tenant()")
        ->and($tenancyConfig)->toContain("'--class' => 'Database\\\\Seeders\\\\Tenant\\\\TenantDatabaseSeeder'")
        ->and($tenantUsersOverride)->toContain("key: 'tenants'")
        ->and($tenantUsersOverride)->toContain("label: 'page-tenants.page_title'")
        ->and($tenantUsersOverride)->toContain("panelSurfaceVariant: 'card'")
        ->and($addonTypesAwareUsersIndex)->toContain('CorePanelTenancyContext')
        ->and($tenantUsersOverride)->toContain('const canManageTenants = computed(')
        ->and($tenantUsersOverride)->toContain('page.props.tenancy?.isCentral === true')
        ->and($tenantUsersOverride)->toContain("import UserTenantsTab from '@/components/Users/UserTenantsTab.vue'")
        ->and($tenantTab)->toContain("import { useDateTime } from '@core-panel/composables/useDateTime'")
        ->and($tenantTab)->toContain('const { formatDateTime } = useDateTime()')
        ->and($tenantTab)->toContain('formatDateTime(String(row.created_at))')
        ->and($tenantEditPage)->toContain("import TenantForm from '@/pages/Admin/Tenants/components/TenantForm.vue'")
        ->and($tenantTab)->toContain('destroy as destroyTenant')
        ->and($tenantTab)->toContain('dtApi as tenantDtApi')
        ->and($tenantTab)->toContain('impersonate as impersonateTenant')
        ->and($tenantTab)->toContain("from '@/routes/tenants'")
        ->and($tenantTab)->toContain('primary_domain')
        ->and($tenantTab)->toContain('database_name')
        ->and($tenantTab)->toContain("import TableBuilderDataTable from '@core-panel/components/TableBuilder/DataTable.vue'")
        ->and($tenantTab)->toContain('<TableBuilderDataTable')
        ->and($tenantTab)->toContain('mode="local"')
        ->and($tenantTab)->toContain("\$t('page-tenants.impersonate')")
        ->and($tenantTab)->toContain("v-if=\"can('tenants.update') && row.super_admin?.id\"")
        ->and($tenantTab)->toContain('<AppIcon name="user-round-plus" />')
        ->and($tenantTab)->toContain('cp-datatable__action-button')
        ->and($tenantTab)->toContain('surface-class="cp-user-tenants-tab__surface"')
        ->and($coreAdminTheme)->toContain('.cp-datatable__action-button {')
        ->and($coreSidebar)->toContain('tenantSwitcher')
        ->and($coreSidebar)->toContain('tenantSwitcherOptions.length > 0')
        ->and($coreSidebar)->toContain("\$t('common.ui.search')")
        ->and($handleInertiaRequests)->toContain("'corePanel' => [")
        ->and($tenantForm)->toContain('index as tenantsIndex')
        ->and($tenantForm)->toContain('store as storeTenant')
        ->and($tenantForm)->toContain('update as updateTenant')
        ->and($tenantForm)->toContain("from '@/routes/tenants'")
        ->and($tenantForm)->toContain('super_admin_first_name')
        ->and($tenantForm)->toContain('database_name')
        ->and($tenantForm)->toContain('page-tenants.tenant_id_hint')
        ->and($tenantForm)->toContain('page-tenants.database_name_hint')
        ->and($tenantForm)->toContain("import TranslatedPassword from '@core-panel/components/TranslatedPassword.vue'")
        ->and($tenantForm)->toContain('<TranslatedPassword')
        ->and($tenantForm)->toContain(':min-length="8"')
        ->and($tenantForm)->toContain(':match-password="form.super_admin_password"')
        ->and($tenantForm)->not->toContain('const showPasswordRequirements = computed(() => {')
        ->and($tenantGermanTranslations)->toContain("'super_admin_password_hint' =>")
        ->and($tenantGermanTranslations)->toContain("'impersonate' => 'Impersonalisieren'")
        ->and($tenantGermanTenancyTranslations)->toContain("'assigned_domains' =>")
        ->and($tenantEnglishTranslations)->toContain("'super_admin_password_hint' =>")
        ->and($tenantEnglishTranslations)->toContain("'impersonate' => 'Impersonate'")
        ->and($tenantEnglishTenancyTranslations)->toContain("'assigned_domains' =>")
        ->and($sessionCookieMiddleware)->toContain("\$cookieName = \$defaultCookie.'_'.\$suffix;")
        ->and($sessionCookieMiddleware)->toContain("config()->set('session.cookie_base', \$defaultCookie);")
        ->and($sessionCookieMiddleware)->toContain("config()->set('session.cookie', \$cookieName);")
        ->and($sessionCookieMiddleware)->toContain("app('session')->setName(\$cookieName);")
        ->and($sessionCookieMiddleware)->toContain("app('session.store')->setName(\$cookieName);")
        ->and($sessionCookieMiddleware)->toContain("return 'central';")
        ->and($sessionCookieMiddleware)->toContain("preg_replace('/[^A-Za-z0-9]+/', '_', \$host)")
        ->and($centralRouteFile)->toContain("require base_path('routes/universal.php');")
        ->and($centralRouteFile)->toContain('$hostTenantRoutesPath = base_path(\'routes/web/tenants.php\');')
        ->and($centralRouteFile)->toContain('$packageTenantRoutesPath = base_path(\'vendor/mapo-89/core-panel-tenancy/routes/web/tenants.php\');')
        ->and($centralRouteFile)->toContain('$loadTenantCentralRoutes = static function () use ($hostTenantRoutesPath, $packageTenantRoutesPath): void {')
        ->and($centralRouteFile)->toContain('if (is_file($hostTenantRoutesPath)) {')
        ->and($centralRouteFile)->toContain('if (is_file($packageTenantRoutesPath)) {')
        ->and($tenantRouteFile)->toContain('TenantImpersonationController::class')
        ->and($tenantRouteFile)->toContain('LeaveTenantImpersonationController::class')
        ->and($tenantRouteFile)->toContain('RedirectImpersonatingTenantGuest::class')
        ->and($tenantRouteFile)->toContain("Route::get('/impersonate/{token}', TenantImpersonationController::class)->name('impersonate');")
        ->and($tenantRouteFile)->toContain("Route::get('/leave-impersonation', LeaveTenantImpersonationController::class)->name('leave-impersonation');")
        ->and($tenantRouteFile)->toContain('RedirectImpersonatingTenantGuest::class,')
        ->and($tenantRouteFile)->toContain('CentralTenantImpersonationController::class')
        ->and($tenantRouteFile)->toContain('Route::middleware($tenantWebMiddleware)->group(function () use ($corePanelRouteMiddleware, $loadTenantWebRouteFile, $webRoutes): void {')
        ->and($tenantRouteFile)->toContain("Route::name('tenant.')->group(function () use (\$corePanelRouteMiddleware, \$loadTenantWebRouteFile, \$webRoutes): void {")
        ->and($tenantRouteFile)->toContain('])->group(function () use ($loadTenantWebRouteFile, $webRoutes): void {')
        ->and($tenantRouteFile)->toContain("Route::post('/tenants/{tenant}/impersonate', CentralTenantImpersonationController::class)")
        ->and($tenantRouteFile)->not->toContain('$hostTenantRoutesPath = base_path(\'routes/web/tenants.php\');')
        ->and($tenantRouteFile)->not->toContain('$packageTenantRoutesPath =')
        ->and($tenantRouteFile)->toContain('InitializeTenancyByDomain::class')
        ->and($tenantRouteFile)->toContain('PreventAccessFromCentralDomains::class')
        ->and($tenantRouteFile)->toContain("Route::name('tenant.')->group(function () use")
        ->and($tenantRouteFile)->toContain("foreach (\$webRoutes['public'] as \$publicRouteFile)")
        ->and($universalRouteFile)->not->toContain("\$loadUniversalWebRouteFile('auth.php');")
        ->and($universalRouteFile)->toContain("\$loadUniversalWebRouteFile('platform.php');")
        ->and($tenantRouteFile)->toContain("\$packageWebRoutesRoot = base_path('vendor/mapo-89/core-panel/routes/web');")
        ->and($tenantRouteFile)->toContain('Unable to locate CorePanel tenant web route fragment')
        ->and($tenantSettingsRouteFile)->toContain('use CorePanelTenancy\Http\Controllers\SettingsController;')
        ->and($tenantSettingsRouteFile)->toContain("Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');")
        ->and($tenantSettingsRouteFile)->toContain("Route::put('/settings/styles', [SettingsController::class, 'updateStyles'])->name('settings.styles');")
        ->and($tenantSettingsRouteFile)->toContain("Route::put('/settings/{group}', [SettingsController::class, 'update'])->name('settings.update');")
        ->and($tenantCentralRouteFile)->toContain("Route::get('/tenants/dt', 'dtApi')->name('tenants.dtApi');")
        ->and($tenantCentralRouteFile)->toContain("Route::get('/tenants/{tenant}/edit', 'edit')->name('tenants.edit');")
        ->and($tenantCentralRouteFile)->toContain("Route::post('/tenants/{tenant}/impersonate', CentralTenantImpersonationController::class)")
        ->and($tenantsMigration)->toContain("\$table->string('id')->primary()")
        ->and($tenantsMigration)->toContain('$table->timestampsTz();')
        ->and($tenantsMigration)->toContain("\$table->json('data')->nullable()")
        ->and($tenantsMigration)->not->toContain("\$table->string('name');")
        ->and($tenantsMigration)->not->toContain("\$table->string('tenancy_db_name')->nullable();")
        ->and($domainsMigration)->toContain("\$table->string('domain', 255)->unique()")
        ->and($domainsMigration)->toContain("\$table->string('tenant_id');")
        ->and($impersonationTokensMigration)->toContain("Schema::create('tenant_user_impersonation_tokens'")
        ->and($impersonationTokensMigration)->toContain("\$table->string('token', 128)->primary();")
        ->and($impersonationTokensMigration)->toContain("\$table->foreign('tenant_id')")
        ->and($tenantUsersMigration)->toContain("\$table->uuid('id')->primary();")
        ->and($tenantUsersMigration)->toContain("\$table->string('first_name');")
        ->and($tenantUsersMigration)->toContain("\$table->string('last_name');")
        ->and($tenantUsersMigration)->toContain("\$table->string('locale', 12)->nullable();")
        ->and($tenantUsersMigration)->toContain("\$table->string('user_id')->nullable()->index();")
        ->and($tenantUsersMigration)->toContain("\$table->boolean('requires_password_setup')->default(false);")
        ->and($tenantUsersMigration)->toContain("\$table->timestampTz('invited_at')->nullable();")
        ->and($tenantUsersMigration)->toContain("\$table->timestampTz('invitation_accepted_at')->nullable();")
        ->and($tenantUsersMigration)->toContain('$table->softDeletesTz();')
        ->and($tenantUserGroupsMigration)->toContain("\$table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();")
        ->and($tenantPermissionMigration)->toContain("\$table->string('core_panel_group')->nullable();")
        ->and($tenantPermissionMigration)->toContain("\$table->string(\$columnNames['model_morph_key']);")
        ->and($tenantOauthAuthCodesMigration)->toContain("\$table->uuid('user_id')->index();")
        ->and($tenantOauthAccessTokensMigration)->toContain("\$table->uuid('user_id')->nullable()->index();")
        ->and($tenantOauthAccessTokensMigration)->toContain("\$table->timestampTz('last_used_at')->nullable();")
        ->and($tenantOauthAccessTokensLastUsedMigration)->toContain("Schema::table('oauth_access_tokens'")
        ->and($tenantOauthAccessTokensLastUsedMigration)->toContain("\$table->timestampTz('last_used_at')->nullable()->after('updated_at');")
        ->and($tenantOauthClientsMigration)->toContain("\$table->nullableUuidMorphs('owner');")
        ->and($tenantOauthDeviceCodesMigration)->toContain("\$table->uuid('user_id')->nullable()->index();")
        ->and(file_exists(__DIR__.'/../../stubs/database/migrations/tenant/2019_12_14_000001_create_personal_access_tokens_table.php'))->toBeFalse()
        ->and($tenantSettingsMigration)->toContain("Schema::create('settings'")
        ->and(file_exists(tenancyMigrationStubPath('2026_01_01_000021_create_authentication_logs_table.php', 'tenant')))->toBeTrue()
        ->and($tenantMediaMigration)->toContain("\$table->string('model_id');")
        ->and(file_exists(__DIR__.'/../../stubs/database/migrations/tenant/2026_01_01_000004_add_core_panel_fields_to_users_table.php'))->toBeFalse()
        ->and(file_exists(__DIR__.'/../../stubs/database/migrations/tenant/2026_01_01_000016_add_core_panel_metadata_to_roles_table.php'))->toBeFalse()
        ->and(file_exists(__DIR__.'/../../stubs/database/migrations/tenant/2026_01_01_000020_add_requires_password_setup_to_users_table.php'))->toBeFalse()
        ->and(file_exists(tenancyMigrationStubPath('2026_01_01_000023_add_invitation_tracking_columns_to_users_table.php', 'tenant')))->toBeTrue()
        ->and(file_exists(__DIR__.'/../../stubs/database/migrations/tenant/2026_01_01_000021_change_activity_log_morph_ids_to_strings.php'))->toBeFalse()
        ->and(file_exists(__DIR__.'/../../stubs/database/migrations/tenant/2026_01_01_000022_change_media_model_morph_ids_to_strings.php'))->toBeFalse()
        ->and($tenantAwareUrlGenerator)->toContain('class TenantAwareUrlGenerator extends DefaultUrlGenerator')
        ->and($tenantAwareUrlGenerator)->toContain('$url = asset($this->getPathRelativeToRoot());')
        ->and($tenantSettingsController)->toContain("'apiTokenManager' => \$this->apiTokenManagerPayload(\$request)")
        ->and($tenantSettingsController)->toContain("'key' => 'api'")
        ->and($tenantSettingsController)->toContain("__('page-settings.tab_api')")
        ->and($tenantSettingsController)->toContain("'abilities' => ApiTokenAbilityOptions::options()")
        ->and($tenantSettingsController)->toContain("'canCreate' => true")
        ->and($tenantSettingsController)->toContain("'canDelete' => true")
        ->and($tenantSettingsController)->toContain("->route(\$this->tenantAwareRouteName('core-panel.settings.index'), ['tab' => \$tab])")
        ->and($tenantSettingsController)->toContain("->route(\$this->tenantAwareRouteName('core-panel.settings.index'), ['tab' => 'appearance'])")
        ->and($tenantSettingsController)->toContain("return 'tenant.'.\$routeName;")
        ->and($tenantSettingsController)->toContain("if (\$group === 'general' && \$key === 'app_subtitle' && \$value === null) {")
        ->and($tenantSettingsController)->toContain("\$value = '';")
        ->and($tenantDatabaseSeeder)->toContain('TenantRolePermissionSeeder::class')
        ->and($tenantDatabaseSeeder)->toContain('CorePanelSettingsSeeder::class')
        ->and($tenantRolePermissionSeeder)->toContain('CorePanelPermissionSeeder::class')
        ->and($tenantRolePermissionSeeder)->toContain("unset(\$tenantAccess['resources']['tenants']);")
        ->and($upsertTenantSuperAdminAction)->toContain("if (in_array('mobile', \$userColumns, true))")
        ->and($upsertTenantSuperAdminAction)->toContain("\$attributes['requires_password_setup'] = false;");
});

it('configures Media Library to use the tenant-aware URL generator when the addon is loaded', function (): void {
    expect(config('media-library.url_generator'))->toBe(TenantAwareUrlGenerator::class);
});

it('binds the tenant-aware settings logo url generator when the addon is loaded', function (): void {
    expect(app(SettingsLogoUrlGenerator::class))
        ->toBeInstanceOf(TenantAwareSettingsLogoUrlGenerator::class);
});

it('registers tenancy impersonation helpers in the service container', function (): void {
    expect(app(CentralImpersonationContext::class))
        ->toBeInstanceOf(CentralImpersonationContext::class)
        ->and(app(TenantSwitcher::class))
        ->toBeInstanceOf(TenantSwitcher::class);
});

it('registers publish tags for the direct stancl addon resources', function (): void {
    $rootLangPublishes = ServiceProvider::pathsToPublish(
        CorePanelTenancyServiceProvider::class,
        'core-panel-tenancy-lang',
    );
    $vendorLangPublishes = ServiceProvider::pathsToPublish(
        CorePanelTenancyServiceProvider::class,
        'core-panel-tenancy-lang-vendor',
    );
    $vendorPublishDestinations = array_values($vendorLangPublishes);

    expect(ServiceProvider::pathsToPublish(
        CorePanelTenancyServiceProvider::class,
        'core-panel-tenancy-core',
    ))->not->toBeEmpty()
        ->and(ServiceProvider::pathsToPublish(
            CorePanelTenancyServiceProvider::class,
            'core-panel-tenancy-config',
        ))->not->toBeEmpty()
        ->and(ServiceProvider::pathsToPublish(
            CorePanelTenancyServiceProvider::class,
            'core-panel-tenancy-migrations',
        ))->not->toBeEmpty()
        ->and($rootLangPublishes)->not->toBeEmpty()
        ->and($vendorLangPublishes)->not->toBeEmpty()
        ->and(collect($vendorPublishDestinations)->contains(
            static fn (string $path): bool => str_ends_with($path, '/lang/vendor/core-panel-tenancy/en/page-tenants.php'),
        ))->toBeTrue()
        ->and(collect($vendorPublishDestinations)->contains(
            static fn (string $path): bool => str_ends_with($path, '/lang/vendor/core-panel-tenancy/de/page-tenants.php'),
        ))->toBeTrue();
});

it('injects a fully qualified tenant switcher into existing inertia middleware mergers', function (): void {
    $basePath = makeTenancyUpdateBasePath('handle-inertia-requests-fqcn');
    $middlewarePath = $basePath.'/app/Http/Middleware/HandleInertiaRequests.php';

    mkdir(dirname($middlewarePath), 0777, true);
    file_put_contents($middlewarePath, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware as InertiaMiddleware;

final class HandleInertiaRequests extends InertiaMiddleware
{
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'locale' => [
                'current' => app()->currentLocale(),
            ],
        ];
    }
}
PHP);

    app(HandleInertiaRequestsTenancyMerger::class)->merge($basePath);

    $contents = file_get_contents($middlewarePath);

    expect($contents)->toBeString()
        ->and($contents)->toContain("'tenant_switcher' => fn (): ?array => app(\CorePanelTenancy\Support\Tenancy\TenantSwitcher::class)->forRequest(\$request)")
        ->and($contents)->not->toContain('use CorePanelTenancy\\Support\\Tenancy\\TenantSwitcher;');
});

it('normalizes generated wayfinder route urls back to relative paths for central-domain routes', function (): void {
    $basePath = sys_get_temp_dir().'/core-panel-wayfinder-'.bin2hex(random_bytes(8));
    $routesPath = $basePath.'/resources/js/routes/profile';

    mkdir($routesPath, 0777, true);

    file_put_contents(
        $routesPath.'/index.ts',
        <<<'TS'
export const show = {
    definition: {
        url: 'https://core-panel-app.test/profile',
    },
};

// @route 'https://core-panel-app.test/profile'
TS,
    );

    config()->set('app.url', 'https://core-panel-app.test');
    config()->set('tenancy.central_domains', ['core-panel-app.test']);

    app(WayfinderRouteUrlNormalizer::class)->normalize($basePath);

    $contents = file_get_contents($routesPath.'/index.ts');

    expect($contents)->toContain("url: '/profile'")
        ->and($contents)->toContain("// @route '/profile'")
        ->and($contents)->not->toContain('//core-panel-app.test/profile')
        ->and($contents)->not->toContain('https://core-panel-app.test/profile')
        ->and($contents)->not->toContain('https:/profile');
});

it('normalizes url-like central domain inputs to bare hosts during tenancy installation', function (): void {
    $command = app(InstallTenancyCommand::class);
    $method = new ReflectionMethod($command, 'normalizeDomains');
    $method->setAccessible(true);

    /** @var list<string> $domains */
    $domains = $method->invoke($command, [
        'https://core-panel-app.test',
        'https://admin.core-panel-app.test/dashboard',
        'admin.core-panel-app.test:8443',
        'core-panel-app.test',
    ]);

    expect($domains)->toBe([
        'core-panel-app.test',
        'admin.core-panel-app.test',
    ]);
});

it('registers tenancy update command', function (): void {
    $commands = Artisan::all();

    expect($commands)->toHaveKeys([
        'core-panel:tenancy:install',
        'core-panel:tenancy:update',
    ])
        ->and($commands['core-panel:tenancy:update'])->toBeInstanceOf(UpdateTenancyCommand::class);
});

it('runs tenancy update command in dry-run', function (): void {
    $this->artisan('core-panel:tenancy:update', [
        '--dry-run' => true,
        '--force' => true,
    ])->assertExitCode(0);
});

it('limits tenancy update dry-runs to tenancy provider tags', function (): void {
    Artisan::call('core-panel:tenancy:update', [
        '--dry-run' => true,
        '--force' => true,
        '--base-path' => makeTenancyUpdateBasePath('dry-run'),
    ]);

    $output = Artisan::output();

    expect($output)->toContain('core-panel-tenancy-core')
        ->and($output)->not->toContain('core-panel-components')
        ->and($output)->not->toContain('core-panel-theme')
        ->and($output)->not->toContain('core-panel-lang')
        ->and($output)->not->toContain('core-panel-views');
});

it('does not create published snapshots during tenancy update dry-runs for legacy manifest entries', function (): void {
    $basePath = makeTenancyUpdateBasePath('dry-run-legacy-snapshot');
    $relativePath = 'resources/js/pages/Admin/Users/Index.vue';
    $target = $basePath.'/'.$relativePath;
    $source = __DIR__.'/../../resources/js/pages/Admin/Users/Index.vue';
    $contents = (string) file_get_contents($source);
    $hash = md5($contents);
    $manifestPath = $basePath.'/storage/app/core-panel/published.json';

    mkdir(dirname($target), 0777, true);
    mkdir(dirname($manifestPath), 0777, true);

    file_put_contents($target, $contents);
    file_put_contents($manifestPath, json_encode([
        'files' => [
            $target => [
                'tag' => 'core-panel-tenancy-ui',
                'source' => $source,
                'source_hash' => $hash,
                'destination_hash' => $hash,
                'published_at' => now()->subDay()->toAtomString(),
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");

    $beforeManifest = (string) file_get_contents($manifestPath);

    $this->artisan('core-panel:tenancy:update', [
        '--dry-run' => true,
        '--base-path' => $basePath,
    ])->assertExitCode(0);

    expect((string) file_get_contents($manifestPath))->toBe($beforeManifest)
        ->and(file_exists($basePath.'/storage/app/core-panel/published'))->toBeFalse();
});

it('adopts legacy tenancy publishes into the manifest during force updates', function (): void {
    $basePath = makeTenancyUpdateBasePath('legacy-adopt');
    $target = $basePath.'/resources/js/pages/Admin/Users/Index.vue';
    $source = __DIR__.'/../../resources/js/pages/Admin/Users/Index.vue';

    mkdir(dirname($target), 0777, true);
    file_put_contents($target, "<template>\n    <div>legacy tenancy users</div>\n</template>\n");

    $this->artisan('core-panel:tenancy:update', [
        '--force' => true,
        '--base-path' => $basePath,
    ])->assertExitCode(0);

    $manifest = readTenancyPublishManifest($basePath);
    $backups = glob($basePath.'/.core-panel-backups/*/resources/js/pages/Admin/Users/Index.vue');

    expect(file_get_contents($target))->toBe(file_get_contents($source))
        ->and($manifest['files'][$target] ?? null)->toBeArray()
        ->and($manifest['files'][$target]['tag'] ?? null)->toBe('core-panel-tenancy-ui')
        ->and($backups)->not->toBeFalse()
        ->and($backups)->not->toBeEmpty()
        ->and(file_get_contents($backups[0]))->toContain('legacy tenancy users');
});

it('merges upstream changes into managed tenancy publishes with local modifications during updates', function (): void {
    $basePath = makeTenancyUpdateBasePath('managed-published-merge');
    $sourcePath = __DIR__.'/../../resources/js/pages/Admin/Users/Index.vue';
    $originalSourceContents = (string) file_get_contents($sourcePath);

    try {
        app(CorePanelPublisher::class)->publishForProvider(
            CorePanelTenancyServiceProvider::class,
            ['core-panel-tenancy-ui'],
            false,
            false,
            $basePath,
        );

        $target = $basePath.'/resources/js/pages/Admin/Users/Index.vue';
        $targetContents = (string) file_get_contents($target);

        file_put_contents(
            $target,
            str_replace(
                "import UserTenantsTab from '@/components/Users/UserTenantsTab.vue'\n",
                "import UserTenantsTab from '@/components/Users/UserTenantsTab.vue'\nconst localHostCustomization = true\n",
                $targetContents,
            ),
        );

        file_put_contents(
            $sourcePath,
            str_replace(
                "const canManageTenants = computed(() => page.props.tenancy?.isCentral === true)\n",
                "const canManageTenants = computed(() => page.props.tenancy?.isCentral === true)\nconst upstreamPackageChange = true\n",
                $originalSourceContents,
            ),
        );

        $this->artisan('core-panel:tenancy:update', [
            '--base-path' => $basePath,
        ])->assertExitCode(0);

        $updatedTargetContents = (string) file_get_contents($target);
        $manifest = readTenancyPublishManifest($basePath);

        expect($updatedTargetContents)
            ->toContain('const localHostCustomization = true')
            ->and($updatedTargetContents)->toContain('const upstreamPackageChange = true')
            ->and($manifest['files'][$target]['snapshot'] ?? null)->toBeString();
    } finally {
        file_put_contents($sourcePath, $originalSourceContents);
    }
});

it('leaves core vendor-first assets unmanaged when updating tenancy user overrides directly', function (): void {
    $basePath = makeTenancyUpdateBasePath('core-types-sync');
    $tenantUsersTarget = $basePath.'/resources/js/pages/Admin/Users/Index.vue';
    $tenantUsersSource = __DIR__.'/../../resources/js/pages/Admin/Users/Index.vue';
    $coreDataTableTarget = $basePath.'/resources/js/components/TableBuilder/DataTable.vue';
    $coreUseDataTableTarget = $basePath.'/resources/js/components/TableBuilder/useDataTable.ts';
    $coreTypesTarget = $basePath.'/resources/js/types/core-panel.ts';

    mkdir(dirname($tenantUsersTarget), 0777, true);
    mkdir(dirname($coreDataTableTarget), 0777, true);
    mkdir(dirname($coreTypesTarget), 0777, true);
    file_put_contents($tenantUsersTarget, "<template>\n    <div>legacy tenancy users</div>\n</template>\n");
    file_put_contents($coreDataTableTarget, "<script setup lang=\"ts\">\nconst legacyTable = true\n</script>\n");
    file_put_contents($coreUseDataTableTarget, "export function useDataTable(schema) {\n    return { rows: schema.rows }\n}\n");
    file_put_contents($coreTypesTarget, "export type UserRecord = {\n    id: string\n}\n");

    $this->artisan('core-panel:tenancy:update', [
        '--force' => true,
        '--base-path' => $basePath,
    ])->assertExitCode(0);

    $publishedManifest = json_decode(
        (string) file_get_contents($basePath.'/storage/app/core-panel/published.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $dataTableBackups = glob($basePath.'/.core-panel-backups/*/resources/js/components/TableBuilder/DataTable.vue');
    $useDataTableBackups = glob($basePath.'/.core-panel-backups/*/resources/js/components/TableBuilder/useDataTable.ts');
    $typeBackups = glob($basePath.'/.core-panel-backups/*/resources/js/types/core-panel.ts');

    expect(file_get_contents($tenantUsersTarget))->toBe(file_get_contents($tenantUsersSource))
        ->and(file_get_contents($coreDataTableTarget))->toContain('const legacyTable = true')
        ->and(file_get_contents($coreUseDataTableTarget))->toContain('return { rows: schema.rows }')
        ->and(file_get_contents($coreTypesTarget))->toContain('id: string')
        ->and($publishedManifest['files'][$coreDataTableTarget] ?? null)->toBeNull()
        ->and($publishedManifest['files'][$coreUseDataTableTarget] ?? null)->toBeNull()
        ->and($publishedManifest['files'][$coreTypesTarget] ?? null)->toBeNull()
        ->and($dataTableBackups)->not->toBeFalse()
        ->and($dataTableBackups)->toBeEmpty()
        ->and($useDataTableBackups)->not->toBeFalse()
        ->and($useDataTableBackups)->toBeEmpty()
        ->and($typeBackups)->not->toBeFalse()
        ->and($typeBackups)->toBeEmpty();
});

it('reports legacy tenancy publishes as conflicts without force', function (): void {
    $basePath = makeTenancyUpdateBasePath('legacy-conflict');
    $target = $basePath.'/resources/js/pages/Admin/Users/Index.vue';

    mkdir(dirname($target), 0777, true);
    file_put_contents($target, "<template>\n    <div>legacy tenancy users</div>\n</template>\n");

    $this->artisan('core-panel:tenancy:update', [
        '--base-path' => $basePath,
    ])->assertExitCode(1);

    expect(file_get_contents($target))->toContain('legacy tenancy users')
        ->and(file_exists($basePath.'/storage/app/core-panel/published.json'))->toBeTrue();
});

it('publishes the impersonation token migration during tenancy addon updates when it was not previously published', function (): void {
    $basePath = makeTenancyUpdateBasePath('missing-impersonation-migration');
    $target = $basePath.'/database/migrations/tenancy/2026_01_01_000024_create_tenant_user_impersonation_tokens_table.php';
    $source = tenancyMigrationStubPath('2026_01_01_000024_create_tenant_user_impersonation_tokens_table.php', 'tenancy');

    mkdir($basePath.'/storage/app/core-panel', 0777, true);
    file_put_contents($basePath.'/storage/app/core-panel/published.json', json_encode([
        'files' => [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    $this->artisan('core-panel:tenancy:update', [
        '--base-path' => $basePath,
    ])->assertExitCode(0);

    $manifest = readTenancyPublishManifest($basePath);

    expect(file_get_contents($target))->toBe(file_get_contents($source))
        ->and($manifest['files'][$target] ?? null)->toBeArray()
        ->and($manifest['files'][$target]['tag'] ?? null)->toBe('core-panel-tenancy-migrations');
});

it('does not duplicate the impersonation token migration when the basename already exists in tenancy migrations', function (): void {
    $basePath = makeTenancyUpdateBasePath('existing-impersonation-migration-basename');
    $target = $basePath.'/database/migrations/tenancy/2026_01_01_000024_create_tenant_user_impersonation_tokens_table.php';
    $existingTenancyMigration = $basePath.'/database/migrations/tenancy/2026_01_01_000024_create_tenant_user_impersonation_tokens_table.php';
    $source = tenancyMigrationStubPath('2026_01_01_000024_create_tenant_user_impersonation_tokens_table.php', 'tenancy');

    mkdir(dirname($existingTenancyMigration), 0777, true);
    mkdir($basePath.'/storage/app/core-panel', 0777, true);
    file_put_contents($existingTenancyMigration, (string) file_get_contents($source));
    file_put_contents($basePath.'/storage/app/core-panel/published.json', json_encode([
        'files' => [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    $this->artisan('core-panel:tenancy:update', [
        '--base-path' => $basePath,
    ])->assertExitCode(0);

    $manifest = readTenancyPublishManifest($basePath);

    expect(file_exists($target))->toBeTrue()
        ->and(file_get_contents($target))->toBe(file_get_contents($source))
        ->and($manifest['files'][$target] ?? null)->toBeArray()
        ->and($manifest['files'][$target]['tag'] ?? null)->toBe('core-panel-tenancy-migrations');
});

it('does not recreate removed tenancy translation overrides during updates', function (): void {
    $basePath = makeTenancyUpdateBasePath('removed-tenancy-translation-override');
    $target = $basePath.'/lang/vendor/core-panel-tenancy/de/page-tenants.php';

    app(CorePanelPublisher::class)->publishForProvider(
        CorePanelTenancyServiceProvider::class,
        ['core-panel-tenancy-lang-vendor'],
        false,
        false,
        $basePath,
    );

    unlink($target);

    $this->artisan('core-panel:tenancy:update', [
        '--base-path' => $basePath,
    ])->assertExitCode(0);

    $manifest = readTenancyPublishManifest($basePath);

    expect(file_exists($target))->toBeFalse()
        ->and($manifest['files'][$target] ?? null)->toBeArray();
});

it('refreshes tenancy publish tags through the addon provider for in-place updates', function (): void {
    $command = file_get_contents(__DIR__.'/../../src/Console/UpdateTenancyCommand.php');

    expect($command)->toContain('CorePanelTenancyServiceProvider::class')
        ->and($command)->toContain('adoptUnmanagedExisting: true')
        ->and($command)->toContain('managedMissingPaths: $this->resolveRequiredUpdatePaths($basePath)')
        ->and($command)->toContain("'database/migrations/tenancy/2026_01_01_000024_create_tenant_user_impersonation_tokens_table.php'")
        ->and($command)->not->toContain('publishProviderTag(CorePanelTenancyServiceProvider::class, $tag, $force);')
        ->and($command)->toContain('if ($basePath === null) {')
        ->and($command)->toContain('ensureTenancyProviderRegistered($basePath);')
        ->and($command)->toContain('handleInertiaRequestsTenancyMerger->merge($basePath);')
        ->and($command)->toContain('App\\\\Providers\\\\TenancyServiceProvider::class')
        ->and($command)->toContain('use App\\\\Providers\\\\TenancyServiceProvider;')
        ->and($command)->toContain("TenancyServiceProvider::class");
});

it('normalizes bootstrap providers to the imported tenancy provider style during updates', function (): void {
    $basePath = makeTenancyUpdateBasePath('normalize-tenancy-provider-import');

    mkdir($basePath.'/bootstrap', 0777, true);
    file_put_contents($basePath.'/bootstrap/providers.php', <<<'PHP'
<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    App\Providers\TenancyServiceProvider::class,
    FortifyServiceProvider::class,
];
PHP);

    $this->artisan('core-panel:tenancy:update', [
        '--base-path' => $basePath,
    ])->assertExitCode(0);

    $providers = file_get_contents($basePath.'/bootstrap/providers.php');

    expect($providers)->toContain('use App\\Providers\\TenancyServiceProvider;')
        ->and($providers)->toContain('TenancyServiceProvider::class,')
        ->and($providers)->not->toContain("    App\\Providers\\TenancyServiceProvider::class,");
});

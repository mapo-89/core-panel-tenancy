<?php

declare(strict_types=1);

use CorePanel\Contracts\SettingsLogoUrlGenerator;
use CorePanelTenancy\Console\InstallTenancyCommand;
use CorePanelTenancy\Console\UpdateTenancyCommand;
use CorePanelTenancy\CorePanelTenancyServiceProvider;
use CorePanelTenancy\Support\Media\TenantAwareUrlGenerator;
use CorePanelTenancy\Support\Settings\TenantAwareSettingsLogoUrlGenerator;
use CorePanelTenancy\Support\Wayfinder\WayfinderRouteUrlNormalizer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;

it('publishes the stancl tenancy foundation for host applications', function (): void {
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
    $tenantUsersOverride = file_get_contents(__DIR__.'/../../stubs/resources/js/pages/Admin/Users/Index.vue');
    $tenantEditPage = file_get_contents(__DIR__.'/../../stubs/resources/js/pages/Admin/Tenants/Edit.vue');
    $tenantTab = file_get_contents(__DIR__.'/../../stubs/resources/js/components/Users/UserTenantsTab.vue');
    $tenantForm = file_get_contents(__DIR__.'/../../stubs/resources/js/pages/Admin/Tenants/components/TenantForm.vue');
    $coreAdminTheme = file_get_contents(__DIR__.'/../../../core-panel/stubs/resources/css/theme/_admin.css');
    $appServiceProviderStub = file_get_contents(__DIR__.'/../../stubs/app/Providers/AppServiceProvider.php');
    $addonTypesAwareUsersIndex = file_get_contents(__DIR__.'/../../stubs/resources/js/pages/Admin/Users/Index.vue');
    $tenancyServiceProviderStub = file_get_contents(__DIR__.'/../../stubs/app/Providers/TenancyServiceProvider.php');
    $appServiceProviderTenancyMerger = file_get_contents(__DIR__.'/../../src/Support/Install/AppServiceProviderTenancyMerger.php');
    $corePanelTypesTenancyMerger = file_get_contents(__DIR__.'/../../src/Support/Install/CorePanelTypesTenancyMerger.php');
    $appServiceProviderTenancyHook = file_get_contents(__DIR__.'/../../stubs/merge/app-service-provider.tenancy-hook.stub');
    $corePanelTenancyContextStub = file_get_contents(__DIR__.'/../../stubs/merge/core-panel-tenancy-context.stub');
    $sessionCookieMiddleware = file_get_contents(__DIR__.'/../../src/Http/Middleware/SetTenantAwareSessionCookie.php');
    $tenantsMigration = file_get_contents(__DIR__.'/../../stubs/database/migrations/2026_01_01_000001_create_tenants_table.php');
    $domainsMigration = file_get_contents(__DIR__.'/../../stubs/database/migrations/2026_01_01_000020_create_domains_table.php');
    $tenantUsersMigration = file_get_contents(__DIR__.'/../../stubs/database/migrations/tenant/0001_01_01_000000_create_users_table.php');
    $tenantSettingsMigration = file_get_contents(__DIR__.'/../../stubs/database/migrations/tenant/2026_01_01_000003_create_core_panel_settings_table.php');
    $tenantUserGroupsMigration = file_get_contents(__DIR__.'/../../stubs/database/migrations/tenant/2026_01_01_000019_create_user_groups_table.php');
    $tenantPermissionMigration = file_get_contents(__DIR__.'/../../stubs/database/migrations/tenant/2026_01_01_000014_create_permission_tables.php');
    $tenantOauthAuthCodesMigration = file_get_contents(__DIR__.'/../../stubs/database/migrations/tenant/2016_06_01_000001_create_oauth_auth_codes_table.php');
    $tenantOauthAccessTokensMigration = file_get_contents(__DIR__.'/../../stubs/database/migrations/tenant/2016_06_01_000002_create_oauth_access_tokens_table.php');
    $tenantOauthAccessTokensLastUsedMigration = file_get_contents(__DIR__.'/../../stubs/database/migrations/tenant/2026_01_01_000022_add_last_used_at_to_oauth_access_tokens_table.php');
    $tenantOauthClientsMigration = file_get_contents(__DIR__.'/../../stubs/database/migrations/tenant/2016_06_01_000004_create_oauth_clients_table.php');
    $tenantOauthDeviceCodesMigration = file_get_contents(__DIR__.'/../../stubs/database/migrations/tenant/2024_06_01_000001_create_oauth_device_codes_table.php');
    $tenantMediaMigration = file_get_contents(__DIR__.'/../../stubs/database/migrations/tenant/2019_01_01_000001_create_media_table.php');
    $tenantAwareUrlGenerator = file_get_contents(__DIR__.'/../../src/Support/Media/TenantAwareUrlGenerator.php');
    $tenantDatabaseSeeder = file_get_contents(__DIR__.'/../../stubs/database/seeders/Tenant/TenantDatabaseSeeder.php');
    $tenantRolePermissionSeeder = file_get_contents(__DIR__.'/../../stubs/database/seeders/Tenant/TenantRolePermissionSeeder.php');
    $upsertTenantSuperAdminAction = file_get_contents(__DIR__.'/../../src/Domains/Tenancy/Actions/UpsertTenantSuperAdminAction.php');
    $tenantSettingsController = file_get_contents(__DIR__.'/../../src/Http/Controllers/SettingsController.php');

    expect($installCommand)->toContain('core-panel-tenancy-core')
        ->and($installCommand)->toContain('core-panel-tenancy-config')
        ->and($installCommand)->toContain('core-panel-tenancy-migrations')
        ->and($installCommand)->toContain('AppServiceProviderTenancyMerger')
        ->and($installCommand)->toContain('CorePanelTypesTenancyMerger')
        ->and($installCommand)->toContain('appServiceProviderTenancyMerger->merge();')
        ->and($installCommand)->toContain('corePanelTypesTenancyMerger->merge();')
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
        ->and($provider)->toContain('SettingsLogoUrlGenerator::class')
        ->and($provider)->toContain('TenantAwareSettingsLogoUrlGenerator::class')
        ->and($provider)->toContain("Inertia::share('tenancy'")
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
        ->and($provider)->toContain("publishableTree(__DIR__.'/../stubs/database/migrations', database_path('migrations'))")
        ->and($provider)->toContain("publishableTree(__DIR__.'/../stubs/database/seeders', database_path('seeders'))")
        ->and($provider)->toContain("publishableTree(__DIR__.'/../stubs/lang', lang_path())")
        ->and($provider)->toContain("publishableTree(__DIR__.'/../resources/lang', \$this->app->langPath('vendor/core-panel-tenancy'))")
        ->and($provider)->toContain("stubs/resources/js/pages/Admin/Users/Index.vue' => resource_path('js/pages/Admin/Users/Index.vue')")
        ->and($appServiceProviderTenancyMerger)->toContain('stubs/app/Providers/AppServiceProvider.php')
        ->and($appServiceProviderTenancyMerger)->toContain('stubs/merge/app-service-provider.tenancy-hook.stub')
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
        ->and($storeTenantRequest)->toContain("'database_name' => ['nullable', 'string', 'max:255']")
        ->and($storeTenantRequest)->toContain("'super_admin_password' => ['required', 'confirmed', Password::defaults()]")
        ->and($updateTenantRequest)->toContain('shouldManageTenantSuperAdmin')
        ->and($upsertTenantSuperAdminAction)->toContain("Role::findOrCreate('super-admin', 'web');")
        ->and($tenancyConfig)->toContain("'tenant_model' => Tenant::class")
        ->and($tenancyConfig)->toContain('UniversalRoutes::class')
        ->and($tenancyConfig)->toContain("'asset_helper_tenancy' => false")
        ->and($tenancyConfig)->toContain("database_path('migrations/tenant')")
        ->and($tenancyConfig)->toContain("'--class' => 'Database\\\\Seeders\\\\Tenant\\\\TenantDatabaseSeeder'")
        ->and($tenantUsersOverride)->toContain("key: 'tenants'")
        ->and($tenantUsersOverride)->toContain("label: 'page-tenants.page_title'")
        ->and($tenantUsersOverride)->toContain("panelSurfaceVariant: 'card'")
        ->and($addonTypesAwareUsersIndex)->toContain('CorePanelTenancyContext')
        ->and($tenantUsersOverride)->toContain('const canManageTenants = computed(')
        ->and($tenantUsersOverride)->toContain('page.props.tenancy?.isCentral === true')
        ->and($tenantUsersOverride)->toContain("import UserTenantsTab from '@/components/Users/UserTenantsTab.vue'")
        ->and($tenantEditPage)->toContain("import TenantForm from '@/pages/Admin/Tenants/components/TenantForm.vue'")
        ->and($tenantTab)->toContain('destroy as destroyTenant')
        ->and($tenantTab)->toContain('dtApi as tenantDtApi')
        ->and($tenantTab)->toContain("from '@/routes/tenants'")
        ->and($tenantTab)->toContain('primary_domain')
        ->and($tenantTab)->toContain('database_name')
        ->and($tenantTab)->toContain('cp-datatable__action-button')
        ->and($tenantTab)->toContain('grid gap-3 px-[1.125rem] pt-[1.125rem] pb-1')
        ->and($coreAdminTheme)->toContain('.cp-datatable__action-button {')
        ->and($tenantForm)->toContain('index as tenantsIndex')
        ->and($tenantForm)->toContain('store as storeTenant')
        ->and($tenantForm)->toContain('update as updateTenant')
        ->and($tenantForm)->toContain("from '@/routes/tenants'")
        ->and($tenantForm)->toContain('super_admin_first_name')
        ->and($tenantForm)->toContain('database_name')
        ->and($tenantForm)->toContain('page-tenants.tenant_id_hint')
        ->and($tenantForm)->toContain('page-tenants.database_name_hint')
        ->and($tenantForm)->toContain("import TranslatedPassword from '@/components/TranslatedPassword.vue'")
        ->and($tenantForm)->toContain('<TranslatedPassword')
        ->and($tenantForm)->toContain(':min-length="8"')
        ->and($tenantForm)->toContain(':match-password="form.super_admin_password"')
        ->and($tenantForm)->not->toContain('const showPasswordRequirements = computed(() => {')
        ->and($sessionCookieMiddleware)->toContain("\$cookieName = \$defaultCookie.'_'.\$suffix;")
        ->and($sessionCookieMiddleware)->toContain("config()->set('session.cookie_base', \$defaultCookie);")
        ->and($sessionCookieMiddleware)->toContain("config()->set('session.cookie', \$cookieName);")
        ->and($sessionCookieMiddleware)->toContain("app('session')->setName(\$cookieName);")
        ->and($sessionCookieMiddleware)->toContain("app('session.store')->setName(\$cookieName);")
        ->and($sessionCookieMiddleware)->toContain("return 'central';")
        ->and($sessionCookieMiddleware)->toContain("preg_replace('/[^A-Za-z0-9]+/', '_', \$host)")
        ->and($centralRouteFile)->toContain("require base_path('routes/universal.php');")
        ->and($centralRouteFile)->toContain("if (file_exists(base_path('routes/web/tenants.php'))) {")
        ->and($centralRouteFile)->toContain("require base_path('routes/web/tenants.php');")
        ->and($tenantRouteFile)->toContain('InitializeTenancyByDomain::class')
        ->and($tenantRouteFile)->toContain('PreventAccessFromCentralDomains::class')
        ->and($tenantRouteFile)->toContain("Route::name('tenant.')->group(function () use")
        ->and($tenantRouteFile)->not->toContain("foreach (\$webRoutes['public'] as \$publicRouteFile)")
        ->and($tenantRouteFile)->not->toContain('routes/web/tenants.php')
        ->and($universalRouteFile)->not->toContain("\$loadUniversalWebRouteFile('auth.php');")
        ->and($universalRouteFile)->toContain("\$loadUniversalWebRouteFile('platform.php');")
        ->and($tenantRouteFile)->toContain("require base_path('routes/web/'.\$file);")
        ->and($tenantSettingsRouteFile)->toContain('use CorePanelTenancy\Http\Controllers\SettingsController;')
        ->and($tenantSettingsRouteFile)->toContain("Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');")
        ->and($tenantSettingsRouteFile)->toContain("Route::put('/settings/styles', [SettingsController::class, 'updateStyles'])->name('settings.styles');")
        ->and($tenantSettingsRouteFile)->toContain("Route::put('/settings/{group}', [SettingsController::class, 'update'])->name('settings.update');")
        ->and($tenantCentralRouteFile)->toContain("Route::get('/tenants/dt', 'dtApi')->name('tenants.dtApi');")
        ->and($tenantCentralRouteFile)->toContain("Route::get('/tenants/{tenant}/edit', 'edit')->name('tenants.edit');")
        ->and($tenantsMigration)->toContain("\$table->string('id')->primary()")
        ->and($tenantsMigration)->toContain('$table->timestamps();')
        ->and($tenantsMigration)->toContain("\$table->json('data')->nullable()")
        ->and($tenantsMigration)->not->toContain("\$table->string('name');")
        ->and($tenantsMigration)->not->toContain("\$table->string('tenancy_db_name')->nullable();")
        ->and($domainsMigration)->toContain("\$table->string('domain', 255)->unique()")
        ->and($domainsMigration)->toContain("\$table->string('tenant_id');")
        ->and($tenantUsersMigration)->toContain("\$table->uuid('id')->primary();")
        ->and($tenantUsersMigration)->toContain("\$table->string('first_name');")
        ->and($tenantUsersMigration)->toContain("\$table->string('last_name');")
        ->and($tenantUsersMigration)->toContain("\$table->string('locale', 12)->nullable();")
        ->and($tenantUsersMigration)->toContain("\$table->string('user_id')->nullable()->index();")
        ->and($tenantUsersMigration)->toContain("\$table->boolean('requires_password_setup')->default(false);")
        ->and($tenantUsersMigration)->toContain('$table->softDeletes();')
        ->and($tenantUserGroupsMigration)->toContain("\$table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();")
        ->and($tenantPermissionMigration)->toContain("\$table->string('core_panel_group')->nullable();")
        ->and($tenantPermissionMigration)->toContain("\$table->string(\$columnNames['model_morph_key']);")
        ->and($tenantOauthAuthCodesMigration)->toContain("\$table->uuid('user_id')->index();")
        ->and($tenantOauthAccessTokensMigration)->toContain("\$table->uuid('user_id')->nullable()->index();")
        ->and($tenantOauthAccessTokensMigration)->toContain("\$table->timestamp('last_used_at')->nullable();")
        ->and($tenantOauthAccessTokensLastUsedMigration)->toContain("Schema::table('oauth_access_tokens'")
        ->and($tenantOauthAccessTokensLastUsedMigration)->toContain("\$table->timestamp('last_used_at')->nullable()->after('updated_at');")
        ->and($tenantOauthClientsMigration)->toContain("\$table->nullableUuidMorphs('owner');")
        ->and($tenantOauthDeviceCodesMigration)->toContain("\$table->uuid('user_id')->nullable()->index();")
        ->and(file_exists(__DIR__.'/../../stubs/database/migrations/tenant/2019_12_14_000001_create_personal_access_tokens_table.php'))->toBeFalse()
        ->and($tenantSettingsMigration)->toContain("Schema::create('settings'")
        ->and(file_exists(__DIR__.'/../../stubs/database/migrations/tenant/2026_01_01_000021_create_authentication_logs_table.php'))->toBeTrue()
        ->and($tenantMediaMigration)->toContain("\$table->string('model_id');")
        ->and(file_exists(__DIR__.'/../../stubs/database/migrations/tenant/2026_01_01_000004_add_core_panel_fields_to_users_table.php'))->toBeFalse()
        ->and(file_exists(__DIR__.'/../../stubs/database/migrations/tenant/2026_01_01_000016_add_core_panel_metadata_to_roles_table.php'))->toBeFalse()
        ->and(file_exists(__DIR__.'/../../stubs/database/migrations/tenant/2026_01_01_000020_add_requires_password_setup_to_users_table.php'))->toBeFalse()
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

it('registers publish tags for the direct stancl addon resources', function (): void {
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
        ->and(ServiceProvider::pathsToPublish(
            CorePanelTenancyServiceProvider::class,
            'core-panel-tenancy-lang',
        ))->not->toBeEmpty();
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
        url: '//core-panel-app.test/profile',
    },
};

// @route '//core-panel-app.test/profile'
TS,
    );

    config()->set('app.url', 'https://core-panel-app.test');
    config()->set('tenancy.central_domains', ['core-panel-app.test']);

    app(WayfinderRouteUrlNormalizer::class)->normalize($basePath);

    $contents = file_get_contents($routesPath.'/index.ts');

    expect($contents)->toContain("url: '/profile'")
        ->and($contents)->toContain("// @route '/profile'")
        ->and($contents)->not->toContain('//core-panel-app.test/profile');
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
        '--base-path' => sys_get_temp_dir().'/core-panel-tenancy-update-'.bin2hex(random_bytes(4)),
    ]);

    $output = Artisan::output();

    expect($output)->toContain('core-panel-tenancy-core')
        ->and($output)->not->toContain('core-panel-components')
        ->and($output)->not->toContain('core-panel-theme')
        ->and($output)->not->toContain('core-panel-lang')
        ->and($output)->not->toContain('core-panel-views');
});

it('refreshes tenancy publish tags through the addon provider for in-place updates', function (): void {
    $command = file_get_contents(__DIR__.'/../../src/Console/UpdateTenancyCommand.php');

    expect($command)->toContain('CorePanelTenancyServiceProvider::class')
        ->and($command)->toContain('publishProviderTag(CorePanelTenancyServiceProvider::class, $tag, $force);')
        ->and($command)->toContain('if ($basePath === null) {')
        ->and($command)->toContain('ensureTenancyProviderRegistered($basePath);')
        ->and($command)->toContain('App\\\\Providers\\\\TenancyServiceProvider::class');
});

<?php

declare(strict_types=1);

use CorePanelTenancy\Http\Controllers\LeaveTenantImpersonationController;
use CorePanelTenancy\Http\Controllers\TenantImpersonationController;
use CorePanelTenancy\Http\Middleware\RedirectImpersonatingTenantGuest;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Register the tenant application routes here. These routes are loaded by
| App\Providers\TenancyServiceProvider after stancl/tenancy is installed.
|
*/

$tenantWebMiddleware = [
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
];
$webRoutes = require base_path('routes/web/routes.php');
$packageWebRoutesRoot = base_path('vendor/mapo-89/core-panel/routes/web');
$packageTenancyRoutesRoot = base_path('vendor/mapo-89/core-panel-tenancy/routes/web');
$loadTenantWebRouteFile = static function (string $file) use ($packageWebRoutesRoot): void {
    $hostRoutePath = base_path('routes/web/'.$file);

    if (is_file($hostRoutePath)) {
        require $hostRoutePath;

        return;
    }

    $packageRoutePath = $packageWebRoutesRoot.'/'.$file;

    if (is_file($packageRoutePath)) {
        require $packageRoutePath;

        return;
    }

    throw new RuntimeException(sprintf(
        'Unable to locate CorePanel tenant web route fragment [%s].',
        $file,
    ));
};
$corePanelRouteMiddleware = array_values(array_filter(
    (array) config('core-panel.middleware', ['web', 'auth']),
    static fn (string $middleware): bool => $middleware !== 'web',
));

Route::middleware($tenantWebMiddleware)->group(function () use ($corePanelRouteMiddleware, $loadTenantWebRouteFile, $webRoutes): void {
    Route::name('tenant.')->group(function () use ($corePanelRouteMiddleware, $loadTenantWebRouteFile, $webRoutes): void {
        Route::redirect('/', config('core-panel.route_prefix', 'admin'));
        Route::get('/impersonate/{token}', TenantImpersonationController::class)->name('impersonate');
        Route::get('/leave-impersonation', LeaveTenantImpersonationController::class)->name('leave-impersonation');

        foreach ($webRoutes['public'] as $publicRouteFile) {
            $loadTenantWebRouteFile($publicRouteFile);
        }

        Route::middleware([
            RedirectImpersonatingTenantGuest::class,
            ...$corePanelRouteMiddleware,
            'core-panel.verified',
        ])->group(function () use ($loadTenantWebRouteFile, $webRoutes): void {
            foreach ($webRoutes['authenticated_without_permission'] as $authenticatedRouteFile) {
                $loadTenantWebRouteFile($authenticatedRouteFile);
            }

            Route::middleware('check.permission')->group(function () use ($loadTenantWebRouteFile, $webRoutes, $packageTenancyRoutesRoot): void {
                foreach ($webRoutes['permission_protected'] as $permissionProtectedRouteFile) {
                    $loadTenantWebRouteFile($permissionProtectedRouteFile);
                }

                $hostTenantRoutesPath = base_path('routes/web/tenants.php');

                if (is_file($hostTenantRoutesPath)) {
                    require $hostTenantRoutesPath;

                    return;
                }

                $packageTenantRoutesPath = $packageTenancyRoutesRoot.'/tenants.php';

                if (is_file($packageTenantRoutesPath)) {
                    require $packageTenantRoutesPath;
                }
            });
        });
    });
});

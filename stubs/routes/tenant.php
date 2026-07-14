<?php

declare(strict_types=1);

use CorePanelTenancy\Http\Controllers\LeaveTenantImpersonationController;
use CorePanelTenancy\Http\Controllers\TenantImpersonationController;
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
$loadTenantWebRouteFile = static function (string $file): void {
    require base_path('routes/web/'.$file);
};
$corePanelRouteMiddleware = array_values(array_filter(
    (array) config('core-panel.middleware', ['web', 'auth']),
    static fn (string $middleware): bool => $middleware !== 'web',
));

Route::middleware($tenantWebMiddleware)->group(function () use ($corePanelRouteMiddleware, $loadTenantWebRouteFile, $webRoutes): void {
    Route::name('tenant.')->group(function () use ($corePanelRouteMiddleware, $loadTenantWebRouteFile, $webRoutes): void {
        Route::redirect('/', config('core-panel.route_prefix', 'admin'));
        Route::get('/impersonate/{token}', TenantImpersonationController::class)->name('impersonate');

        foreach ($webRoutes['public'] as $publicRouteFile) {
            $loadTenantWebRouteFile($publicRouteFile);
        }

        Route::middleware([...$corePanelRouteMiddleware, 'core-panel.verified'])->group(function () use ($loadTenantWebRouteFile, $webRoutes): void {
            Route::get('/leave-impersonation', LeaveTenantImpersonationController::class)->name('leave-impersonation');

            foreach ($webRoutes['authenticated_without_permission'] as $authenticatedRouteFile) {
                $loadTenantWebRouteFile($authenticatedRouteFile);
            }

            Route::middleware('check.permission')->group(function () use ($loadTenantWebRouteFile, $webRoutes): void {
                foreach ($webRoutes['permission_protected'] as $permissionProtectedRouteFile) {
                    $loadTenantWebRouteFile($permissionProtectedRouteFile);
                }

                if (file_exists(base_path('routes/web/tenants.php'))) {
                    require base_path('routes/web/tenants.php');
                }
            });
        });
    });
});

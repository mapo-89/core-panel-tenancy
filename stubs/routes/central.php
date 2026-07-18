<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

$hostTenantRoutesPath = base_path('routes/web/tenants.php');
$packageTenantRoutesPath = base_path('vendor/mapo-89/core-panel-tenancy/routes/web/tenants.php');

$loadTenantCentralRoutes = static function () use ($hostTenantRoutesPath, $packageTenantRoutesPath): void {
    if (is_file($hostTenantRoutesPath)) {
        require $hostTenantRoutesPath;

        return;
    }

    if (is_file($packageTenantRoutesPath)) {
        require $packageTenantRoutesPath;
    }
};

require base_path('routes/universal.php');

$centralDomains = config('tenancy.central_domains', []);

if (! is_array($centralDomains) || $centralDomains === []) {
    require base_path('routes/web.php');

    $loadTenantCentralRoutes();

    return;
}

foreach ($centralDomains as $domain) {
    Route::domain($domain)->group(function () use ($loadTenantCentralRoutes): void {
        require base_path('routes/web.php');

        $loadTenantCentralRoutes();
    });
}

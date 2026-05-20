<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

require base_path('routes/universal.php');

$centralDomains = config('tenancy.central_domains', []);

if (! is_array($centralDomains) || $centralDomains === []) {
    require base_path('routes/web.php');

    if (file_exists(base_path('routes/web/tenants.php'))) {
        require base_path('routes/web/tenants.php');
    }

    return;
}

foreach ($centralDomains as $domain) {
    Route::domain($domain)->group(function (): void {
        require base_path('routes/web.php');

        if (file_exists(base_path('routes/web/tenants.php'))) {
            require base_path('routes/web/tenants.php');
        }
    });
}

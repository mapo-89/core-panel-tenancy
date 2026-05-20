<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;

$loadUniversalWebRouteFile = static function (string $file): void {
    require base_path('routes/web/'.$file);
};

Route::middleware([
    'web',
    'universal',
    InitializeTenancyByDomain::class,
])->group(function () use ($loadUniversalWebRouteFile): void {
    $loadUniversalWebRouteFile('platform.php');
    $loadUniversalWebRouteFile('forms.php');
});

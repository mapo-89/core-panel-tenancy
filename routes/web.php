<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::middleware([
    ...config('core-panel.middleware', ['web', 'auth']),
    'check.permission',
])
    ->prefix(config('core-panel.route_prefix', 'admin'))
    ->name('core-panel.')
    ->group(function (): void {
        require __DIR__.'/web/tenants.php';
    });

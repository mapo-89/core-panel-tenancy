<?php

declare(strict_types=1);

use CorePanelTenancy\Http\Controllers\CentralTenantImpersonationController;
use CorePanelTenancy\Http\Controllers\TenantController;
use Illuminate\Support\Facades\Route;

Route::post('/tenants/{tenant}/impersonate', CentralTenantImpersonationController::class)
    ->name('tenants.impersonate');

Route::controller(TenantController::class)
    ->group(function (): void {
        Route::get('/tenants', 'index')->name('tenants.index');
        Route::get('/tenants/dt', 'dtApi')->name('tenants.dtApi');
        Route::get('/tenants/{tenant}/edit', 'edit')->name('tenants.edit');
        Route::get('/tenants/{tenant}/data', 'data')->name('tenants.data');
        Route::post('/tenants', 'store')->name('tenants.store');
        Route::put('/tenants/{tenant}', 'update')->name('tenants.update');
        Route::delete('/tenants/{tenant}', 'destroy')->name('tenants.destroy');
    });

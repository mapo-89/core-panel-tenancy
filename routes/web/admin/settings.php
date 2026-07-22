<?php

declare(strict_types=1);

use CorePanel\Http\Controllers\OidcLogoController;
use CorePanel\Http\Controllers\SettingsLogoController;
use CorePanelTenancy\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
Route::post('/settings/logo', [SettingsLogoController::class, 'store'])->name('settings.logo.store');
Route::post('/settings/oidc-logo', [OidcLogoController::class, 'store'])->name('settings.oidc-logo.store');
Route::put('/settings/styles', [SettingsController::class, 'updateStyles'])->name('settings.styles');
Route::put('/settings/{group}', [SettingsController::class, 'update'])->name('settings.update');
Route::delete('/settings/logo', [SettingsLogoController::class, 'destroy'])->name('settings.logo.destroy');

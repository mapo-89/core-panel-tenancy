<?php

declare(strict_types=1);

use CorePanel\Models\Setting;
use CorePanel\Support\Settings\SettingsLogoManager;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

it('uses the tenant asset route for logo urls inside tenant context', function (): void {
    config()->set('core-panel.files.disk', 'public');
    config()->set('core-panel.files.logo.disk', 'public');
    Storage::fake('public');
    $this->migrateScaffoldDatabase();

    URL::forceRootUrl('https://tenant.example.test');
    URL::forceScheme('https');

    Route::get('/tenancy/assets/{path?}', static fn () => null)
        ->where('path', '(.*)')
        ->name('stancl.tenancy.asset');
    app('router')->getRoutes()->refreshNameLookups();

    $record = new Setting;
    $record->forceFill([
        'group' => 'general',
        'is_localized' => false,
        'is_public' => false,
        'key' => 'app_logo_path',
        'type' => 'json',
        'value_json' => [
            'path' => 'branding/logo.png',
        ],
    ]);
    $record->save();

    $tenancy = new class
    {
        public bool $initialized = true;
    };
    $tenancy->initialized = true;

    app()->instance('Stancl\\Tenancy\\Tenancy', $tenancy);

    expect(app(SettingsLogoManager::class)->currentUrl())
        ->toBe('https://tenant.example.test/tenancy/assets/branding/logo.png');
});
